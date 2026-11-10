<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\Session;
use App\Models\Voucher;
use App\Services\Tenancy\TenantContext;
use App\Support\ReportingTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $context): View
    {
        $tenant = $context->getTenant();

        abort_unless($tenant, 404, 'Tenant not found');

        $tenantId = $tenant->id;
        $currencies = Package::query()
            ->where('packages.tenant_id', $tenantId)
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency')
            ->all();

        if ($currencies === []) {
            $currencies = ['UGX'];
        }

        $currency = $request->query('currency');
        $currency = in_array($currency, $currencies, true) ? $currency : $currencies[0];

        $todayRevenue = $this->revenueForPeriod($tenantId, $currency, ...ReportingTime::dayRange());
        $weeklyRevenue = $this->revenueForPeriod($tenantId, $currency, ...ReportingTime::weekRange());
        $monthlyRevenue = $this->revenueForPeriod($tenantId, $currency, ...ReportingTime::monthRange());

        $totalCustomers = Customer::query()
            ->where('customers.tenant_id', $tenantId)
            ->count();

        $activeSessionsQuery = Session::query()
            ->where('sessions_log.tenant_id', $tenantId)
            ->where('sessions_log.status', 'active')
            ->where('sessions_log.expires_at', '>', now());

        $activeSessions = (clone $activeSessionsQuery)->count();
        $totalUsers = max($totalCustomers, $activeSessions);
        $offlineCustomers = $totalUsers - $activeSessions;
        $onlinePercentage = $totalUsers > 0
            ? (int) round(($activeSessions / $totalUsers) * 100)
            : 0;
        $dataUsageBytes = (clone $activeSessionsQuery)->sum(DB::raw('sessions_log.bytes_in + sessions_log.bytes_out'));
        $dataUsageGb = $dataUsageBytes / 1_073_741_824;

        $totalRouters = Router::query()
            ->where('routers.tenant_id', $tenantId)
            ->count();
        $onlineRouters = Router::query()
            ->where('routers.tenant_id', $tenantId)
            ->where('routers.status', 'online')
            ->count();

        // Last 7 local days, oldest first. One small indexed query per day keeps the
        // day boundaries exact in the reporting timezone on every database.
        $chartData = collect(range(6, 0))->map(function (int $daysAgo) use ($tenantId, $currency): array {
            $day = ReportingTime::now()->startOfDay()->subDays($daysAgo);
            $totals = $this->redeemedVouchers($tenantId, $currency)
                ->whereBetween('vouchers.redeemed_at', ReportingTime::dayRange($day))
                ->selectRaw('COALESCE(SUM(COALESCE(vouchers.redeemed_price, packages.price)), 0) as revenue, COUNT(vouchers.id) as transactions')
                ->first();

            return [
                'date' => $day->toDateString(),
                'label' => $day->format('D'),
                'revenue' => (float) ($totals->revenue ?? 0),
                'transactions' => (int) ($totals->transactions ?? 0),
            ];
        });

        $recentPackages = Package::query()
            ->where('packages.tenant_id', $tenantId)
            ->latest('packages.created_at')
            ->limit(4)
            ->get();

        return view('operator.dashboard', compact(
            'tenant',
            'currency',
            'currencies',
            'todayRevenue',
            'weeklyRevenue',
            'monthlyRevenue',
            'totalCustomers',
            'totalUsers',
            'activeSessions',
            'offlineCustomers',
            'onlinePercentage',
            'totalRouters',
            'onlineRouters',
            'dataUsageGb',
            'recentPackages',
            'chartData',
        ));
    }

    private function revenueForPeriod(int $tenantId, string $currency, Carbon $start, Carbon $end): float
    {
        return (float) $this->redeemedVouchers($tenantId, $currency)
            ->whereBetween('vouchers.redeemed_at', [$start, $end])
            ->sum(DB::raw('COALESCE(vouchers.redeemed_price, packages.price)'));
    }

    private function redeemedVouchers(int $tenantId, string $currency): Builder
    {
        return Voucher::query()
            ->join('packages', function ($join): void {
                $join->on('vouchers.package_id', '=', 'packages.id')
                    ->on('vouchers.tenant_id', '=', 'packages.tenant_id');
            })
            ->where('vouchers.tenant_id', $tenantId)
            ->where('packages.tenant_id', $tenantId)
            ->where('packages.currency', $currency)
            ->where('vouchers.status', 'redeemed');
    }
}
