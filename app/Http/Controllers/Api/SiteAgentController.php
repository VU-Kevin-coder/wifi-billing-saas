<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BlockedDevice;
use App\Models\Router;
use App\Models\Session;
use App\Models\SiteAgent;
use App\Models\SiteAgentCommand;
use App\Services\ProvisioningWatchdog;
use App\Services\RouterIdentityEnrollmentService;
use App\Services\RouterIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SiteAgentController extends Controller
{
    private const IDENTITY_GUARD_AGENT_VERSION = '2';

    public function poll(Request $request): JsonResponse
    {
        /** @var SiteAgent $agent */
        $agent = $request->attributes->get('site_agent');
        $identityGuardSupported = $request->header('X-Site-Agent-Version') === self::IDENTITY_GUARD_AGENT_VERSION;
        $commands = DB::transaction(function () use ($agent, $identityGuardSupported): array {
            $agent->forceFill([
                'last_seen_at' => now(),
                'offline_notified_at' => null,
            ])->save();
            $rows = SiteAgentCommand::query()
                ->where('site_agent_id', $agent->id)
                ->where(function ($query): void {
                    $query->where('status', 'pending')
                        ->orWhere(function ($stale): void {
                            $stale->where('status', 'claimed')
                                ->where('claimed_at', '<=', now()->subMinutes(2));
                        });
                })
                ->when(! $identityGuardSupported, fn ($query) => $query->whereIn('operation', ['test_connection', 'telemetry']))
                ->orderBy('id')
                ->lockForUpdate()
                ->limit(20)
                ->get();

            foreach ($rows as $command) {
                if ($command->attempts >= 5) {
                    $command->update(['status' => 'failed']);
                    if (in_array($command->operation, ['telemetry', 'test_connection'], true)) {
                        $this->markRouterOffline($agent, $command);
                    } elseif (in_array($command->operation, ['ban_mac', 'unban_mac'], true)) {
                        $this->markDeviceActionFailed($command, 'Site agent exhausted its command attempts.');
                    }

                    continue;
                }

                $command->update([
                    'status' => 'claimed',
                    'attempts' => $command->attempts + 1,
                    'claimed_at' => now(),
                ]);
            }

            return $rows
                ->where('status', 'claimed')
                ->where('attempts', '<=', 5)
                ->map(function (SiteAgentCommand $command) use ($agent): array {
                    $router = $this->routerForAgent($agent, $command);

                    return [
                        'id' => $command->id,
                        'operation' => $command->operation,
                        'router_id' => $command->router_id,
                        'payload' => $command->payload,
                        'expected_identity' => [
                            'verified' => (bool) $router->identity_verified_at,
                            'hardware_model' => $router->hardware_model,
                            'serial_number' => $router->serial_number,
                        ],
                    ];
                })
                ->values()
                ->all();
        });

        return response()->json(['commands' => $commands]);
    }

    public function complete(
        Request $request,
        int $command,
        RouterIdentityEnrollmentService $enrollmentService,
        RouterIdentityService $identityService,
    ): JsonResponse
    {
        $validated = $request->validate([
            'success' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:1000'],
            'telemetry' => ['sometimes', 'nullable', 'array'],
            'telemetry.cpu_usage' => ['required_with:telemetry', 'integer', 'between:0,100'],
            'telemetry.memory_usage' => ['required_with:telemetry', 'integer', 'between:0,100'],
            'telemetry.active_users' => ['required_with:telemetry', 'integer', 'min:0'],
            'telemetry.uptime' => ['required_with:telemetry', 'string', 'max:64'],
            'result' => ['sometimes', 'nullable', 'array'],
            'result.mac_address' => ['nullable', 'regex:/^(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
            'result.hardware_model' => ['sometimes', 'string', 'max:255'],
            'result.serial_number' => ['sometimes', 'string', 'max:255'],
            'result.routeros_version' => ['sometimes', 'string', 'max:64'],
            'result.system_identity' => ['sometimes', 'string', 'max:255'],
            'result.identity_state' => ['sometimes', 'in:verified,unverified,mismatch'],
        ]);
        /** @var SiteAgent $agent */
        $agent = $request->attributes->get('site_agent');

        $result = DB::transaction(function () use ($agent, $command, $validated, $enrollmentService, $identityService): array {
            $job = SiteAgentCommand::query()
                ->where('site_agent_id', $agent->id)
                ->lockForUpdate()
                ->findOrFail($command);

            if ($job->status === 'completed') {
                return ['duplicate' => true, 'status' => 'completed'];
            }

            abort_unless($job->status === 'claimed', 409, 'Command is not currently claimed by this agent.');

            if (! $validated['success']) {
                // Older agents may send raw router errors; do not persist them.
                $failureReason = 'Router command failed.';
                $job->update([
                    'status' => $job->attempts >= 5 ? 'failed' : 'pending',
                    'claimed_at' => null,
                    'failure_reason' => $failureReason,
                ]);
                if (in_array($job->operation, ['telemetry', 'test_connection'], true) && $job->attempts >= 5) {
                    $this->markRouterOffline($agent, $job);
                } elseif (in_array($job->operation, ['ban_mac', 'unban_mac'], true) && $job->attempts >= 5) {
                    $this->markDeviceActionFailed($job, $failureReason);
                }

                return ['duplicate' => false, 'status' => $job->status];
            }

            $router = $this->routerForAgent($agent, $job);
            $observedIdentity = $validated['result'] ?? [];
            $identityState = $observedIdentity['identity_state'] ?? null;
            $identityMatches = (bool) $router->identity_verified_at
                && $identityState === 'verified'
                && $identityService->matches($observedIdentity, [
                    'hardware_model' => $router->hardware_model,
                    'serial_number' => $router->serial_number,
                ]);
            if (! in_array($job->operation, ['test_connection', 'enroll_identity', 'telemetry'], true)
                && ! $identityMatches) {
                $job->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'claimed_at' => null,
                    'failure_reason' => $identityState === 'mismatch' || $router->identity_verified_at
                        ? 'Router identity mismatch; write operation was blocked.'
                        : 'Router identity is unverified; write operation was blocked.',
                    'result' => $observedIdentity,
                ]);
                if (in_array($job->operation, ['ban_mac', 'unban_mac'], true)) {
                    $this->markDeviceActionFailed($job, $job->failure_reason);
                }
                app(\App\Services\SiteAgentCommandService::class)->testConnection($router);

                return ['duplicate' => false, 'status' => 'failed', 'identity_required' => true];
            }

            if ($job->operation === 'enroll_identity') {
                $identity = $validated['result'] ?? [];
                abort_unless(
                    filled($identity['hardware_model'] ?? null) && filled($identity['serial_number'] ?? null),
                    422,
                    'Identity enrollment did not include both the board name and serial number.',
                );
                $router = $enrollmentService->enroll($router, $identity);
                $router->update([
                    'status' => 'online',
                    'last_seen_at' => now(),
                ]);
                AuditLog::record('router.identity_enrolled', $router, [
                    'hardware_model' => $router->hardware_model,
                ]);
            }

            $job->update([
                'status' => 'completed',
                'completed_at' => now(),
                'claimed_at' => null,
                'failure_reason' => null,
                'result' => $validated['result'] ?? null,
            ]);

            if ($job->operation === 'telemetry') {
                abort_unless(isset($validated['telemetry']), 422, 'Telemetry command did not include router metrics.');
                $this->routerForAgent($agent, $job)->update([
                    'status' => 'online',
                    'last_seen_at' => now(),
                    'telemetry_at' => now(),
                    'cpu_usage' => $validated['telemetry']['cpu_usage'],
                    'memory_usage' => $validated['telemetry']['memory_usage'],
                    'active_users' => $validated['telemetry']['active_users'],
                    'uptime' => $validated['telemetry']['uptime'],
                ]);
            } elseif ($job->operation === 'test_connection') {
                $observed = $validated['result'] ?? [];
                $actualIdentityState = $identityService->status($router, $observed);
                if ($identityState !== $actualIdentityState || $actualIdentityState === 'mismatch') {
                    $job->update([
                        'status' => 'failed',
                        'failure_reason' => 'Router identity mismatch; write operations remain blocked.',
                    ]);
                } elseif ($actualIdentityState === 'verified') {
                    $router->update([
                        'status' => 'online',
                        'last_seen_at' => now(),
                    ]);
                }
            } elseif ($job->operation === 'expire_user') {
                $sessionStatus = $job->payload['session_status'] ?? 'expired';
                abort_unless(in_array($sessionStatus, ['expired', 'disconnected'], true), 422, 'Invalid session status in revocation command.');
                $session = Session::withoutTenancy()
                    ->where('tenant_id', $job->tenant_id)
                    ->whereKey($job->session_id)
                    ->first();
                if ($session) {
                    $session->update([
                        'status' => $sessionStatus,
                        'pending_revocation' => false,
                        'pending_revocation_status' => null,
                    ]);
                    AuditLog::record('session.revocation_completed', $session, [
                        'router_id' => $job->router_id,
                        'session_status' => $sessionStatus,
                    ]);
                }
            } elseif ($job->operation === 'ban_mac') {
                $macAddress = strtoupper((string) ($validated['result']['mac_address'] ?? ''));
                abort_unless($macAddress !== '', 422, 'Device ban command did not report the router-verified MAC address.');

                $blockedDeviceId = (int) ($job->payload['blocked_device_id'] ?? 0);
                $blockedDevice = BlockedDevice::withoutGlobalScopes()
                    ->where('tenant_id', $job->tenant_id)
                    ->where('router_id', $job->router_id)
                    ->findOrFail($blockedDeviceId);
                $existing = BlockedDevice::withoutGlobalScopes()
                    ->where('tenant_id', $job->tenant_id)
                    ->where('router_id', $job->router_id)
                    ->where('mac_address', $macAddress)
                    ->where('id', '<>', $blockedDevice->id)
                    ->first();

                if ($existing) {
                    $existing->update([
                        'session_id' => $job->payload['session_id'] ?? $existing->session_id,
                        'status' => 'blocked',
                        'reason' => $blockedDevice->reason,
                        'last_error' => null,
                    ]);
                    $blockedDevice->delete();
                    $blockedDevice = $existing;
                } else {
                    $blockedDevice->update([
                        'mac_address' => $macAddress,
                        'status' => 'blocked',
                        'last_error' => null,
                    ]);
                }

                Session::withoutTenancy()
                    ->where('tenant_id', $job->tenant_id)
                    ->whereKey($job->payload['session_id'] ?? null)
                    ->update([
                        'status' => 'disconnected',
                        'expires_at' => now(),
                        'pending_device_ban' => false,
                    ]);
                AuditLog::record('device.mac_blocked', $blockedDevice, [
                    'mac_address' => $macAddress,
                    'router_id' => $job->router_id,
                ]);
            } elseif ($job->operation === 'unban_mac') {
                $blockedDeviceId = (int) ($job->payload['blocked_device_id'] ?? 0);
                $blockedDevice = BlockedDevice::withoutGlobalScopes()
                    ->where('tenant_id', $job->tenant_id)
                    ->where('router_id', $job->router_id)
                    ->findOrFail($blockedDeviceId);
                $blockedDevice->update([
                    'status' => 'unblocked',
                    'last_error' => null,
                ]);
                AuditLog::record('device.mac_unblocked', $blockedDevice, [
                    'mac_address' => $blockedDevice->mac_address,
                    'router_id' => $job->router_id,
                ]);
            } elseif ($job->operation === 'provision_user') {
                app(ProvisioningWatchdog::class)->markProvisioned($job);
            }

            return ['duplicate' => false, 'status' => $job->status];
        });

        return response()->json($result);
    }

    private function markRouterOffline(SiteAgent $agent, SiteAgentCommand $command): void
    {
        $this->routerForAgent($agent, $command)->update(['status' => 'offline']);
    }

    private function markDeviceActionFailed(SiteAgentCommand $command, string $reason): void
    {
        $blockedDeviceId = (int) ($command->payload['blocked_device_id'] ?? 0);
        if ($blockedDeviceId === 0) {
            return;
        }

        BlockedDevice::withoutGlobalScopes()
            ->where('tenant_id', $command->tenant_id)
            ->where('router_id', $command->router_id)
            ->whereKey($blockedDeviceId)
            ->update([
                'status' => $command->operation === 'unban_mac' ? 'unban_failed' : 'ban_failed',
                'last_error' => $reason,
            ]);
    }

    private function routerForAgent(SiteAgent $agent, SiteAgentCommand $command): Router
    {
        return Router::withoutTenancy()
            ->where('tenant_id', $agent->tenant_id)
            ->findOrFail($command->router_id);
    }
}
