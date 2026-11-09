<?php

namespace App\Services;

use App\Models\Router;
use App\Models\Session;
use App\Models\SiteAgent;
use App\Models\SiteAgentCommand;
use App\Models\Voucher;
use App\Services\Policy\Policy;

class SiteAgentCommandService
{
    public function provision(Router $router, Session $session, Voucher $voucher): SiteAgentCommand
    {
        $agent = $this->agentFor($router);
        $package = $session->package;

        return SiteAgentCommand::query()->updateOrCreate(
            ['session_id' => $session->id, 'operation' => 'provision_user'],
            [
                'site_agent_id' => $agent->id,
                'tenant_id' => $router->tenant_id,
                'router_id' => $router->id,
                'payload' => [
                    'username' => $voucher->code,
                    'password' => $voucher->code,
                    'profile' => $package?->radius_profile_name ?: 'default',
                    'limit_uptime' => $this->routerUptimeLimit($session),
                    'rate_limit' => (int) $package?->speed_limit_mbps > 0
                        ? ((int) $package->speed_limit_mbps).'M/'.((int) $package->speed_limit_mbps).'M'
                        : null,
                    'shared_users' => max(1, (int) ($package?->device_limit ?? 1)),
                    'limit_bytes_total' => $this->dataLimitBytes($package),
                ],
                'status' => 'pending',
                'attempts' => 0,
                'claimed_at' => null,
                'completed_at' => null,
                'failure_reason' => null,
            ],
        );
    }

    /**
     * Put a failed activation back in the queue with a fresh attempt budget.
     * When delays are compensated, the router limit is the full package
     * duration (access will be re-based on the moment activation succeeds).
     */
    public function requeueProvision(SiteAgentCommand $command, Session $session): SiteAgentCommand
    {
        $payload = $command->payload ?? [];
        $payload['limit_uptime'] = $this->routerUptimeLimit(
            $session,
            fullDuration: (bool) Policy::get('provisioning.compensate_delay', config('provisioning.compensate_delay', true)),
        );

        $command->forceFill([
            'payload' => $payload,
            'status' => 'pending',
            'attempts' => 0,
            'claimed_at' => null,
            'completed_at' => null,
        ])->save();

        return $command;
    }

    public function expire(Router $router, Session $session, Voucher $voucher): SiteAgentCommand
    {
        $agent = $this->agentFor($router);
        $command = SiteAgentCommand::query()->firstOrNew([
            'session_id' => $session->id,
            'operation' => 'expire_user',
        ]);

        if ($command->exists && in_array($command->status, ['pending', 'claimed', 'completed'], true)) {
            return $command;
        }

        $command->fill([
            'site_agent_id' => $agent->id,
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'payload' => ['username' => $voucher->code],
            'status' => 'pending',
            'attempts' => 0,
            'claimed_at' => null,
            'completed_at' => null,
            'failure_reason' => null,
        ])->save();

        return $command;
    }

    private function agentFor(Router $router): SiteAgent
    {
        if (! $router->site_id) {
            throw new \LogicException('Router must be assigned to a site with a paired site agent.');
        }

        return SiteAgent::query()
            ->where('tenant_id', $router->tenant_id)
            ->where('site_id', $router->site_id)
            ->whereNull('revoked_at')
            ->firstOrFail();
    }

    private function routerUptimeLimit(Session $session, bool $fullDuration = false): string
    {
        $package = $fullDuration ? $session->package : null;
        $target = $package ? $package->expiresAt(now()) : $session->expires_at;

        $seconds = max(1, (int) floor(now()->diffInSeconds($target, false)));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }

    /**
     * The package's data allowance in bytes for the router, or null for unlimited.
     * A legacy value that cannot be parsed is logged and treated as unlimited
     * rather than blocking a paying customer's activation.
     */
    private function dataLimitBytes(?\App\Models\Package $package): ?int
    {
        try {
            return \App\Support\DataLimit::bytes($package?->data_limit);
        } catch (\InvalidArgumentException $exception) {
            \Illuminate\Support\Facades\Log::warning('Package has an unparseable data limit; no byte cap applied.', [
                'package_id' => $package?->id,
                'data_limit' => $package?->data_limit,
            ]);

            return null;
        }
    }
}
