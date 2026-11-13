<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Session;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Support\ReportingTime;
use App\Support\SmsTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Your internet expires soon, renew here" - sent to customers whose package is
 * about to end, using the platform's SMS gateway. Each session is reminded at
 * most once (claimed atomically), capped per business per month, and logged.
 */
class ExpiryReminderService
{
    public function __construct(private readonly RenewLink $links) {}

    private const DEFAULT_TEMPLATE = "@company_name: Your internet package expires in @hours_left!\nPackage: @package_name\nExpires: @expiry_date\nRenew now at @portal_url";

    /**
     * @return array{sent: int, failed: int, skipped: int, capped: int}
     */
    public function run(): array
    {
        $counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'capped' => 0];
        if (! PlatformSetting::get('sms.expiry_reminder', false)) {
            return $counts;
        }

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use (&$counts): void {
            $settings = $tenant->settings ?? [];

            if (empty($settings['sms_enabled']) || empty($settings['notify_expiry_sms_reminder'])) {
                return;
            }

            foreach ($this->remindTenant($tenant, $settings) as $outcome => $n) {
                $counts[$outcome] += $n;
            }
        });

        return $counts;
    }

    /**
     * @return array{sent: int, failed: int, skipped: int, capped: int}
     */
    private function remindTenant(Tenant $tenant, array $settings): array
    {
        $counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'capped' => 0];
        $lead = max(1, (int) ($settings['notify_expiry_reminder_mins'] ?? 30));

        $sessions = Session::withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->whereNull('expiry_reminder_sent_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addMinutes($lead))
            ->orderBy('expires_at')
            ->limit(500)
            ->get();

        if ($sessions->isEmpty()) {
            return $counts;
        }

        $phones = Voucher::withoutTenancy()->whereIn('id', $sessions->pluck('voucher_id')->filter())->pluck('redeemed_by', 'id');
        $packages = Package::withoutTenancy()->whereIn('id', $sessions->pluck('package_id')->filter())->get()->keyBy('id');
        $cap = (int) config('sms.monthly_cap_per_tenant', 300);
        $sentThisMonth = $cap > 0 ? $this->sentThisMonth($tenant) : 0;

        foreach ($sessions as $session) {
            $phone = SmsService::normalizePhoneNumber((string) ($phones[$session->voucher_id] ?? ''));

            // No number (printed voucher), or a package so short the reminder would
            // arrive the moment it was bought.
            $duration = $session->started_at ? $session->started_at->diffInMinutes($session->expires_at) : 0;
            if (! $phone || $duration <= $lead + 5) {
                $counts['skipped']++;

                continue;
            }

            if ($cap > 0 && $sentThisMonth >= $cap) {
                $counts['capped']++;

                continue;
            }

            if (! $this->claim($session)) {
                continue; // another run already took it
            }

            $outcome = $this->send($tenant, $settings, $session, $packages[$session->package_id] ?? null, $phone);
            $counts[$outcome]++;
            if ($outcome === 'sent') {
                $sentThisMonth++;
            }
        }

        if ($counts['capped'] > 0) {
            Log::warning('SMS monthly cap reached; expiry reminders withheld.', ['tenant_id' => $tenant->id, 'cap' => $cap]);
        }

        return $counts;
    }

    private function claim(Session $session): bool
    {
        return Session::withoutTenancy()
            ->whereKey($session->id)
            ->whereNull('expiry_reminder_sent_at')
            ->update(['expiry_reminder_sent_at' => now()]) === 1;
    }

    /** @return 'sent'|'failed' */
    private function send(Tenant $tenant, array $settings, Session $session, ?Package $package, string $phone): string
    {
        $token = $this->links->tokenFor($session);
        $message = SmsTemplate::render($settings['template_expiry_reminder'] ?? self::DEFAULT_TEMPLATE, [
            'company_name' => $tenant->name,
            'package_name' => $package?->name ?? 'Internet package',
            'hours_left' => $this->timeLeft($session),
            'expiry_date' => $session->expires_at->copy()->setTimezone(ReportingTime::timezone())->format('d M, H:i'),
            'portal_url' => $this->links->urlFor($tenant, $token),
            'support_number' => (string) ($settings['support_phone'] ?? ''),
        ]);

        try {
            $result = SmsService::send($phone, $message);
        } catch (Throwable $exception) {
            $result = ['success' => false, 'message' => $exception->getMessage()];
        }

        $ok = (bool) ($result['success'] ?? false);

        SmsMessage::withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'session_id' => $session->id,
            'type' => 'expiry_reminder',
            'recipient' => $phone,
            'status' => $ok ? 'sent' : 'failed',
            'segments' => SmsTemplate::segments($message),
            'detail' => Str::limit((string) ($result['message'] ?? ''), 250, ''),
        ]);

        if (! $ok) {
            $failures = SmsMessage::withoutTenancy()->where('session_id', $session->id)->where('status', 'failed')->count();
            if ($failures < (int) config('sms.max_attempts', 3)) {
                // Release the claim: it is tried again on the next run (until it expires).
                Session::withoutTenancy()->whereKey($session->id)->update(['expiry_reminder_sent_at' => null]);
            }
        }

        return $ok ? 'sent' : 'failed';
    }

    private function sentThisMonth(Tenant $tenant): int
    {
        return SmsMessage::withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'sent')
            ->whereBetween('created_at', ReportingTime::monthRange())
            ->count();
    }

    private function timeLeft(Session $session): string
    {
        $minutes = max(1, (int) ceil(now()->diffInMinutes($session->expires_at)));

        return $minutes >= 90
            ? (int) round($minutes / 60).' hours'
            : $minutes.' minute'.($minutes === 1 ? '' : 's');
    }
}
