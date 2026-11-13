<?php

namespace App\Services;

use App\Models\BlockedDevice;
use App\Models\Package;
use App\Models\Router;
use App\Models\Session;
use App\Models\SiteAgent;
use App\Models\SiteAgentCommand;
use App\Models\Voucher;
use App\Services\Policy\Policy;

class SiteAgentCommandService
{
    public function banDevice(Router $router, Session $session, Voucher $voucher, BlockedDevice $blockedDevice): SiteAgentCommand
    {
        $agent = $this->agentFor($router);
        $command = SiteAgentCommand::query()
            ->where('session_id', $session->id)
            ->where('operation', 'ban_mac')
            ->first();

        if ($command && in_array($command->status, ['pending', 'claimed'], true)) {
            return $command;
        }

        if (! $command) {
            $command = new SiteAgentCommand;
        }

        $command->fill([
            'site_agent_id' => $agent->id,
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'session_id' => $session->id,
            'operation' => 'ban_mac',
            'payload' => [
                'username' => $voucher->code,
                'blocked_device_id' => $blockedDevice->id,
                'session_id' => $session->id,
            ],
            'status' => 'pending',
            'attempts' => 0,
            'claimed_at' => null,
            'completed_at' => null,
            'failure_reason' => null,
        ])->save();

        return $command;
    }

