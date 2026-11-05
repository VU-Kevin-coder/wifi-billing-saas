<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\SsoNonce;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;


class DashboardController extends Controller
{
    public function __invoke()
    {
        // ── 1. Financial KPIs ──────────────────────────────────────────
        $subscriptionRevenue = Tenant::where('subscription_status', 'active')->sum('plan_fee');

        $monthlyCommission = Transaction::withoutTenancy()->where('type', 'voucher_sale')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('platform_fee');

        $weeklyCommission = Transaction::withoutTenancy()->where('type', 'voucher_sale')
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->sum('platform_fee');

        $ytdCommission = Transaction::withoutTenancy()->where('type', 'voucher_sale')
            ->whereYear('created_at', now()->year)
            ->sum('platform_fee');

        $totalRevenueThisMonth = (float) $subscriptionRevenue + (float) $monthlyCommission;

        // ── 2. Growth Percentages ──────────────────────────────────────
        // Last month's total revenue (subscriptions of active tenants created before last month + last month's commissions)
        $lastMonthCommission = Transaction::withoutTenancy()->where('type', 'voucher_sale')
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->sum('platform_fee');

        // For subscription revenue last month we reuse the same active-tenant sum
        // (subscription revenue is recurring, so same pool unless someone cancelled)
        $lastMonthSubscription = Tenant::where('subscription_status', 'active')
            ->where(function ($q) {
                $last = now()->subMonth();
                $q->whereYear('created_at', '<', $last->year)
                  ->orWhere(function ($q2) use ($last) {
                      $q2->whereYear('created_at', $last->year)
                         ->whereMonth('created_at', '<=', $last->month);
                  });
            })
            ->sum('plan_fee');

        $lastMonthTotal = (float) $lastMonthSubscription + (float) $lastMonthCommission;

        $monthlyGrowthPercent = $lastMonthTotal > 0
            ? (($totalRevenueThisMonth - $lastMonthTotal) / $lastMonthTotal) * 100
            : 0;

        // Last week's commission
        $lastWeekCommission = Transaction::withoutTenancy()->where('type', 'voucher_sale')
            ->whereBetween('created_at', [
                now()->subWeek()->startOfWeek(),
                now()->subWeek()->endOfWeek(),
            ])
            ->sum('platform_fee');

        $weeklyGrowthPercent = (float) $lastWeekCommission > 0
            ? (((float) $weeklyCommission - (float) $lastWeekCommission) / (float) $lastWeekCommission) * 100
            : 0;

        // ── 3. Payouts ─────────────────────────────────────────────────
        $totalPayouts = Transaction::withoutTenancy()->where('type', 'withdrawal')
            ->where('status', 'completed')
            ->sum('amount');

        $pendingPayouts = Transaction::withoutTenancy()->where('type', 'withdrawal')
            ->where('status', 'pending')
            ->sum('amount');

        $pendingPayoutsCount = Transaction::withoutTenancy()->where('type', 'withdrawal')
            ->where('status', 'pending')
            ->count();

        // ── 4. Tenant KPIs ─────────────────────────────────────────────
        $totalTenants     = Tenant::count();
        $activeTenants    = Tenant::where('subscription_status', 'active')->count();
        $trialTenants     = Tenant::where('subscription_status', 'trial')->count();
        $suspendedTenants = Tenant::where('status', 'suspended')->count();
        $overdueTenants   = Tenant::where('subscription_status', 'past_due')->count();

        // ── 5. End Customers (across ALL tenants) ──────────────────────
        $totalCustomers = Customer::withoutTenancy()->count();

        // ── 6. Recent Signups ──────────────────────────────────────────
        $recentTenants = Tenant::with('plan')->latest()->take(5)->get();

        // ── 7. Chart Data — Last 6 months ─────────────────────────────
        $chartData = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);

            $subRev = Tenant::where('subscription_status', 'active')
                ->where(function ($q) use ($month) {
                    $q->whereYear('created_at', '<', $month->year)
                      ->orWhere(function ($q2) use ($month) {
                          $q2->whereYear('created_at', $month->year)
                             ->whereMonth('created_at', '<=', $month->month);
                      });
                })
                ->sum('plan_fee');

            $comm = Transaction::withoutTenancy()->where('type', 'voucher_sale')
                ->whereYear('created_at', $month->year)
                ->whereMonth('created_at', $month->month)
                ->sum('platform_fee');

            $chartData[] = [
                'month'         => $month->format('M'),
                'subscriptions' => (float) $subRev,
                'commissions'   => (float) $comm,
            ];
        }

        return view('platform.dashboard', compact(
            'totalRevenueThisMonth', 'monthlyGrowthPercent',
            'weeklyCommission', 'weeklyGrowthPercent',
            'ytdCommission', 'monthlyCommission',
            'totalPayouts', 'pendingPayouts', 'pendingPayoutsCount',
            'totalTenants', 'activeTenants', 'trialTenants', 'suspendedTenants', 'overdueTenants',
            'totalCustomers', 'recentTenants', 'chartData'
        ));
    }

    public function impersonate(Request $request, Tenant $tenant)
    {
        $user = $request->user();
        $token = SsoNonce::issue($user, $tenant, impersonation: true);

        // Generate a signed, single-use URL to switch into the tenant subdomain.
        $ssoUrl = URL::temporarySignedRoute(
            'operator.sso.verify',
            now()->addMinutes(2),
            [
                'tenant' => $tenant->slug,
                'user' => $user->id,
                'token' => $token,
            ]
        );

        return redirect()->to($ssoUrl);
    }

    public function stopImpersonating(Request $request, TenantContext $context)
    {
        $tenant = $context->getTenant();

        if ($tenant) {
            AuditLog::record('tenant.impersonation.stopped', $tenant, [], $request->user(), $tenant);
        }

        session()->forget('impersonate_tenant');
        $context->forget();

        return redirect()->route('platform.dashboard');
    }
}
