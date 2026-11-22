<?php

namespace App\Services;

use App\Jobs\DeliverRouterCommandJob;
use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Router;
use App\Models\RouterCommand;
use App\Models\Session;
use App\Models\Subscriber;
use App\Support\DataLimit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class RouterCommandService
{
    private const API_LEASE_SECONDS = 60;

    private const POLL_LEASE_SECONDS = 120;

    private const MAX_ATTEMPTS = 20;

    public function __construct(private readonly RouterConnectionService $connection) {}

    public function authenticatePollingToken(?string $token): ?Router
    {
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return null;
        }

        $tokenHash = hash('sha256', $token);
        $router = Router::withoutTenancy()
            ->where('polling_token_hash', $tokenHash)
            ->where('enrolled', true)
            ->with('vpnPeer')
            ->first();

        if (! $router
            || ! $router->vpnPeer?->enabled
            || ! hash_equals((string) $router->getRawOriginal('polling_token_hash'), $tokenHash)) {
            return null;
        }

        return $router;
    }

    public function requestKick(Session $session, string $idempotencyKey): bool
    {
        $command = $this->recordKick($session, $idempotencyKey);
        if (! $command) {
            return false;
        }

        if ($command->state === 'pending_api') {
            $this->deliverViaApi((int) $command->id);
        }

        return in_array($command->fresh()->state, ['pending_api', 'api_leased', 'pending_poll', 'poll_leased', 'delivered'], true);
    }

    public function recordKick(Session $session, string $idempotencyKey, bool $dispatch = false): ?RouterCommand
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            return null;
        }

        return DB::transaction(function () use ($session, $idempotencyKey, $dispatch): ?RouterCommand {
            $target = Session::withoutTenancy()
                ->where('tenant_id', $session->tenant_id)
                ->where('router_id', $session->router_id)
                ->whereKey($session->id)
                ->with(['voucher' => fn ($query) => $query->withoutTenancy()])
                ->lockForUpdate()
                ->first();

            if (! $target || (! $target->pending_revocation && ! $target->pending_device_ban) || ! $target->voucher
                || ! preg_match('/^[A-Za-z0-9._-]{1,64}$/D', (string) $target->voucher->code)) {
                return null;
            }

            $router = Router::withoutTenancy()
                ->where('tenant_id', $target->tenant_id)
                ->whereKey($target->router_id)
                ->with(['vpnPeer', 'onboarding'])
                ->first();
            if (! $router || $router->access_backend !== 'radius') {
                return null;
            }

            $command = RouterCommand::query()
                ->where('router_id', $router->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if (! $command) {
                $command = RouterCommand::create([
                    'command_id' => (string) Str::uuid(),
                    'tenant_id' => $target->tenant_id,
                    'router_id' => $router->id,
                    'session_id' => $target->id,
                    'operation' => 'kick',
                    'target_service' => 'hotspot',
                    'target_username' => $target->voucher->code,
                    'idempotency_key' => $idempotencyKey,
                    'state' => 'pending_api',
                    'available_at' => now(),
                ]);
            } elseif ((int) $command->session_id !== (int) $target->id
                || $command->target_service !== 'hotspot'
                || $command->target_username !== $target->voucher->code) {
                return null;
            } elseif (in_array($command->state, ['failed', 'cancelled'], true)) {
                $command->update([
                    'state' => 'pending_api',
                    'api_attempt_count' => 0,
                    'poll_attempt_count' => 0,
                    'available_at' => now(),
                    'lease_owner' => null,
                    'lease_token_hash' => null,
                    'lease_expires_at' => null,
                    'delivered_via' => null,
                    'result_code' => null,
                ]);
            }

            if ($dispatch && $command->state === 'pending_api') {
                DeliverRouterCommandJob::dispatch((int) $command->id)->afterCommit();
            }

            return $command;
        });
    }

    public function requestPppoeKick(int $subscriberSessionId, string $idempotencyKey): bool
    {
        $command = $this->recordPppoeKick($subscriberSessionId, $idempotencyKey);
        if (! $command) {
            return false;
        }

        if ($command->state === 'pending_api') {
            $this->deliverViaApi((int) $command->id);
        }

        return in_array($command->fresh()->state, ['pending_api', 'api_leased', 'pending_poll', 'poll_leased', 'delivered'], true);
    }

    public function recordPppoeKick(
        int $subscriberSessionId,
        string $idempotencyKey,
        bool $dispatch = false,
    ): ?RouterCommand {
        if ($subscriberSessionId < 1 || $idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            return null;
        }

        return DB::transaction(function () use ($subscriberSessionId, $idempotencyKey, $dispatch): ?RouterCommand {
            $target = DB::table('radius_subscriber_sessions as ppp_sessions')
                ->join('subscribers', function ($join): void {
                    $join->on('subscribers.id', '=', 'ppp_sessions.subscriber_id')
                        ->on('subscribers.tenant_id', '=', 'ppp_sessions.tenant_id');
                })
                ->where('ppp_sessions.id', $subscriberSessionId)
                ->where('ppp_sessions.state', 'open')
                ->lockForUpdate()
                ->first([
                    'ppp_sessions.tenant_id',
                    'ppp_sessions.router_id',
                    'ppp_sessions.id as subscriber_session_id',
                    'subscribers.username',
                ]);
            if (! $target || ! preg_match('/^[A-Za-z0-9._-]{1,64}$/D', (string) $target->username)) {
                return null;
            }

            $router = Router::withoutTenancy()
                ->where('tenant_id', $target->tenant_id)
                ->whereKey($target->router_id)
                ->with(['vpnPeer', 'onboarding'])
                ->first();
            if (! $router || $router->access_backend !== 'radius' || ! $router->enable_pppoe) {
                return null;
            }

            $command = RouterCommand::query()
                ->where('router_id', $router->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if (! $command) {
                $command = RouterCommand::create([
                    'command_id' => (string) Str::uuid(),
                    'tenant_id' => $target->tenant_id,
                    'router_id' => $router->id,
                    'subscriber_session_id' => $target->subscriber_session_id,
                    'operation' => 'kick',
                    'target_service' => 'pppoe',
                    'target_username' => $target->username,
                    'idempotency_key' => $idempotencyKey,
                    'state' => 'pending_api',
                    'available_at' => now(),
                ]);
            } elseif ((int) $command->subscriber_session_id !== (int) $target->subscriber_session_id
                || $command->target_service !== 'pppoe'
                || $command->target_username !== $target->username) {
                return null;
            } elseif (in_array($command->state, ['failed', 'cancelled'], true)) {
                $command->update([
                    'state' => 'pending_api',
                    'api_attempt_count' => 0,
                    'poll_attempt_count' => 0,
                    'available_at' => now(),
                    'lease_owner' => null,
                    'lease_token_hash' => null,
                    'lease_expires_at' => null,
                    'delivered_via' => null,
                    'result_code' => null,
                ]);
            }

            if ($dispatch && $command->state === 'pending_api') {
                DeliverRouterCommandJob::dispatch((int) $command->id)->afterCommit();
            }

            return $command;
        });
    }

    public function deliverViaApi(int $commandId): void
    {
        $claim = $this->claimApiCommand($commandId);
        if (! $claim) {
            return;
        }

        $command = RouterCommand::query()
            ->with([
                'router' => fn ($query) => $query->withoutTenancy(),
                'router.vpnPeer',
                'router.onboarding',
                'session' => fn ($query) => $query->withoutTenancy(),
                'session.voucher' => fn ($query) => $query->withoutTenancy(),
            ])
            ->find($commandId);

        $activeTarget = $this->activeTarget($command);
        if (! $command
            || $command->operation !== 'kick'
            || ! in_array($command->operation, RouterCommand::ALLOWED_OPERATIONS, true)
            || ! in_array($command->target_service, RouterCommand::ALLOWED_TARGET_SERVICES, true)
            || ! $activeTarget
            || $command->router?->access_backend !== 'radius'
            || ! $command->router?->enrolled
            || ! $command->router?->vpnPeer?->enabled
            || ! in_array($command->router?->onboarding?->status, ['api_verified', 'radius_ready', 'complete'], true)) {
            $this->finishApiAttempt($commandId, $claim['lease_hash'], 'failed', null, 'router_not_ready');

            return;
        }

        try {
            $connection = $this->connection->test($command->router);
            if (! ($connection['ok'] ?? false)) {
                $state = ($connection['failure_type'] ?? null) === 'identity_not_verified'
                    ? 'failed'
                    : 'pending_poll';
                $this->finishApiAttempt(
                    $commandId,
                    $claim['lease_hash'],
                    $state,
                    null,
                    $state === 'failed' ? 'identity_unverified' : 'api_unavailable',
                );

                return;
            }

            if ($this->connection->disconnectUser(
                $command->router,
                (string) $command->target_username,
                (string) $command->target_service,
            )) {
                $this->finishApiAttempt($commandId, $claim['lease_hash'], 'delivered', 'api_ssl', 'kicked');

                return;
            }

            $this->finishApiAttempt($commandId, $claim['lease_hash'], 'pending_poll', null, 'session_not_active');
        } catch (Throwable $exception) {
            Log::warning('Router API-SSL kick attempt failed; the command will use polling if configured.', [
                'router_id' => $command->router_id,
                'command_id' => $command->command_id,
                'exception' => $exception::class,
            ]);
            $this->finishApiAttempt($commandId, $claim['lease_hash'], 'pending_poll', null, 'api_unavailable');
        }
    }

    public function dispatchPendingApiCommands(int $limit = 100): int
    {
        $now = now();
        $ids = RouterCommand::query()
            ->where(function ($query) use ($now): void {
                $query->where(function ($pending) use ($now): void {
                    $pending->where('state', 'pending_api')
                        ->where('available_at', '<=', $now);
                })->orWhere(function ($expired) use ($now): void {
                    $expired->where('state', 'api_leased')
                        ->where('lease_expires_at', '<=', $now);
                });
            })
            ->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->pluck('id');

        foreach ($ids as $id) {
            $this->deliverViaApi((int) $id);
        }

        return $ids->count();
    }

    public function poll(Router $router, array $telemetry): ?array
    {
        $router->loadMissing('onboarding');
        $onboardingStatus = $router->onboarding?->status;

        $router->forceFill([
            'cpu_usage' => $telemetry['cpu_usage'],
            'memory_usage' => $telemetry['memory_usage'],
            'active_users' => $telemetry['active_users'],
            'status' => $onboardingStatus !== null && $onboardingStatus !== 'complete'
                ? 'provisioning'
                : 'online',
            'last_seen_at' => now(),
            'telemetry_at' => now(),
            'polling_last_seen_at' => now(),
        ])->saveOrFail();

        return DB::transaction(function () use ($router): ?array {
            $now = now();
            $command = DB::table('router_commands as commands')
                ->where('commands.tenant_id', $router->tenant_id)
                ->where('commands.router_id', $router->id)
                ->where('commands.operation', 'kick')
                ->where(function ($query) use ($now): void {
                    $query->where(function ($pending) use ($now): void {
                        $pending->where('commands.state', 'pending_poll')
                            ->where('commands.available_at', '<=', $now);
                    })->orWhere(function ($expiredPollLease) use ($now): void {
                        $expiredPollLease->where('commands.state', 'poll_leased')
                            ->where('commands.lease_expires_at', '<=', $now);
                    })->orWhere(function ($apiFallback) use ($now): void {
                        $apiFallback->where('commands.state', 'pending_api')
                            ->where('commands.created_at', '<=', $now->copy()->subSeconds(self::API_LEASE_SECONDS));
                    })->orWhere(function ($expiredApiLease) use ($now): void {
                        $expiredApiLease->where('commands.state', 'api_leased')
                            ->where('commands.lease_expires_at', '<=', $now);
                    });
                })
                ->where(function ($targets): void {
                    $targets->where(function ($hotspot): void {
                        $hotspot->where('commands.target_service', 'hotspot')
                            ->whereNotNull('commands.session_id')
                            ->whereExists(function ($session): void {
                                $session->selectRaw('1')
                                    ->from('sessions_log as sessions')
                                    ->join('vouchers', 'vouchers.id', '=', 'sessions.voucher_id')
                                    ->whereColumn('sessions.id', 'commands.session_id')
                                    ->whereColumn('sessions.tenant_id', 'commands.tenant_id')
                                    ->whereColumn('sessions.router_id', 'commands.router_id')
                                    ->whereColumn('vouchers.code', 'commands.target_username')
                                    ->where(function ($revoked): void {
                                        $revoked->where('sessions.pending_revocation', true)
                                            ->orWhere('sessions.pending_device_ban', true);
                                    });
                            });
                    })->orWhere(function ($pppoe): void {
                        $pppoe->where('commands.target_service', 'pppoe')
                            ->whereNotNull('commands.subscriber_session_id')
                            ->whereExists(function ($session): void {
                                $session->selectRaw('1')
                                    ->from('radius_subscriber_sessions as ppp_sessions')
                                    ->join('subscribers', function ($join): void {
                                        $join->on('subscribers.id', '=', 'ppp_sessions.subscriber_id')
                                            ->on('subscribers.tenant_id', '=', 'ppp_sessions.tenant_id');
                                    })
                                    ->whereColumn('ppp_sessions.id', 'commands.subscriber_session_id')
                                    ->whereColumn('ppp_sessions.tenant_id', 'commands.tenant_id')
                                    ->whereColumn('ppp_sessions.router_id', 'commands.router_id')
                                    ->whereColumn('subscribers.username', 'commands.target_username')
                                    ->where('ppp_sessions.state', 'open');
                            });
                    });
                })
                ->orderBy('commands.id')
                ->select([
                    'commands.command_id',
                    'commands.poll_attempt_count',
                    'commands.lease_expires_at',
                    'commands.state',
                    'commands.target_service',
                    'commands.target_username',
                    'commands.tenant_id',
                    'commands.router_id',
                    'commands.subscriber_session_id',
                ])
                ->lockForUpdate()
                ->first();

            if (! $command
                || ! in_array($command->target_service, RouterCommand::ALLOWED_TARGET_SERVICES, true)
                || ! preg_match('/^[A-Za-z0-9._-]{1,64}$/D', (string) $command->target_username)) {
                return null;
            }

            if ($command->target_service === 'pppoe'
                && ! $this->pppoeTargetNeedsDisconnect(
                    (int) $command->tenant_id,
                    (int) $command->router_id,
                    (int) $command->subscriber_session_id,
                    (string) $command->target_username,
                )) {
                DB::table('router_commands')
                    ->where('command_id', $command->command_id)
                    ->update([
                        'state' => 'cancelled',
                        'lease_owner' => null,
                        'lease_token_hash' => null,
                        'lease_expires_at' => null,
                        'result_code' => 'disconnect_not_required',
                        'updated_at' => $now,
                    ]);

                return null;
            }

            if ((int) $command->poll_attempt_count >= self::MAX_ATTEMPTS) {
                DB::table('router_commands')
                    ->where('command_id', $command->command_id)
                    ->update([
                        'state' => 'failed',
                        'lease_owner' => null,
                        'lease_token_hash' => null,
                        'lease_expires_at' => null,
                        'result_code' => 'poll_attempt_limit',
                        'updated_at' => $now,
                    ]);

                return null;
            }

            $leaseToken = bin2hex(random_bytes(32));
            DB::table('router_commands')
                ->where('command_id', $command->command_id)
                ->update([
                    'state' => 'poll_leased',
                    'poll_attempt_count' => (int) $command->poll_attempt_count + 1,
                    'lease_owner' => 'poll',
                    'lease_token_hash' => hash('sha256', $leaseToken),
                    'lease_expires_at' => $now->copy()->addSeconds(self::POLL_LEASE_SECONDS),
                    'updated_at' => $now,
                ]);

            return [
                'command_id' => $command->command_id,
                'lease_token' => $leaseToken,
                'operation' => 'kick',
                'target_service' => $command->target_service,
                'username' => $command->target_username,
            ];
        });
    }

    public function acknowledgePoll(Router $router, string $commandId, string $leaseToken, string $resultCode): bool
    {
        return DB::transaction(function () use ($router, $commandId, $leaseToken, $resultCode): bool {
            $command = RouterCommand::query()
                ->where('router_id', $router->id)
                ->where('tenant_id', $router->tenant_id)
                ->where('command_id', $commandId)
                ->lockForUpdate()
                ->first();

            if (! $command) {
                return false;
            }

            if ($command->state === 'delivered' && $command->delivered_via === 'polling') {
                return true;
            }

            if ($command->state !== 'poll_leased'
                || ! $command->lease_expires_at?->isFuture()
                || ! is_string($command->lease_token_hash)
                || ! hash_equals($command->lease_token_hash, hash('sha256', $leaseToken))
                || ! in_array($resultCode, ['kicked', 'already_absent'], true)) {
                return false;
            }

            $command->update([
                'state' => 'delivered',
                'delivered_via' => 'polling',
                'result_code' => $resultCode,
                'lease_owner' => null,
                'lease_token_hash' => null,
                'lease_expires_at' => null,
            ]);

            return true;
        });
    }

    public function cancelPendingForSession(int $sessionId): void
    {
        RouterCommand::query()
            ->where('session_id', $sessionId)
            ->whereIn('state', ['pending_api', 'api_leased', 'pending_poll', 'poll_leased'])
            ->update([
                'state' => 'cancelled',
                'lease_owner' => null,
                'lease_token_hash' => null,
                'lease_expires_at' => null,
            ]);
    }

    public function cancelPendingForPppoeSession(int $subscriberSessionId): void
    {
        RouterCommand::query()
            ->where('subscriber_session_id', $subscriberSessionId)
            ->whereIn('state', ['pending_api', 'api_leased', 'pending_poll', 'poll_leased'])
            ->update([
                'state' => 'cancelled',
                'lease_owner' => null,
                'lease_token_hash' => null,
                'lease_expires_at' => null,
            ]);
    }

    private function activeTarget(?RouterCommand $command): bool
    {
        if (! $command || ! is_string($command->target_username)
            || ! preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $command->target_username)) {
            return false;
        }

        if ($command->target_service === 'hotspot') {
            $session = $command->session;

            return $session
                && ($session->pending_revocation || $session->pending_device_ban)
                && $session->voucher
                && hash_equals((string) $session->voucher->code, $command->target_username);
        }

        if ($command->target_service !== 'pppoe' || ! $command->subscriber_session_id) {
            return false;
        }

        return $this->pppoeTargetNeedsDisconnect(
            (int) $command->tenant_id,
            (int) $command->router_id,
            (int) $command->subscriber_session_id,
            $command->target_username,
        );
    }

    private function pppoeTargetNeedsDisconnect(
        int $tenantId,
        int $routerId,
        int $subscriberSessionId,
        string $username,
    ): bool {
        $accountingSession = DB::table('radius_subscriber_sessions')
            ->where('id', $subscriberSessionId)
            ->where('tenant_id', $tenantId)
            ->where('router_id', $routerId)
            ->where('state', 'open')
            ->first(['subscriber_id']);
        if (! $accountingSession) {
            return false;
        }

        $subscriber = Subscriber::withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('username', $username)
            ->first();
        if (! $subscriber) {
            return false;
        }
        if ((int) $subscriber->id !== (int) $accountingSession->subscriber_id) {
            return false;
        }
        if ($subscriber->status !== 'active'
            || ! $subscriber->expires_at
            || CarbonImmutable::parse($subscriber->expires_at)->lessThanOrEqualTo(now())) {
            return true;
        }

        $package = Package::withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->find($subscriber->package_id);
        if (! $package || ! in_array('PPPoE', (array) $package->features, true)) {
            return true;
        }
        if (! PlatformSetting::get('package.data_caps', true)) {
            return false;
        }

        try {
            $limit = DataLimit::bytes($package->data_limit);
        } catch (InvalidArgumentException) {
            return true;
        }

        return $limit !== null && (int) $subscriber->bytes_used >= $limit;
    }

    private function claimApiCommand(int $commandId): ?array
    {
        return DB::transaction(function () use ($commandId): ?array {
            $command = RouterCommand::query()->lockForUpdate()->find($commandId);
            if (! $command
                || ! in_array($command->state, ['pending_api', 'api_leased'], true)
                || ($command->state === 'api_leased' && $command->lease_expires_at?->isFuture())) {
                return null;
            }

            if ((int) $command->api_attempt_count >= self::MAX_ATTEMPTS) {
                $command->update([
                    'state' => 'failed',
                    'lease_owner' => null,
                    'lease_token_hash' => null,
                    'lease_expires_at' => null,
                    'result_code' => 'api_attempt_limit',
                ]);

                return null;
            }

            $leaseToken = bin2hex(random_bytes(32));
            $leaseHash = hash('sha256', $leaseToken);
            $command->update([
                'state' => 'api_leased',
                'api_attempt_count' => (int) $command->api_attempt_count + 1,
                'lease_owner' => 'api',
                'lease_token_hash' => $leaseHash,
                'lease_expires_at' => now()->addSeconds(self::API_LEASE_SECONDS),
            ]);

            return ['lease_hash' => $leaseHash];
        });
    }

    private function finishApiAttempt(
        int $commandId,
        string $leaseHash,
        string $state,
        ?string $deliveredVia,
        string $resultCode,
    ): void {
        $command = DB::table('router_commands')
            ->where('id', $commandId)
            ->first(['router_id', 'tenant_id']);
        if (! $command) {
            return;
        }

        $pollingConfigured = DB::table('routers')
            ->where('id', $command->router_id)
            ->where('tenant_id', $command->tenant_id)
            ->where('enrolled', true)
            ->whereNotNull('polling_token_hash')
            ->exists();
        $finalState = $state === 'pending_poll' && ! $pollingConfigured ? 'failed' : $state;
        $finalResult = $state === 'pending_poll' && ! $pollingConfigured ? 'polling_not_configured' : $resultCode;

        RouterCommand::query()
            ->whereKey($commandId)
            ->where('state', 'api_leased')
            ->where('lease_token_hash', $leaseHash)
            ->update([
                'state' => $finalState,
                'available_at' => now(),
                'lease_owner' => null,
                'lease_token_hash' => null,
                'lease_expires_at' => null,
                'delivered_via' => $deliveredVia,
                'result_code' => $finalResult,
                'updated_at' => now(),
            ]);
    }
}