    public function unbanDevice(BlockedDevice $blockedDevice): SiteAgentCommand
    {
        $router = Router::withoutTenancy()
            ->where('tenant_id', $blockedDevice->tenant_id)
            ->findOrFail($blockedDevice->router_id);
        $agent = $this->agentFor($router);
        $command = SiteAgentCommand::query()
            ->whereNull('session_id')
            ->where('operation', 'unban_mac')
            ->whereIn('status', ['pending', 'claimed'])
            ->get()
            ->first(fn (SiteAgentCommand $item): bool => (int) ($item->payload['blocked_device_id'] ?? 0) === $blockedDevice->id);

        if ($command) {
            return $command;
        }

        return SiteAgentCommand::create([
            'site_agent_id' => $agent->id,
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'session_id' => null,
            'operation' => 'unban_mac',
            'payload' => [
                'blocked_device_id' => $blockedDevice->id,
                'mac_address' => $blockedDevice->mac_address,
            ],
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }

    public function retryDeviceAction(BlockedDevice $blockedDevice): void
    {
        if (in_array($blockedDevice->status, ['pending', 'ban_failed'], true)) {
            $session = Session::withoutTenancy()
                ->where('tenant_id', $blockedDevice->tenant_id)
                ->findOrFail($blockedDevice->session_id);
            $router = Router::withoutTenancy()
                ->where('tenant_id', $blockedDevice->tenant_id)
                ->findOrFail($blockedDevice->router_id);
            $voucher = Voucher::withoutTenancy()
                ->where('tenant_id', $blockedDevice->tenant_id)
                ->findOrFail($session->voucher_id);

            $this->banDevice($router, $session, $voucher, $blockedDevice);
            $blockedDevice->update(['status' => 'pending', 'last_error' => null]);

            return;
        }

        if (in_array($blockedDevice->status, ['unban_pending', 'unban_failed'], true)) {
            $this->unbanDevice($blockedDevice);
            $blockedDevice->update(['status' => 'unban_pending', 'last_error' => null]);
        }
    }

    public function provision(Router $router, Session $session, Voucher $voucher): SiteAgentCommand
    {
        $agent = $this->agentFor($router);
        $package = $session->package;
        $profileSettings = $package?->routerProfileSettings();

        return SiteAgentCommand::query()->updateOrCreate(
            ['session_id' => $session->id, 'operation' => 'provision_user'],
            [
                'site_agent_id' => $agent->id,
                'tenant_id' => $router->tenant_id,
                'router_id' => $router->id,
                'payload' => [
                    'username' => $voucher->code,
                    'password' => $voucher->code,
                    'profile' => $package?->routerProfileName() ?? 'default',
                    'profile_settings' => $profileSettings,
                    'limit_uptime' => $this->routerUptimeLimit($session),
                    'limit_bytes_total' => $package?->routerLimitBytes(),
                ],
                'status' => 'pending',
                'attempts' => 0,
                'claimed_at' => null,
                'completed_at' => null,
                'failure_reason' => null,
            ],
        );
    }

    public function syncProfiles(Router $router): SiteAgentCommand
    {
        $agent = $this->agentFor($router);
        $profiles = Package::withoutGlobalScopes()
            ->where('tenant_id', $router->tenant_id)
            ->get()
            ->map(fn (Package $package): array => $package->routerProfileSettings())
            ->values()
            ->all();

        $command = SiteAgentCommand::query()
            ->where('router_id', $router->id)
            ->whereNull('session_id')
            ->where('operation', 'sync_profile')
            ->whereIn('status', ['pending', 'claimed'])
            ->latest('id')
            ->first();

        if ($command) {
            if ($command->status === 'pending') {
                $command->update([
                    'payload' => ['profiles' => $profiles],
                    'site_agent_id' => $agent->id,
                    'tenant_id' => $router->tenant_id,
                    'status' => 'pending',
                    'attempts' => 0,
                    'claimed_at' => null,
                    'completed_at' => null,
                    'failure_reason' => null,
                ]);
            }

            return $command;
        }

        return SiteAgentCommand::create([
            'site_agent_id' => $agent->id,
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'session_id' => null,
            'operation' => 'sync_profile',
            'payload' => ['profiles' => $profiles],
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }

    public function testConnection(Router $router): SiteAgentCommand
    {
        return $this->queueRouterCheck($router, 'test_connection');
    }

    public function telemetry(Router $router): SiteAgentCommand
    {
        return $this->queueRouterCheck($router, 'telemetry');
    }

    private function queueRouterCheck(Router $router, string $operation): SiteAgentCommand
    {
        $agent = $this->agentFor($router);
        $command = SiteAgentCommand::query()
            ->where('router_id', $router->id)
            ->whereNull('session_id')
            ->where('operation', $operation)
            ->whereIn('status', ['pending', 'claimed'])
            ->latest('id')
            ->first();

        if ($command) {
            return $command;
        }

        return SiteAgentCommand::create([
            'site_agent_id' => $agent->id,
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'session_id' => null,
            'operation' => $operation,
            'payload' => [],
            'status' => 'pending',
            'attempts' => 0,
        ]);
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

    public function expire(
        Router $router,
        Session $session,
        Voucher $voucher,
        string $finalSessionStatus = 'expired',
    ): SiteAgentCommand {
        if (! in_array($finalSessionStatus, ['expired', 'disconnected'], true)) {
            throw new \InvalidArgumentException('Unsupported final session status for an expiry command.');
        }

        $session->update([
            'status' => 'disconnecting',
            'pending_revocation' => true,
            'pending_revocation_status' => $finalSessionStatus,
        ]);

        $agent = $this->agentFor($router);
        $command = SiteAgentCommand::query()->firstOrNew([
            'session_id' => $session->id,
            'operation' => 'expire_user',
        ]);

        if ($command->exists && in_array($command->status, ['pending', 'claimed', 'completed'], true)) {
            if ($command->status === 'completed') {
                $session->update([
                    'status' => $finalSessionStatus,
                    'pending_revocation' => false,
                    'pending_revocation_status' => null,
                ]);

                return $command;
            }

            if ($command->status !== 'completed') {
                $command->update([
                    'site_agent_id' => $agent->id,
                    'payload' => [
                        'username' => $voucher->code,
                        'session_status' => $finalSessionStatus,
                    ],
                ]);
            }

            return $command;
        }

        $command->fill([
            'site_agent_id' => $agent->id,
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'payload' => [
                'username' => $voucher->code,
                'session_status' => $finalSessionStatus,
            ],
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
}
