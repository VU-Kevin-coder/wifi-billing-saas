<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\RadiusAccountingEvent;
use App\Models\RadiusAccountingSession;
use App\Models\RadiusDisconnectCommand;
use App\Models\RadiusNasSessionIdentity;
use App\Models\Router;
use App\Models\Session;
use App\Models\SiteAgentCommand;
use App\Models\Voucher;
use App\Support\DataLimit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RadiusAccountingService
{
    private const MAX_RADIUS_COUNTER = 4294967295;

    /**
     * Resolve policy only through an authenticated NAS identity. The voucher
     * code is used transiently and is never logged or persisted here.
     */
    public function authorize(string $nasIdentifier, string $nasSessionId, string $voucherCode): array
    {
        $router = $this->radiusRouter($nasIdentifier);
        if (! $router) {
            return ['decision' => 'reject'];
        }

        $voucher = Voucher::withoutTenancy()
            ->where('tenant_id', $router->tenant_id)
            ->where('code', $voucherCode)
            ->where('status', 'redeemed')
            ->first();

        if (! $voucher) {
            return ['decision' => 'reject'];
        }

        $session = Session::withoutTenancy()
            ->where('tenant_id', $router->tenant_id)
            ->where('router_id', $router->id)
            ->where('voucher_id', $voucher->id)
            ->whereIn('status', ['provisioning', 'active'])
            ->where('pending_revocation', false)
            ->where('pending_device_ban', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $session) {
            return ['decision' => 'reject'];
        }

        $package = Package::withoutTenancy()
            ->where('tenant_id', $router->tenant_id)
            ->find($session->package_id);

        if (! $package || (int) $voucher->package_id !== (int) $package->id
            || ! $this->sessionMayAuthenticate($router, $session, $package, $nasSessionId)) {
            return ['decision' => 'reject'];
        }

        $dataLimitBytes = null;
        if (PlatformSetting::get('package.data_caps', true)) {
            try {
                $dataLimitBytes = DataLimit::bytes($package->data_limit);
            } catch (InvalidArgumentException) {
                return ['decision' => 'reject'];
            }

            if ($dataLimitBytes !== null
                && ((int) $session->bytes_in >= $dataLimitBytes
                    || (int) $session->bytes_out >= $dataLimitBytes - (int) $session->bytes_in)) {
                return ['decision' => 'reject'];
            }
        }

        return [
            'decision' => 'accept',
            'policy' => [
                'session_timeout_seconds' => max(1, (int) now()->diffInSeconds($session->expires_at)),
                'rate_limit_mbps' => max(1, (int) $package->speed_limit_mbps),
                'device_limit' => max(1, (int) $package->device_limit),
                'data_limit_bytes' => $dataLimitBytes,
            ],
        ];
    }

    /**
     * Store one normalized accounting event and reconcile only a proven,
     * router-bound session generation. Unknown/stale facts never mutate a
     * customer session.
     */
    public function ingestAccountingEvent(array $input): array
    {
        $router = $this->radiusRouter((string) ($input['nas_identifier'] ?? ''));
        if (! $router) {
            return [
                'accepted' => false,
                'quarantined' => true,
                'reason' => 'unknown_router',
            ];
        }

        $statusType = (string) ($input['status_type'] ?? '');
        $eventTime = $this->eventTime($input['event_time'] ?? null);

        return DB::transaction(function () use ($router, $input, $statusType, $eventTime): array {
            $identity = RadiusNasSessionIdentity::query()->firstOrCreate(
                [
                    'router_id' => $router->id,
                    'nas_session_id' => $input['nas_session_id'],
                ],
                [
                    'tenant_id' => $router->tenant_id,
                    'last_generation' => 0,
                ],
            );

            $identity = RadiusNasSessionIdentity::query()
                ->whereKey($identity->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $identity->tenant_id !== (int) $router->tenant_id
                || (int) $identity->router_id !== (int) $router->id) {
                return ['accepted' => false, 'quarantined' => true, 'reason' => 'identity_mismatch'];
            }

            $fingerprint = $this->fingerprint($identity, $router, $input, $statusType, $eventTime);
            $existing = RadiusAccountingEvent::query()
                ->whereIn('accounting_session_id', RadiusAccountingSession::query()
                    ->where('identity_id', $identity->id)
                    ->select('id'))
                ->where('fingerprint', $fingerprint)
                ->first();

            if ($existing) {
                return [
                    'accepted' => ! $existing->is_quarantined,
                    'quarantined' => (bool) $existing->is_quarantined,
                    'duplicate' => true,
                    'generation' => $existing->accountingSession?->generation,
                    'reason' => $existing->quarantine_reason,
                ];
            }

            $current = $identity->current_generation_id
                ? RadiusAccountingSession::query()
                    ->where('identity_id', $identity->id)
                    ->whereKey($identity->current_generation_id)
                    ->where('state', 'open')
                    ->lockForUpdate()
                    ->first()
                : null;

            $orphanOpen = RadiusAccountingSession::query()
                ->where('identity_id', $identity->id)
                ->where('state', 'open')
                ->when($current, fn ($query) => $query->where('id', '<>', $current->id))
                ->exists();

            if ($orphanOpen) {
                return $this->quarantineNewGeneration($identity, $router, $input, $fingerprint, $eventTime, 'ambiguous_open_generation');
            }

            if ($statusType === 'Start') {
                if ($current) {
                    return $this->quarantineEvent($current, $input, $fingerprint, $eventTime, 'conflicting_start');
                }

                $previous = RadiusAccountingSession::query()
                    ->where('identity_id', $identity->id)
                    ->orderByDesc('generation')
                    ->first();
                if (! $eventTime
                    || ($previous?->state === 'stopped'
                        && (! $previous->stopped_at || $eventTime->lessThanOrEqualTo($previous->stopped_at)))) {
                    return $this->quarantineNewGeneration($identity, $router, $input, $fingerprint, $eventTime, 'stale_or_unordered_start');
                }

                $session = $this->sessionForVoucher(
                    $router,
                    isset($input['voucher_code']) ? (string) $input['voucher_code'] : null,
                    (string) $input['nas_session_id'],
                );
                if (! $session
                    || ($session->started_at && $eventTime->lessThan($session->started_at))) {
                    return $this->quarantineNewGeneration($identity, $router, $input, $fingerprint, $eventTime, 'no_eligible_session');
                }

                [$inputBytes, $inputValid] = $this->counterBytes(
                    $input['input_octets'] ?? null,
                    $input['input_gigawords'] ?? null,
                    (int) $session->bytes_in,
                );
                [$outputBytes, $outputValid] = $this->counterBytes(
                    $input['output_octets'] ?? null,
                    $input['output_gigawords'] ?? null,
                    (int) $session->bytes_out,
                );
                if (! $inputValid || ! $outputValid) {
                    return $this->quarantineNewGeneration($identity, $router, $input, $fingerprint, $eventTime, 'invalid_start_counters');
                }

                $accounting = $this->newGeneration($identity, $router, $session, 'open', $eventTime, $inputBytes, $outputBytes);
                $this->storeEvent($accounting, $input, $fingerprint, $eventTime, false, null);
                $sessionChanges = [
                    'status' => 'active',
                    'bytes_in' => $inputBytes,
                    'bytes_out' => $outputBytes,
                ];
                if (! empty($input['ip_address'])) {
                    $sessionChanges['ip_address'] = $input['ip_address'];
                }
                if (! empty($input['mac_address'])) {
                    $sessionChanges['mac_address'] = $input['mac_address'];
                }
                if ($this->limitReached($session, $inputBytes, $outputBytes)) {
                    $sessionChanges['status'] = 'disconnecting';
                    $sessionChanges['pending_revocation'] = true;
                    $sessionChanges['pending_revocation_status'] = 'expired';
                }
                $session->update($sessionChanges);
                if ($session->pending_revocation_status === 'expired') {
                    $this->createDisconnectCommand($accounting, 'data-limit:'.$session->id);
                }

                return [
                    'accepted' => true,
                    'quarantined' => false,
                    'duplicate' => false,
                    'generation' => $accounting->generation,
                ];
            }

            if (! $current) {
                return $this->quarantineNewGeneration($identity, $router, $input, $fingerprint, $eventTime, 'no_open_generation');
            }

            $session = $current->session_id
                ? Session::withoutTenancy()
                    ->where('tenant_id', $router->tenant_id)
                    ->where('router_id', $router->id)
                    ->whereKey($current->session_id)
                    ->lockForUpdate()
                    ->first()
                : null;

            if (! $session) {
                return $this->quarantineEvent($current, $input, $fingerprint, $eventTime, 'unbound_application_session');
            }

            if (isset($input['voucher_code']) && ! $this->voucherMatchesSession($session, (string) $input['voucher_code'])) {
                return $this->quarantineEvent($current, $input, $fingerprint, $eventTime, 'voucher_mismatch');
            }

            if (! $eventTime
                || ! $current->started_at
                || $eventTime->lessThanOrEqualTo($current->started_at)
                || ($current->last_accounting_at && $eventTime->lessThanOrEqualTo($current->last_accounting_at))) {
                return $this->quarantineEvent($current, $input, $fingerprint, $eventTime, 'stale_or_unordered_event');
            }

            [$inputBytes, $inputValid] = $this->counterBytes(
                $input['input_octets'] ?? null,
                $input['input_gigawords'] ?? null,
                max((int) $current->input_bytes, (int) $session->bytes_in),
            );
            [$outputBytes, $outputValid] = $this->counterBytes(
                $input['output_octets'] ?? null,
                $input['output_gigawords'] ?? null,
                max((int) $current->output_bytes, (int) $session->bytes_out),
            );
            if (! $inputValid || ! $outputValid) {
                return $this->quarantineEvent($current, $input, $fingerprint, $eventTime, 'counter_regression_or_overflow');
            }

            $this->storeEvent($current, $input, $fingerprint, $eventTime, false, null);
            $current->forceFill([
                'last_accounting_at' => $eventTime,
                'input_bytes' => $inputBytes,
                'output_bytes' => $outputBytes,
                'state' => $statusType === 'Stop' ? 'stopped' : 'open',
                'stopped_at' => $statusType === 'Stop' ? $eventTime : null,
            ])->save();

            $sessionChanges = [
                'bytes_in' => $inputBytes,
                'bytes_out' => $outputBytes,
            ];
            if (! empty($input['ip_address'])) {
                $sessionChanges['ip_address'] = $input['ip_address'];
            }
            if (! empty($input['mac_address'])) {
                $sessionChanges['mac_address'] = $input['mac_address'];
            }

            if ($statusType === 'Stop') {
                $sessionChanges['status'] = $session->pending_revocation
                    ? ($session->pending_revocation_status ?: 'disconnected')
                    : 'disconnected';
                $sessionChanges['pending_revocation'] = false;
                $sessionChanges['pending_revocation_status'] = null;
                $identity->forceFill(['current_generation_id' => null])->save();
            } elseif (! $session->pending_revocation && $this->limitReached($session, $inputBytes, $outputBytes)) {
                $sessionChanges['status'] = 'disconnecting';
                $sessionChanges['pending_revocation'] = true;
                $sessionChanges['pending_revocation_status'] = 'expired';
            }

            $session->update($sessionChanges);

            if ($statusType !== 'Stop' && $session->pending_revocation_status === 'expired') {
                $this->createDisconnectCommand($current, 'data-limit:'.$session->id);
            }

            return [
                'accepted' => true,
                'quarantined' => false,
                'duplicate' => false,
                'generation' => $current->generation,
            ];
        });
    }

    /**
     * Persist an idempotent disconnect intent. A missing accounting generation
     * stays pending; it is never reported as a confirmed disconnection.
     */
    public function requestDisconnect(Session $session, string $idempotencyKey): bool
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            return false;
        }

        return DB::transaction(function () use ($session, $idempotencyKey): bool {
            $currentSession = Session::withoutTenancy()
                ->where('tenant_id', $session->tenant_id)
                ->where('router_id', $session->router_id)
                ->whereKey($session->id)
                ->lockForUpdate()
                ->first();
            if (! $currentSession) {
                return false;
            }

            $router = Router::withoutTenancy()
                ->where('tenant_id', $currentSession->tenant_id)
                ->whereKey($currentSession->router_id)
                ->where('access_backend', 'radius')
                ->whereNotNull('radius_nas_identifier')
                ->whereNotNull('radius_shared_secret')
                ->first();
            if (! $router || ! $router->hasUsableRadiusConfiguration()) {
                return false;
            }

            $accounting = RadiusAccountingSession::query()
                ->where('tenant_id', $currentSession->tenant_id)
                ->where('router_id', $currentSession->router_id)
                ->where('session_id', $currentSession->id)
                ->where('state', 'open')
                ->orderByDesc('generation')
                ->lockForUpdate()
                ->first();
            if (! $accounting) {
                return false;
            }

            $this->createDisconnectCommand($accounting, $idempotencyKey);
            $currentSession->update([
                'status' => 'disconnecting',
                'pending_revocation' => true,
                'pending_revocation_status' => $currentSession->pending_revocation_status ?: 'disconnected',
            ]);

            return true;
        });
    }

    /**
     * Claim durable outbox work with a lease. The response excludes tenant
     * records and all credentials; it contains only the NAS/session target.
     */
    public function claimDisconnectCommands(int $limit = 25): array
    {
        return DB::transaction(function () use ($limit): array {
            $now = now();
            $commands = DB::table('radius_disconnect_commands as commands')
                ->join('radius_accounting_sessions as accounting', 'accounting.id', '=', 'commands.accounting_session_id')
                ->join('radius_nas_session_identities as identities', 'identities.id', '=', 'accounting.identity_id')
                ->join('routers', 'routers.id', '=', 'commands.router_id')
                ->where('routers.access_backend', 'radius')
                ->whereNotNull('routers.radius_nas_identifier')
                ->where(function ($query) use ($now): void {
                    $query->where(function ($ready) use ($now): void {
                        $ready->whereIn('commands.state', ['pending', 'retry_wait'])
                            ->where('commands.available_at', '<=', $now);
                    })->orWhere(function ($unconfirmedDisconnect) use ($now): void {
                        $unconfirmedDisconnect->where('commands.state', 'delivery_acknowledged')
                            ->where('commands.available_at', '<=', $now);
                    })->orWhere(function ($expiredLease) use ($now): void {
                        $expiredLease->where('commands.state', 'leased')
                            ->where('commands.lease_expires_at', '<=', $now);
                    });
                })
                ->where('accounting.state', 'open')
                ->orderBy('commands.id')
                ->select([
                    'commands.id',
                    'commands.command_id',
                    'commands.attempt_count',
                    'accounting.generation',
                    'accounting.identity_id',
                    'identities.nas_session_id',
                    'routers.radius_nas_identifier',
                ])
                ->lockForUpdate()
                ->limit(max(1, min(100, $limit)))
                ->get();

            $leaseSeconds = max(15, (int) config('radius_policy.lease_seconds', 60));
            $claimed = [];
            foreach ($commands as $command) {
                $attempts = (int) $command->attempt_count + 1;
                DB::table('radius_disconnect_commands')
                    ->where('id', $command->id)
                    ->update([
                        'state' => 'leased',
                        'attempt_count' => $attempts,
                        'lease_owner' => 'radius-policy-adapter',
                        'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
                        'updated_at' => $now,
                    ]);

                $claimed[] = [
                    'command_id' => $command->command_id,
                    'nas_identifier' => $command->radius_nas_identifier,
                    'nas_session_id' => $command->nas_session_id,
                    'generation' => (int) $command->generation,
                    'attempt' => $attempts,
                ];
            }

            return $claimed;
        });
    }

    /**
     * Record adapter delivery only. The session remains disconnecting until a
     * valid Stop event provides terminal evidence.
     */
    public function acknowledgeDisconnectCommand(string $commandId, string $result, ?string $resultCode = null): array
    {
        return DB::transaction(function () use ($commandId, $result, $resultCode): array {
            $command = RadiusDisconnectCommand::query()
                ->where('command_id', $commandId)
                ->lockForUpdate()
                ->first();
            if (! $command) {
                return ['found' => false, 'duplicate' => false];
            }

            if ($command->state === 'delivery_acknowledged') {
                return ['found' => true, 'duplicate' => true, 'state' => $command->state];
            }

            if ($result === 'delivered') {
                $retrySeconds = max(15, (int) config('radius_policy.retry_seconds', 30));
                $command->update([
                    'state' => 'delivery_acknowledged',
                    'available_at' => now()->addSeconds($retrySeconds),
                    'delivery_acknowledged_at' => now(),
                    'delivery_result_code' => $resultCode ?: 'delivered',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                ]);
            } else {
                $retrySeconds = max(15, (int) config('radius_policy.retry_seconds', 30));
                $command->update([
                    'state' => 'retry_wait',
                    'available_at' => now()->addSeconds($retrySeconds),
                    'delivery_result_code' => $resultCode ?: 'retryable_failure',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                ]);
            }

            return ['found' => true, 'duplicate' => false, 'state' => $command->fresh()->state];
        });
    }

    private function radiusRouter(string $nasIdentifier): ?Router
    {
        if ($nasIdentifier === '' || strlen($nasIdentifier) > 255) {
            return null;
        }

        $router = Router::withoutTenancy()
            ->where('radius_nas_identifier', $nasIdentifier)
            ->where('access_backend', 'radius')
            ->whereNotNull('radius_shared_secret')
            ->first();

        return $router && $router->hasUsableRadiusConfiguration() ? $router : null;
    }

    private function sessionForVoucher(Router $router, ?string $voucherCode, string $nasSessionId): ?Session
    {
        if (! $voucherCode) {
            return null;
        }

        $voucher = Voucher::withoutTenancy()
            ->where('tenant_id', $router->tenant_id)
            ->where('code', $voucherCode)
            ->where('status', 'redeemed')
            ->first();
        if (! $voucher) {
            return null;
        }

        $session = Session::withoutTenancy()
            ->where('tenant_id', $router->tenant_id)
            ->where('router_id', $router->id)
            ->where('voucher_id', $voucher->id)
            ->whereIn('status', ['provisioning', 'active'])
            ->where('pending_revocation', false)
            ->where('pending_device_ban', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->first();

        if (! $session || (int) $voucher->package_id !== (int) $session->package_id) {
            return null;
        }

        $package = Package::withoutTenancy()
            ->where('tenant_id', $router->tenant_id)
            ->find($session->package_id);

        return $package && $this->sessionMayAuthenticate($router, $session, $package, $nasSessionId)
            ? $session
            : null;
    }

    /**
     * Do not turn legacy or agent-managed sessions into RADIUS entitlements.
     * A pending session without an agent command is eligible for the explicit
     * RADIUS path; active sessions need an existing RADIUS generation and
     * available package device capacity.
     */
    private function sessionMayAuthenticate(Router $router, Session $session, Package $package, string $nasSessionId): bool
    {
        if ($session->pending_revocation || $session->pending_device_ban
            || ! $session->expires_at || $session->expires_at->isPast()
            || SiteAgentCommand::query()
                ->where('tenant_id', $router->tenant_id)
                ->where('router_id', $router->id)
                ->where('session_id', $session->id)
                ->exists()) {
            return false;
        }

        if ($session->status === 'provisioning') {
            return true;
        }

        if ($session->status !== 'active') {
            return false;
        }

        $identity = RadiusNasSessionIdentity::query()
            ->where('router_id', $router->id)
            ->where('nas_session_id', $nasSessionId)
            ->whereNotNull('current_generation_id')
            ->first();
        if ($identity && RadiusAccountingSession::query()
            ->where('identity_id', $identity->id)
            ->where('id', $identity->current_generation_id)
            ->where('session_id', $session->id)
            ->where('state', 'open')
            ->exists()) {
            return true;
        }

        $openSessions = RadiusAccountingSession::query()
            ->where('tenant_id', $router->tenant_id)
            ->where('router_id', $router->id)
            ->where('session_id', $session->id)
            ->where('state', 'open')
            ->count();

        return $openSessions > 0 && $openSessions < max(1, (int) $package->device_limit);
    }

    private function voucherMatchesSession(Session $session, string $voucherCode): bool
    {
        return Voucher::withoutTenancy()
            ->where('tenant_id', $session->tenant_id)
            ->where('id', $session->voucher_id)
            ->where('code', $voucherCode)
            ->exists();
    }

    private function newGeneration(
        RadiusNasSessionIdentity $identity,
        Router $router,
        Session $session,
        string $state,
        CarbonImmutable $eventTime,
        int $inputBytes,
        int $outputBytes,
    ): RadiusAccountingSession {
        $generation = (int) $identity->last_generation + 1;
        $accounting = RadiusAccountingSession::create([
            'identity_id' => $identity->id,
            'generation' => $generation,
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'session_id' => $session->id,
            'state' => $state,
            'started_at' => $eventTime,
            'last_accounting_at' => $eventTime,
            'input_bytes' => $inputBytes,
            'output_bytes' => $outputBytes,
        ]);

        $identity->forceFill([
            'last_generation' => $generation,
            'current_generation_id' => $state === 'open' ? $accounting->id : null,
        ])->save();

        return $accounting;
    }

    private function quarantineNewGeneration(
        RadiusNasSessionIdentity $identity,
        Router $router,
        array $input,
        string $fingerprint,
        ?CarbonImmutable $eventTime,
        string $reason,
    ): array {
        $accounting = RadiusAccountingSession::query()
            ->where('identity_id', $identity->id)
            ->where('state', 'quarantined')
            ->orderByDesc('generation')
            ->first();

        if ($accounting) {
            $generation = (int) $accounting->generation;
        } else {
            $generation = (int) $identity->last_generation + 1;
            $accounting = RadiusAccountingSession::create([
                'identity_id' => $identity->id,
                'generation' => $generation,
                'tenant_id' => $router->tenant_id,
                'router_id' => $router->id,
                'session_id' => null,
                'state' => 'quarantined',
                'started_at' => null,
                'last_accounting_at' => null,
                'input_bytes' => 0,
                'output_bytes' => 0,
            ]);
            $identity->forceFill([
                'last_generation' => $generation,
                'current_generation_id' => null,
            ])->save();
        }

        $event = $this->storeEvent($accounting, $input, $fingerprint, $eventTime, true, $reason);

        return [
            'accepted' => false,
            'quarantined' => true,
            'duplicate' => false,
            'generation' => $generation,
            'event_id' => $event->id,
            'reason' => $reason,
        ];
    }

    private function quarantineEvent(
        RadiusAccountingSession $accounting,
        array $input,
        string $fingerprint,
        ?CarbonImmutable $eventTime,
        string $reason,
    ): array {
        $event = $this->storeEvent($accounting, $input, $fingerprint, $eventTime, true, $reason);

        return [
            'accepted' => false,
            'quarantined' => true,
            'duplicate' => false,
            'generation' => $accounting->generation,
            'event_id' => $event->id,
            'reason' => $reason,
        ];
    }

    private function storeEvent(
        RadiusAccountingSession $accounting,
        array $input,
        string $fingerprint,
        ?CarbonImmutable $eventTime,
        bool $quarantined,
        ?string $reason,
    ): RadiusAccountingEvent {
        return RadiusAccountingEvent::create([
            'accounting_session_id' => $accounting->id,
            'fingerprint' => $fingerprint,
            'status_type' => $input['status_type'],
            'event_time' => $eventTime,
            'session_time' => $input['session_time'] ?? null,
            'input_octets' => $input['input_octets'] ?? null,
            'input_gigawords' => $input['input_gigawords'] ?? null,
            'output_octets' => $input['output_octets'] ?? null,
            'output_gigawords' => $input['output_gigawords'] ?? null,
            'termination_cause' => $input['termination_cause'] ?? null,
            'ip_address' => $input['ip_address'] ?? null,
            'mac_address' => $input['mac_address'] ?? null,
            'is_quarantined' => $quarantined,
            'quarantine_reason' => $reason,
            'received_at' => now(),
        ]);
    }

    private function createDisconnectCommand(RadiusAccountingSession $accounting, string $idempotencyKey): RadiusDisconnectCommand
    {
        return RadiusDisconnectCommand::query()->firstOrCreate(
            [
                'accounting_session_id' => $accounting->id,
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'command_id' => (string) Str::uuid(),
                'tenant_id' => $accounting->tenant_id,
                'router_id' => $accounting->router_id,
                'state' => 'pending',
                'attempt_count' => 0,
                'available_at' => now(),
            ],
        );
    }

    private function limitReached(Session $session, int $inputBytes, int $outputBytes): bool
    {
        $package = Package::withoutTenancy()
            ->where('tenant_id', $session->tenant_id)
            ->find($session->package_id);
        if (! $package || ! PlatformSetting::get('package.data_caps', true)) {
            return false;
        }

        try {
            $limit = DataLimit::bytes($package->data_limit);
        } catch (InvalidArgumentException) {
            return true;
        }

        return $limit !== null
            && ($inputBytes >= $limit || $outputBytes >= $limit - $inputBytes);
    }

    private function fingerprint(
        RadiusNasSessionIdentity $identity,
        Router $router,
        array $input,
        string $statusType,
        ?CarbonImmutable $eventTime,
    ): string {
        $canonical = [
            'router_id' => (int) $router->id,
            'identity_id' => (int) $identity->id,
            'nas_session_id' => (string) $identity->nas_session_id,
            'status_type' => $statusType,
            'event_time' => $eventTime?->toIso8601String(),
            'session_time' => $input['session_time'] ?? null,
            'input_octets' => $input['input_octets'] ?? null,
            'input_gigawords' => $input['input_gigawords'] ?? null,
            'output_octets' => $input['output_octets'] ?? null,
            'output_gigawords' => $input['output_gigawords'] ?? null,
            'termination_cause' => $input['termination_cause'] ?? null,
            'ip_address' => $input['ip_address'] ?? null,
            'mac_address' => $input['mac_address'] ?? null,
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    private function eventTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }

    /**
     * @return array{0: int, 1: bool}
     */
    private function counterBytes(mixed $octets, mixed $gigawords, int $currentBytes): array
    {
        if ($octets === null && $gigawords === null) {
            return [$currentBytes, true];
        }

        $octets = (int) ($octets ?? 0);
        $gigawords = (int) ($gigawords ?? 0);
        if ($octets < 0 || $octets > self::MAX_RADIUS_COUNTER
            || $gigawords < 0 || $gigawords > self::MAX_RADIUS_COUNTER
            || $gigawords > intdiv(PHP_INT_MAX - $octets, 4294967296)) {
            return [$currentBytes, false];
        }

        $bytes = $gigawords * 4294967296 + $octets;

        return [$bytes, $bytes >= $currentBytes];
    }
}
