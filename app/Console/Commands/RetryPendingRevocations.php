<?php

namespace App\Console\Commands;

use App\Models\Session;
use App\Models\Tenant;
use App\Services\RadiusAccountingService;
use App\Services\RouterConnectionService;
use App\Services\SiteAgentCommandService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RetryPendingRevocations extends Command
{
    protected $signature = 'sessions:retry-revocations';

    protected $description = 'Retry router revocations that are waiting for a site agent';

    public function handle(
        SiteAgentCommandService $commands,
        RouterConnectionService $routerService,
        TenantContext $context,
        RadiusAccountingService $radiusAccounting,
    ): int {
        $queued = 0;
        $failed = 0;

        Session::withoutTenancy()
            ->with([
                'router' => fn ($query) => $query->withoutTenancy(),
                'voucher' => fn ($query) => $query->withoutTenancy(),
                'router.assignedSite.agent',
            ])
            ->where('pending_revocation', true)
            ->orderBy('id')
            ->chunkById(100, function ($sessions) use ($commands, $context, $radiusAccounting, &$queued, &$failed): void {
                foreach ($sessions as $session) {
                    $router = $session->router;
                    $voucher = $session->voucher;
                    $tenant = Tenant::withoutGlobalScopes()->find($session->tenant_id);

                    if (! $router || ! $voucher || ! $tenant) {
                        $failed++;
                        Log::error('Pending session revocation is missing its router, voucher, or tenant.', [
                            'session_id' => $session->id,
                        ]);

                        continue;
                    }

                    try {
                        $finalStatus = $session->pending_revocation_status === 'disconnected'
                            ? 'disconnected'
                            : 'expired';

                        if ($router->access_backend === 'radius') {
                            if ($radiusAccounting->requestDisconnect(
                                $session,
                                "revocation:{$session->id}:{$finalStatus}",
                            )) {
                                $queued++;
                            } else {
                                $failed++;
                            }

                            continue;
                        }

                        $agent = $router->assignedSite?->agent;
                        $agentOnline = $agent
                            && ! $agent->revoked_at
                            && $agent->last_seen_at?->greaterThanOrEqualTo(now()->subMinutes(2));

                        if ($agentOnline) {
                            $context->runAs($tenant, fn () => $commands->expire(
                                $router,
                                $session,
                                $voucher,
                                $finalStatus,
                            ));
                        } elseif (config('site_agent.required')) {
                            throw new RuntimeException('The required site agent is unavailable.');
                        } else {
                            $context->runAs($tenant, fn () => $routerService->disconnectUser($router, $voucher->code));
                            $session->update([
                                'status' => $finalStatus,
                                'pending_revocation' => false,
                                'pending_revocation_status' => null,
                            ]);
                        }

                        $queued++;
                    } catch (Throwable $exception) {
                        $failed++;
                        Log::warning('Unable to queue pending session revocation.', [
                            'session_id' => $session->id,
                            'router_id' => $router->id,
                            'exception' => $exception::class,
                        ]);
                    }
                }
            });

        $this->info("Queued {$queued} pending revocation(s); {$failed} could not be queued.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
