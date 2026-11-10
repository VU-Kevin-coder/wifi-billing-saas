<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\SmsMessage;
use App\Models\Voucher;
use App\Services\Tenancy\TenantContext;
use App\Support\ReportingTime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sales analytics for one business, in the operator's own timezone.
 * Revenue is recognised at redemption (same definition as the dashboard).
 */
class AnalyticsController extends Controller
{
    private const PERIODS = [7, 30, 90];

    public function index(Request $request, TenantContext $context)
    {
        $tenant = $context->getTenant();
        abort_unless($tenant, 404, 'Tenant not found');

        $days = in_array((int) $request->query('days'), self::PERIODS, true) ? (int) $request->query('days') : 30;
        $timezone = ReportingTime::timezone();
        $storage = (string) config('app.timezone', 'UTC');

        $today = ReportingTime::now()->startOfDay();
        $firstDay = $today->copy()->subDays($days - 1);
        [$from] = ReportingTime::dayRange($firstDay);
        [, $to] = ReportingTime::dayRange($today);
        // Redemptions up to a day after the period count toward "did a reminder bring them back?".
        $lookahead = $to->copy()->addDay();

        $daily = [];
        for ($d = $firstDay->copy(); $d->lte($today); $d->addDay()) {
            $daily[$d->toDateString()] = ['label' => $d->format('d M'), 'revenue' => 0.0, 'count' => 0];
        }
        $hours = array_fill(0, 24, 0);
        $packages = [];
        $buyers = [];           // phone => redemptions in the period
        $byPhone = [];          // last 9 digits => [local timestamps] (includes the lookahead day)
        $revenue = 0.0;
        $count = 0;

        $rows = Voucher::query()
            ->join('packages', 'packages.id', '=', 'vouchers.package_id')
            ->where('vouchers.tenant_id', $tenant->id)
            ->where('vouchers.status', 'redeemed')
            ->whereBetween('vouchers.redeemed_at', [$from, $lookahead])
            ->select('vouchers.redeemed_at', 'vouchers.redeemed_by', 'packages.name as package_name')
            ->selectRaw('COALESCE(vouchers.redeemed_price, packages.price) as price')
            ->cursor();

        foreach ($rows as $row) {
            $at = Carbon::parse($row->redeemed_at, $storage)->setTimezone($timezone);
            $phone = $this->digits((string) $row->redeemed_by);

            if ($phone !== '') {
                $byPhone[$phone][] = $at->getTimestamp();
            }
            if ($at->getTimestamp() > $to->getTimestamp()) {
                continue; // lookahead only
            }

            $price = (float) $row->price;
            $revenue += $price;
            $count++;
            $key = $at->toDateString();
            if (isset($daily[$key])) {
                $daily[$key]['revenue'] += $price;
                $daily[$key]['count']++;
            }
            $hours[(int) $at->format('G')]++;
            $packages[$row->package_name]['count'] = ($packages[$row->package_name]['count'] ?? 0) + 1;
            $packages[$row->package_name]['revenue'] = ($packages[$row->package_name]['revenue'] ?? 0.0) + $price;
            if ($phone !== '') {
                $buyers[$phone] = ($buyers[$phone] ?? 0) + 1;
            }
        }

        uasort($packages, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        $repeatBuyers = count(array_filter($buyers, fn (int $n) => $n >= 2));

        return view('operator.analytics.index', [
            'days' => $days,
            'periods' => self::PERIODS,
            'currency' => PlatformSetting::get('currency', 'UGX'),
            'revenue' => $revenue,
            'count' => $count,
            'customers' => count($buyers),
            'repeatBuyers' => $repeatBuyers,
            'repeatRate' => count($buyers) > 0 ? round($repeatBuyers / count($buyers) * 100) : 0,
            'daily' => array_values($daily),
            'maxDaily' => max(1.0, ...array_column($daily, 'revenue')),
            'hours' => $hours,
            'maxHour' => max(1, ...$hours),
            'packages' => array_slice($packages, 0, 8, true),
            'reminders' => $this->reminderResults($tenant->id, $from, $to, $byPhone, $timezone, $storage),
        ]);
    }

    /**
     * @param  array<string, list<int>>  $byPhone
     * @return array{sent: int, returned: int, rate: int}
     */
    private function reminderResults(int $tenantId, Carbon $from, Carbon $to, array $byPhone, string $timezone, string $storage): array
    {
        $sent = 0;
        $returned = 0;

        SmsMessage::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'expiry_reminder')
            ->where('status', 'sent')
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('id')
            ->select('recipient', 'created_at')
            ->cursor()
            ->each(function ($message) use (&$sent, &$returned, $byPhone, $storage): void {
                $sent++;
                $at = Carbon::parse($message->created_at, $storage)->getTimestamp();

                foreach ($byPhone[$this->digits($message->recipient)] ?? [] as $redeemedAt) {
                    if ($redeemedAt > $at && $redeemedAt <= $at + 86400) {
                        $returned++;
                        break;
                    }
                }
            });

        return ['sent' => $sent, 'returned' => $returned, 'rate' => $sent > 0 ? (int) round($returned / $sent * 100) : 0];
    }

    /** Last 9 digits: 0771234567, +256771234567 and 256771234567 are one number. */
    private function digits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        return strlen($digits) >= 9 ? substr($digits, -9) : '';
    }
}
