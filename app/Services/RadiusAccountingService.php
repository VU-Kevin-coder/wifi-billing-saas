<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\RadiusAccountingEvent;
use App\Models\RadiusAccountingSession;
use App\Models\RadiusNasSessionIdentity;
use App\Models\Router;
use App\Models\Session;
use App\Models\Subscriber;
use App\Models\Voucher;
use App\Models\VpnPeer;
use App\Support\DataLimit;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class RadiusAccountingService
{
    private const MAX_RADIUS_COUNTER = 4294967295;

    public function __construct(private readonly RouterCommandService $routerCommands) {}

    /**
     * Resolve policy only through an authenticated NAS identity. The voucher
     * code is used transiently and is never logged or persisted here.
     */
    public function authorize(string $nasIdentifier, string $nasSessionId, string $voucherCode): array
    {
        if (! RadiusReadiness::available()) {
            return ['decision' => 'reject'];
        }

        $router = $this->radiusRouter($nasIdentifier);
        if (! $router) {
            return ['decision' => 'reject'];
        }

        return $this->authorizeForRouter($router, $nasSessionId, $voucherCode);
    }

    public function authorizeForPeer(
        int $routerId,
        string $peerIp,
        string $nasIdentifier,
        string $nasSessionId,
        string $voucherCode,
    ): array {
        if (! RadiusReadiness::available()) {
            return ['decision' => 'reject'];
        }

        $router = $this->routerBoundToPeer($routerId, $peerIp, $nasIdentifier);
        if (! $router) {
            return ['decision' => 'reject'];
        }

        return $this->authorizeForRouter($router, $nasSessionId, $voucherCode);
    }

    public function authorizePppoeForPeer(
        int $routerId,
        string $peerIp,
        string $nasIdentifier,
        string $nasSessionId,
        string $username,
    ): array {
        if (! RadiusReadiness::available()) {
            return ['decision' => 'reject'];
        }

        $router = $this->routerBoundToPeer($routerId, $peerIp, $nasIdentifier);
        if (! $router || ! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $username)) {
            return ['decision' => 'reject'];
        }

        $subscriber = Subscriber::withoutTenancy()
            ->where('tenant_id', $router->tenant_id)
            ->where('username', $username)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->first();
        if (! $subscriber) {
            return ['decision' => 'reject'];
        }

        $package = Package::withoutTenancy()
            ->where('tenant_id', $router->tenant_id)
            ->find($subscriber->package_id);
        if (! $package || ! in_array('PPPoE', (array) $package->features, true)) {
            return ['decision' => 'reject'];
        }

        $dataLimitBytes = null;
        if (PlatformSetting::get('package.data_caps', true)) {
            try {
                $dataLimitBytes = DataLimit::bytes($package->data_limit);
            } catch (InvalidArgumentException) {
                return ['decision' => 'reject'];
            }

            if ($dataLimitBytes !== null && $subscriber->bytes_used >= $dataLimitBytes) {
                return ['decision' => 'reject'];
            }
            if ($dataLimitBytes !== null) {
                $dataLimitBytes -= $subscriber->bytes_used;
            }
        }

        return [
            'decision' => 'accept',
            'cleartext_password' => $subscriber->password,
            'policy' => [
                'session_timeout_seconds' => max(1, (int) now()->diffInSeconds($subscriber->expires_at)),
                'rate_limit_mbps' => max(1, (int) $package->speed_limit_mbps),
                'device_limit' => max(1, (int) $package->device_limit),
                'data_limit_bytes' => $dataLimitBytes,
            ],
        ];
    }

    public function recordPppoeAccountingEvent(array $input): void
    {
        if (! RadiusReadiness::available()) {
            throw new RadiusUnavailableException('RADIUS accounting is temporarily unavailable.');
        }

        $router = $this->routerBoundToPeer(
            (int) ($input['authenticated_router_id'] ?? 0),
            (string) ($input['authenticated_peer_ip'] ?? ''),
            (string) ($input['nas_identifier'] ?? ''),
        );
        $username = (string) ($input['username'] ?? '');
        $statusType = (string) ($input['status_type'] ?? '');
        $nasSessionId = (string) ($input['nas_session_id'] ?? '');

        if (! $router
            || ! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $username)
            || ! in_array($statusType, ['Start', 'Interim-Update', 'Stop'], true)
            || $nasSessionId === '') {
            throw new RadiusUnavailableException('PPPoE accounting identity could not be verified.');
        }

        $inputBytes = $this->radiusCounterBytes($input['input_gigawords'] ?? 0, $input['input_octets'] ?? 0);
        $outputBytes = $this->radiusCounterBytes($input['output_gigawords'] ?? 0, $input['output_octets'] ?? 0);

        $eventTime = $this->eventTime($input['event_time'] ?? null) ?? CarbonImmutable::now('UTC');
        $accountingResult = DB::transaction(function () use (
            $router,
            $username,
            $statusType,
            $nasSessionId,
            $inputBytes,
            $outputBytes,
            $eventTime,
        ): array {
            $subscriber = Subscriber::withoutTenancy()
                ->where('tenant_id', $router->tenant_id)
                ->where('username', $username)
                ->lockForUpdate()
                ->first();
            if (! $subscriber) {
                throw new RadiusUnavailableException('PPPoE subscriber accounting identity was not found.');
            }

            $session = DB::table('radius_subscriber_sessions')
                ->where('tenant_id', $router->tenant_id)
                ->where('router_id', $router->id)
                ->where('nas_session_id', $nasSessionId)
                ->orderByDesc('generation')
                ->lockForUpdate()
                ->first();

            if ($statusType === 'Start' && (! $session || $session->state === 'closed')) {
                $generation = $session ? (int) $session->generation + 1 : 1;
                $id = DB::table('radius_subscriber_sessions')->insertGetId([
                    'tenant_id' => $router->tenant_id,
                    'router_id' => $router->id,
                    'subscriber_id' => $subscriber->id,
                    'nas_session_id' => $nasSessionId,
                    'generation' => $generation,
                    'input_bytes' => 0,
                    'output_bytes' => 0,
                    'state' => 'open',
                    'started_at' => $eventTime,
                    'last_seen_at' => $eventTime,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $session = DB::table('radius_subscriber_sessions')->where('id', $id)->first();
            } elseif (! $session) {
                $id = DB::table('radius_subscriber_sessions')->insertGetId([
                    'tenant_id' => $router->tenant_id,
                    'router_id' => $router->id,
                    'subscriber_id' => $subscriber->id,
                    'nas_session_id' => $nasSessionId,
                    'generation' => 1,
                    'input_bytes' => 0,
                    'output_bytes' => 0,
                    'state' => 'open',
                    'started_at' => $eventTime,
                    'last_seen_at' => $eventTime,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $session = DB::table('radius_subscriber_sessions')->where('id', $id)->first();
            } elseif ($session->state === 'closed') {
                return ['closed_session_id' => null, 'sessions_to_kick' => []];
            } elseif ((int) $session->subscriber_id !== (int) $subscriber->id) {
                throw new RadiusUnavailableException('PPPoE accounting session does not match the subscriber identity.');
            }

            $previousInput = (int) $session->input_bytes;
            $previousOutput = (int) $session->output_bytes;
            $currentInput = max($previousInput, $inputBytes);
            $currentOutput = max($previousOutput, $outputBytes);
            $inputDelta = $currentInput - $previousInput;
            $outputDelta = $currentOutput - $previousOutput;
            $delta = $outputDelta > PHP_INT_MAX - $inputDelta
                ? PHP_INT_MAX
                : $inputDelta + $outputDelta;
            $currentBytesUsed = (int) $subscriber->bytes_used;
            $bytesUsed = $delta > PHP_INT_MAX - $currentBytesUsed
                    ? PHP_INT_MAX
                    : $currentBytesUsed + $delta;
            $subscriber->forceFill(['bytes_used' => $bytesUsed])->saveOrFail();

            DB::table('radius_subscriber_sessions')->where('id', $session->id)->update([
                'input_bytes' => $currentInput,
                'output_bytes' => $currentOutput,
                'state' => $statusType === 'Stop' ? 'closed' : 'open',
                'last_seen_at' => $eventTime,
                'stopped_at' => $statusType === 'Stop' ? $eventTime : null,
                'updated_at' => $eventTime,
            ]);

            $package = Package::withoutTenancy()
                ->where('tenant_id', $router->tenant_id)
                ->find($subscriber->package_id);
            $quotaReached = $this->subscriberDataLimitReached($subscriber, $package, $bytesUsed);
            $timeExpired = ! $subscriber->expires_at
                || $eventTime->greaterThanOrEqualTo($subscriber->expires_at)
                || now()->greaterThanOrEqualTo($subscriber->expires_at);
            $revoked = $subscriber->status !== 'active';
            $mustDisconnect = $revoked || $timeExpired || $quotaReached;

            if (! $mustDisconnect) {
                return [
                    'closed_session_id' => $statusType === 'Stop' ? (int) $session->id : null,
                    'sessions_to_kick' => [],
                ];
            }

            return [
                'closed_session_id' => $statusType === 'Stop' ? (int) $session->id : null,
                'sessions_to_kick' => DB::table('radius_subscriber_sessions')
                    ->where('tenant_id', $router->tenant_id)
                    ->where('router_id', $router->id)
                    ->where('subscriber_id', $subscriber->id)
                    ->where('state', 'open')
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
            ];
        });

        if ($accountingResult['closed_session_id'] !== null) {
            $this->routerCommands->cancelPendingForPppoeSession($accountingResult['closed_session_id']);
        }

        foreach ($accountingResult['sessions_to_kick'] as $subscriberSessionId) {
            $this->routerCommands->requestPppoeKick(
                $subscriberSessionId,
                'policy:pppoe-session:'.$subscriberSessionId,
            );
        }
    }

    private function subscriberDataLimitReached(Subscriber $subscriber, ?Package $package, int $bytesUsed): bool
    {
        if (! $package || ! PlatformSetting::get('package.data_caps', true)) {
            return false;
        }

        try {
            $limit = DataLimit::bytes($package->data_limit);
        } catch (InvalidArgumentException) {
            return true;
        }

        return $limit !== null && $bytesUsed >= $limit;
    }

    private function radiusCounterBytes(mixed $gigawords, mixed $octets): int
    {
        $gigawords = is_numeric($gigawords) ? max(0, (int) $gigawords) : 0;
        $octets = is_numeric($octets) ? max(0, (int) $octets) : 0;
        $base = 4294967296;

        if ($gigawords > intdiv(PHP_INT_MAX - min($octets, $base - 1), $base)) {
            return PHP_INT_MAX;
        }

        return $gigawords * $base + min($octets, $base - 1);
    }

    public function routerBoundToPeer(int $routerId, string $peerIp, string $nasIdentifier): ?Router
    {
        if (filter_var($peerIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || $nasIdentifier === '' || strlen($nasIdentifier) > 255) {
            return null;
        }

        $router = Router::withoutTenancy()
            ->whereKey($routerId)
            ->where(function ($query): void {
                $query->where('access_backend', 'radius')
                    ->orWhere(function ($pending): void {
                        $pending->where('pending_radius_activation', true)
                            ->whereNull('radius_activation_paused_at');
                    });
            })
            ->where('radius_nas_identifier', $nasIdentifier)
            ->whereNotNull('radius_shared_secret')
            ->first();

        if (! $router || ! $router->hasUsableRadiusConfiguration()) {
            return null;
        }

        return VpnPeer::withoutGlobalScopes()
            ->where('tenant_id', $router->tenant_id)
            ->where('router_id', $router->id)
            ->where('tunnel_ip', $peerIp)
            ->where('enabled', true)
            ->exists()
            ? $router
            : null;
    }

    public function radiusRouterForPeerIp(string $peerIp): ?Router
    {
        if (filter_var($peerIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        $peer = VpnPeer::withoutGlobalScopes()
            ->where('tunnel_ip', $peerIp)
            ->where('enabled', true)
            ->first();
        if (! $peer) {
            return null;
        }

        $router = Router::withoutTenancy()
            ->whereKey($peer->router_id)
            ->where('tenant_id', $peer->tenant_id)
            ->where(function ($query): void {
                $query->where('access_backend', 'radius')
                    ->orWhere(function ($pending): void {
                        $pending->where('pending_radius_activation', true)
                            ->whereNull('radius_activation_paused_at');
                    });
            })
            ->first();

        return $router && $router->hasUsableRadiusConfiguration()
            ? $router
            : null;
    }

    /**
     * Process an actual FreeRADIUS post-auth event, never an authorization API decision.
     */
    public function recordPostAuthEvent(array $input): void
    {
        $routerId = (int) ($input['authenticated_router_id'] ?? 0);
        $peerIp = (string) ($input['authenticated_peer_ip'] ?? '');
        $nasIdentifier = (string) ($input['nas_identifier'] ?? '');
        $outcome = (string) ($input['outcome'] ?? '');
        $router = $this->routerBoundToPeer($routerId, $peerIp, $nasIdentifier);

        if (! $router || ! in_array($outcome, ['accept', 'reject'], true)) {
            throw new RadiusUnavailableException('RADIUS authentication event identity could not be verified.');
        }

        $paused = DB::transaction(function () use ($router, $outcome): bool {
            $lockedRouter = Router::withoutTenancy()
                ->whereKey($router->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedRouter || ! $lockedRouter->pending_radius_activation) {
                return false;
            }

            if ($outcome === 'accept') {
                $lockedRouter->forceFill([
                    'access_backend' => 'radius',
                    'pending_radius_activation' => false,
                    'radius_access_reject_streak' => 0,
                    'radius_activation_paused_at' => null,
                ])->saveOrFail();

                return false;
            }

            if ($lockedRouter->radius_activation_paused_at !== null) {
                return false;
            }

            $rejectStreak = min(3, (int) $lockedRouter->radius_access_reject_streak + 1);
            $lockedRouter->forceFill([
                'radius_access_reject_streak' => $rejectStreak,
                'radius_activation_paused_at' => $rejectStreak >= 3 ? now() : null,
            ])->saveOrFail();

            return $rejectStreak >= 3;
        });

        if ($paused) {
            $router->loadMissing('tenant');
            app(ActivityNotifier::class)->platformAdmins(
                'Router RADIUS activation paused',
                "RADIUS activation for {$router->name} in {$router->tenant?->name} was paused after three consecutive Access-Reject events. The existing access backend remains active.",
                '/platform/tenants/'.$router->tenant_id,
            );
        }
    }

    public function recordLabAccountingObservation(array $input): void
    {
        if (! RadiusReadiness::available() || ! (bool) config('radius.lab_accounting_log', false)) {
            throw new RadiusUnavailableException('RADIUS accounting observation is unavailable.');
        }

        $router = $this->routerBoundToPeer(
            (int) ($input['authenticated_router_id'] ?? 0),
            (string) ($input['authenticated_peer_ip'] ?? ''),
            (string) ($input['nas_identifier'] ?? ''),
        );
        if (! $router) {
            throw new RadiusUnavailableException('RADIUS accounting router identity could not be verified.');
        }

        $sessionId = (string) ($input['nas_session_id'] ?? '');
        Log::channel('radius')->info('RADIUS accounting observation', [
            'router_id' => $router->id,
            'tenant_id' => $router->tenant_id,
            'session_fingerprint' => hash_hmac('sha256', $sessionId, (string) config('app.key')),
            'status_type' => $input['status_type'],
            'event_time' => $input['event_time'] ?? null,
            'session_time' => $input['session_time'] ?? null,
            'input_octets' => $input['input_octets'] ?? null,
            'input_gigawords' => $input['input_gigawords'] ?? null,
            'output_octets' => $input['output_octets'] ?? null,
            'output_gigawords' => $input['output_gigawords'] ?? null,
            'termination_cause' => $input['termination_cause'] ?? null,
        ]);
    }

    private function authorizeForRouter(Router $router, string $nasSessionId, string $voucherCode): array
    {
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
        if (! RadiusReadiness::available() || ! config('radius_policy.accounting_reconciliation_enabled', false)) {
            return ['accepted' => false, 'quarantined' => true, 'reason' => 'radius_unavailable'];
        }

        $statusType = (string) ($input['status_type'] ?? '');
        $nasSessionId = (string) ($input['nas_session_id'] ?? '');
        if (! in_array($statusType, ['Start', 'Interim-Update', 'Stop'], true) || $nasSessionId === '') {
            return ['accepted' => false, 'quarantined' => true, 'reason' => 'invalid_accounting_identity'];
        }

        $router = $this->radiusRouter((string) ($input['nas_identifier'] ?? ''));
        if (! $router) {
            return [
                'accepted' => false,
                'quarantined' => true,
                'reason' => 'unknown_router',
            ];
        }

        $eventTime = $this->eventTime($input['event_time'] ?? null);

        return DB::transaction(function () use ($router, $input, $statusType, $nasSessionId, $eventTime): array {
            $identity = RadiusNasSessionIdentity::query()->firstOrCreate(
                [
                    'router_id' => $router->id,
                    'nas_session_id' => $nasSessionId,
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

            if ($current
                && ! empty($current->acct_unique_session_id)
                && ! empty($input['acct_unique_session_id'])
                && ! hash_equals((string) $current->acct_unique_session_id, (string) $input['acct_unique_session_id'])) {
                return $this->quarantineEvent($current, $input, $fingerprint, $eventTime, 'unique_session_mismatch');
            }

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
                    0,
                );
                [$outputBytes, $outputValid] = $this->counterBytes(
                    $input['output_octets'] ?? null,
                    $input['output_gigawords'] ?? null,
                    0,
                );
                if (! $inputValid || ! $outputValid) {
                    return $this->quarantineNewGeneration($identity, $router, $input, $fingerprint, $eventTime, 'invalid_start_counters');
                }

                $accounting = $this->newGeneration($identity, $router, $session, 'open', $eventTime, $inputBytes, $outputBytes);
                $accounting->forceFill([
                    'acct_unique_session_id' => $input['acct_unique_session_id'] ?? null,
                    'session_time_seconds' => max(0, (int) ($input['session_time'] ?? 0)),
                ])->save();
                $this->storeEvent($accounting, $input, $fingerprint, $eventTime, false, null);
                $usage = $this->aggregateSessionUsage($session);
                $sessionChanges = [
                    'status' => 'active',
                    'bytes_in' => $usage['input_bytes'],
                    'bytes_out' => $usage['output_bytes'],
                    'session_duration_seconds' => $usage['session_duration_seconds'],
                    'accounting_stopped_at' => null,
                ];
                if (! empty($input['ip_address'])) {
                    $sessionChanges['ip_address'] = $input['ip_address'];
                }
                if (! empty($input['mac_address'])) {
                    $sessionChanges['mac_address'] = $input['mac_address'];
                }
                $disconnectReason = $this->disconnectReason($session, $usage, $eventTime);
                if ($disconnectReason !== null || $session->pending_device_ban) {
                    $sessionChanges['status'] = 'disconnecting';
                }
                if ($disconnectReason !== null) {
                    $sessionChanges['pending_revocation'] = true;
                    $sessionChanges['pending_revocation_status'] = $disconnectReason;
                }
                $session->update($sessionChanges);
                if ($disconnectReason !== null || $session->pending_device_ban) {
                    $this->routerCommands->recordKick($session, 'acct-kick:'.$accounting->id, true);
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

            if (! empty($current->acct_unique_session_id)
                && ! empty($input['acct_unique_session_id'])
                && ! hash_equals((string) $current->acct_unique_session_id, (string) $input['acct_unique_session_id'])) {
                return $this->quarantineEvent($current, $input, $fingerprint, $eventTime, 'unique_session_mismatch');
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
                (int) $current->input_bytes,
            );
            [$outputBytes, $outputValid] = $this->counterBytes(
                $input['output_octets'] ?? null,
                $input['output_gigawords'] ?? null,
                (int) $current->output_bytes,
            );
            if (! $inputValid || ! $outputValid) {
                return $this->quarantineEvent($current, $input, $fingerprint, $eventTime, 'counter_regression_or_overflow');
            }

            $this->storeEvent($current, $input, $fingerprint, $eventTime, false, null);
            $sessionTime = max(
                (int) $current->session_time_seconds,
                (int) ($input['session_time'] ?? 0),
            );
            $current->forceFill([
                'last_accounting_at' => $eventTime,
                'input_bytes' => $inputBytes,
                'output_bytes' => $outputBytes,
                'session_time_seconds' => $sessionTime,
                'acct_unique_session_id' => $current->acct_unique_session_id
                    ?: ($input['acct_unique_session_id'] ?? null),
                'state' => $statusType === 'Stop' ? 'stopped' : 'open',
                'stopped_at' => $statusType === 'Stop' ? $eventTime : null,
            ])->save();

            $usage = $this->aggregateSessionUsage($session);
            $hasOpenGenerations = RadiusAccountingSession::query()
                ->where('session_id', $session->id)
                ->where('state', 'open')
                ->exists();
            $sessionChanges = [
                'bytes_in' => $usage['input_bytes'],
                'bytes_out' => $usage['output_bytes'],
                'session_duration_seconds' => $usage['session_duration_seconds'],
            ];
            if (! empty($input['ip_address'])) {
                $sessionChanges['ip_address'] = $input['ip_address'];
            }
            if (! empty($input['mac_address'])) {
                $sessionChanges['mac_address'] = $input['mac_address'];
            }

            if ($statusType === 'Interim-Update') {
                $sessionChanges['last_interim_at'] = $eventTime;
            }

            if ($statusType === 'Stop' && ! $hasOpenGenerations) {
                $sessionChanges['status'] = $session->pending_revocation
                    ? ($session->pending_revocation_status ?: 'disconnected')
                    : 'disconnected';
                $sessionChanges['pending_revocation'] = false;
                $sessionChanges['pending_revocation_status'] = null;
                $sessionChanges['accounting_stopped_at'] = $eventTime;
                $identity->forceFill(['current_generation_id' => null])->save();
            } else {
                $disconnectReason = $this->disconnectReason($session, $usage, $eventTime);
                if ($disconnectReason !== null || $session->pending_device_ban) {
                    $sessionChanges['status'] = 'disconnecting';
                }
                if ($disconnectReason !== null) {
                    $sessionChanges['pending_revocation'] = true;
                    $sessionChanges['pending_revocation_status'] = $disconnectReason;
                }
            }

            $session->update($sessionChanges);

            if ($statusType === 'Stop' && ! $hasOpenGenerations) {
                $this->routerCommands->cancelPendingForSession((int) $session->id);
            } elseif ($session->pending_revocation || $session->pending_device_ban) {
                $this->routerCommands->recordKick($session, 'acct-kick:'.$current->id, true);
            }

            return [
                'accepted' => true,
                'quarantined' => false,
                'duplicate' => false,
                'generation' => $current->generation,
            ];
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
     * Pending RADIUS sessions may authenticate; active sessions need an
     * existing RADIUS generation and available package device capacity.
     */
    private function sessionMayAuthenticate(Router $router, Session $session, Package $package, string $nasSessionId): bool
    {
        if ($session->pending_revocation || $session->pending_device_ban
            || ! $session->expires_at || $session->expires_at->isPast()) {
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
            'acct_unique_session_id' => $input['acct_unique_session_id'] ?? null,
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

    /**
     * @return array{input_bytes: int, output_bytes: int, session_duration_seconds: int}
     */
    private function aggregateSessionUsage(Session $session): array
    {
        $usage = [
            'input_bytes' => 0,
            'output_bytes' => 0,
            'session_duration_seconds' => 0,
        ];
        $accountingSessions = RadiusAccountingSession::query()
            ->where('tenant_id', $session->tenant_id)
            ->where('router_id', $session->router_id)
            ->where('session_id', $session->id)
            ->get(['input_bytes', 'output_bytes', 'session_time_seconds']);

        foreach ($accountingSessions as $accountingSession) {
            $usage['input_bytes'] = $this->saturatedAdd(
                $usage['input_bytes'],
                (int) $accountingSession->input_bytes,
            );
            $usage['output_bytes'] = $this->saturatedAdd(
                $usage['output_bytes'],
                (int) $accountingSession->output_bytes,
            );
            $usage['session_duration_seconds'] = $this->saturatedAdd(
                $usage['session_duration_seconds'],
                (int) $accountingSession->session_time_seconds,
            );
        }

        return $usage;
    }

    private function saturatedAdd(int $total, int $addition): int
    {
        return $addition > PHP_INT_MAX - $total ? PHP_INT_MAX : $total + $addition;
    }

    /**
     * Return the terminal session state to record when an open generation
     * must be disconnected, or null when no policy limit has been reached.
     */
    private function disconnectReason(Session $session, array $usage, CarbonImmutable $eventTime): ?string
    {
        if ($session->pending_revocation) {
            return $session->pending_revocation_status ?: 'disconnected';
        }

        if ($session->expires_at
            && ($eventTime->greaterThanOrEqualTo($session->expires_at)
                || now()->greaterThanOrEqualTo($session->expires_at))) {
            return 'expired';
        }

        return $this->limitReached($session, $usage['input_bytes'], $usage['output_bytes'])
            ? 'expired'
            : null;
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
            'acct_unique_session_id' => $input['acct_unique_session_id'] ?? null,
            'event_time' => $eventTime?->utc()->toIso8601String(),
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

        try {
            return CarbonImmutable::parse($value)
                ->setTimezone((string) config('app.timezone', 'UTC'));
        } catch (InvalidFormatException) {
            return null;
        }
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
