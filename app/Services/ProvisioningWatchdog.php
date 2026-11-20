<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Router;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\ProvisioningStuck;
use App\Services\Policy\Policy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * A customer who has paid must not be left without access in silence.
 * This watches RADIUS sessions that remain in "provisioning": it alerts the
 * operator once and closes sessions whose package expires before activation.
 */
class ProvisioningWatchdog
{
    private const REASONS = [
        'radius_timeout' => 'RADIUS has not confirmed access activation yet.',
        'expired' => 'The package expired before access was ever activated.',
    ];

    public function __construct(
        private readonly RefundService $refunds,
    ) {}

    /**
     * @return string waiting|requeued|recreated|alerted|already_alerted|expired|skipped
     */
    public function inspect(Session $session): string
    {
        $tenant = Tenant::find($session->tenant_id);
        if (! $tenant || $session->status !== 'provisioning') {
            return 'skipped';
        }

        return $this->evaluate($session);
    }

    private function evaluate(Session $session): string
    {
        if ($session->expires_at && $session->expires_at->lte(now())) {
            return $this->giveUp($session);
        }

        $age = $this->minutesSince($session->started_at ?? $session->created_at);
        $alertAfter = (int) Policy::get('provisioning.alert_after_minutes', config('provisioning.alert_after_minutes', 10));

        return $age >= $alertAfter
            ? $this->alert($session, 'radius_timeout', $age)
            : 'waiting';
    }

    /**
     * The package ran out before access was ever active. Close the session,
     * RADIUS itself rejects expired entitlements, so no router-side cleanup is
     * needed when the session never reached the active state.
     */
    private function giveUp(Session $session): string
    {
        $closed = Session::withoutTenancy()
            ->whereKey($session->id)
            ->where('status', 'provisioning')
            ->update(['status' => 'expired']);

        if (! $closed) {
            return 'skipped';
        }

        AuditLog::record('provisioning.expired_unprovisioned', $session, [
            'package_id' => $session->package_id,
        ]);
        $this->requestAutomaticRefund($session, 'package expired before access was provisioned');
        Log::error('Paid session expired without ever being provisioned.', ['session_id' => $session->id]);
        $this->notifyOperators($session, 'expired', $this->minutesSince($session->started_at ?? $session->created_at), true);

        return 'expired';
    }

    /**
     * Alert at most once per session (atomic claim, safe under concurrency).
     */
    private function alert(Session $session, string $reason, int $minutes): string
    {
        $claimed = Session::withoutTenancy()
            ->whereKey($session->id)
            ->whereNull('provisioning_alerted_at')
            ->update(['provisioning_alerted_at' => now()]);

        if (! $claimed) {
            return 'already_alerted';
        }

        Log::error('Paid session is stuck in provisioning.', [
            'session_id' => $session->id,
            'reason' => $reason,
            'minutes' => $minutes,
        ]);
        AuditLog::record('provisioning.stuck', $session, ['reason' => $reason, 'minutes' => $minutes]);
        if ($reason === 'radius_timeout') {
            $this->requestAutomaticRefund($session, $reason);
        }
        $this->notifyOperators($session, $reason, $minutes, false);

        return 'alerted';
    }

    private function requestAutomaticRefund(Session $session, string $failure): void
    {
        if (! PlatformSetting::get('refunds.auto_request', true) || ! $session->voucher_id) {
            return;
        }

        $sale = Transaction::withoutTenancy()
            ->where('tenant_id', $session->tenant_id)
            ->where('type', 'voucher_sale')
            ->where('status', 'completed')
            ->where('meta->voucher_id', $session->voucher_id)
            ->first();

        if (! $sale) {
            Log::warning('Automatic refund request skipped because the provisioning session has no completed sale.', [
                'session_id' => $session->id,
            ]);

            return;
        }

        $amount = $this->refunds->refundable($sale);
        if ($amount <= 0) {
            return;
        }

        try {
            $refund = $this->refunds->request(
                $sale,
                $amount,
                "Automatic request: paid access failed for session #{$session->id} ({$failure}).",
                null,
            );
            AuditLog::record('refund.auto_requested', $refund, [
                'session_id' => $session->id,
                'amount' => $amount,
                'failure' => $failure,
            ]);
        } catch (Throwable $exception) {
            Log::error('Unable to create an automatic refund request after provisioning failure.', [
                'session_id' => $session->id,
                'transaction_id' => $sale->id,
                'exception' => $exception::class,
            ]);
        }
    }

    private function notifyOperators(Session $session, string $reason, int $minutes, bool $expired): void
    {
        if (! PlatformSetting::get('alerts.notify_stuck', true)) {
            return;
        }

        try {
            $admins = User::withoutTenancy()
                ->where('tenant_id', $session->tenant_id)
                ->role('operator_admin')
                ->get();

            if ($admins->isEmpty()) {
                return;
            }

            $package = $session->package_id ? Package::withoutTenancy()->find($session->package_id) : null;
            $router = $session->router_id ? Router::withoutTenancy()->find($session->router_id) : null;

            $url = null;
            try {
                $slug = Tenant::whereKey($session->tenant_id)->value('slug');
                $url = $slug ? route('operator.sessions.index', ['tenant' => $slug]) : null;
            } catch (Throwable) {
                // A missing link must never block the alert.
            }

            Notification::send($admins, new ProvisioningStuck(
                $session->id,
                self::REASONS[$reason] ?? $reason,
                $minutes,
                $package?->name,
                $router?->name,
                $expired,
                $url,
                (int) $session->tenant_id,
            ));
        } catch (Throwable $exception) {
            Log::error('Unable to notify operators about a stuck session.', [
                'session_id' => $session->id,
                'exception' => $exception::class,
            ]);
        }
    }

    private function minutesSince($moment): int
    {
        return $moment ? max(0, (int) floor($moment->diffInMinutes(now()))) : 0;
    }
}
