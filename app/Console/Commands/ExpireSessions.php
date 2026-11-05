<?php

namespace App\Console\Commands;

use App\Models\Session;
use App\Services\RouterConnectionService;
use App\Services\SiteAgentCommandService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExpireSessions extends Command
{
    protected $signature = 'sessions:expire';

    protected $description = 'Expire elapsed sessions and disconnect active MikroTik users';

    public function handle(
        RouterConnectionService $routerService,
        SiteAgentCommandService $agentCommands,
    ): int {
    $expiredCount = 0;
    $queuedCount = 0;
        $retryCount = 0;

        Session::withoutTenancy()
            ->with([
                'router' => fn ($query) => $query->withoutTenancy(),
                'voucher' => fn ($query) => $query->withoutTenancy(),
                'router.assignedSite' => fn ($query) => $query->withoutTenancy(),
                'router.assignedSite.agent',
            ])
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($sessions) use ($routerService, $agentCommands, &$expiredCount, &$queuedCount, &$retryCount): void {
                foreach ($sessions as $session) {
                    $siteAgent = $session->router?->assignedSite?->agent;
                    if ($session->router?->site_id && $session->voucher
                        && $siteAgent && ! $siteAgent->revoked_at) {
                        try {
                            $agentCommands->expire($session->router, $session, $session->voucher);
                            $queuedCount++;
                        } catch (Throwable $exception) {
                            $retryCount++;
                            Log::error('Unable to queue site-agent expiry command.', [
                                'session_id' => $session->id,
                                'router_id' => $session->router_id,
                                'exception' => $exception,
                            ]);
                        }

                        continue;
                    }

                    if (config('site_agent.required') && $session->router && $session->voucher) {
                        $retryCount++;
                        Log::error('Expired session has no paired site agent; it remains active until router access is revoked.', [
                            'session_id' => $session->id,
                            'router_id' => $session->router_id,
                        ]);

                        continue;
                    }

                    if ($session->router && $session->voucher) {
                        try {
                            $disconnected = $routerService->disconnectUser(
                                $session->router,
                                $session->voucher->code
                            );

                            if (! $disconnected) {
                                Log::info('Expired voucher had no active MikroTik hotspot session.', [
                                    'session_id' => $session->id,
                                    'router_id' => $session->router_id,
                                    'voucher_id' => $session->voucher_id,
                                ]);
                            }
                        } catch (Throwable $exception) {
                            $retryCount++;
                            Log::error('Unable to disconnect expired MikroTik session; it will be retried.', [
                                'session_id' => $session->id,
                                'router_id' => $session->router_id,
                                'voucher_id' => $session->voucher_id,
                                'exception' => $exception,
                            ]);

                            continue;
                        }
                    }

                    $session->update(['status' => 'expired']);
                    $expiredCount++;
                }
            });

        $this->info("Expired {$expiredCount} session(s); queued {$queuedCount} site-agent command(s); {$retryCount} operation(s) failed.");

        return $retryCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
