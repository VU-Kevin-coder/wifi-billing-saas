<?php

namespace App\Services;

use App\Models\Router;
use App\Models\Session;
use App\Models\SiteAgent;
use App\Models\SiteAgentCommand;
use App\Models\Voucher;

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
                ],
                'status' => 'pending',
                'attempts' => 0,
                'claimed_at' => null,
                'completed_at' => null,
                'failure_reason' => null,
            ],
        );
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

    private function routerUptimeLimit(Session $session): string
    {
        $seconds = max(1, now()->diffInSeconds($session->expires_at, false));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }
}
