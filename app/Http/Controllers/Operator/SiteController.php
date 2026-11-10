<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Site;
use App\Services\Tenancy\TenantContext;
use App\Support\ReportingTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteController extends Controller
{
    public function index(Request $request, TenantContext $context)
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
        $siteMetrics = $this->siteMetrics($tenantId, $currency);
        $sitesQuery = Site::query()
            ->where('sites.tenant_id', $tenantId)
            ->with('routers');

        if ($search = trim((string) $request->query('search', ''))) {
            $sitesQuery->where(function (Builder $query) use ($search): void {
                $query->where('sites.name', 'like', "%{$search}%")
                    ->orWhere('sites.location', 'like', "%{$search}%");
            });
        }

        $sites = $sitesQuery->orderBy('sites.name')->get();

        foreach ($sites as $site) {
            $routers = $site->routers;
            $site->setAttribute('routers_online_count', $routers->where('status', 'online')->count());
            $site->setAttribute('live_connections_count', (int) ($siteMetrics['sessions'][$site->id]->live_connections ?? 0));
            $site->setAttribute('customers_covered_count', (int) ($siteMetrics['sessions'][$site->id]->customers_covered ?? 0));
            $site->setAttribute('monthly_revenue', (float) ($siteMetrics['revenue'][$site->id]->revenue ?? 0));
            $site->setAttribute(
                'health_status',
                $this->healthStatus($routers)
            );
        }

        $statusFilter = $request->query('status', 'all');
        if (in_array($statusFilter, ['healthy', 'degraded', 'offline', 'unassigned'], true)) {
            $sites = $sites->filter(fn (Site $site) => $site->health_status === $statusFilter)->values();
        } else {
            $statusFilter = 'all';
        }

        $allSites = Site::query()
            ->where('sites.tenant_id', $tenantId)
            ->with('routers')
            ->get();
        $operatingSites = $allSites->filter(fn (Site $site) => $site->routers->contains('status', 'online'))->count();
        $sitesWithAlerts = $allSites->filter(function (Site $site): bool {
            return $site->routers->contains('status', 'offline');
        })->count();

        $customersCovered = DB::table('sessions_log')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('site_id')
            ->whereNotNull('customer_id')
            ->distinct()
            ->count('customer_id');

        $liveConnections = DB::table('sessions_log')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->count();

        return view('operator.sites.index', compact(
            'tenant',
            'sites',
            'operatingSites',
            'customersCovered',
            'liveConnections',
            'sitesWithAlerts',
            'currencies',
            'currency',
            'statusFilter',
        ));
    }

    public function store(Request $request, TenantContext $context)
    {
        $tenant = $context->getTenant();
        abort_unless($tenant, 404, 'Tenant not found');

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('sites', 'name')->where('tenant_id', $tenant->id),
            ],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $tenant->sites()->create($validated);

        return redirect()->route('operator.sites.index', ['tenant' => $tenant->slug])
            ->with('status', 'Site created successfully.');
    }

    public function export(Request $request, TenantContext $context): StreamedResponse
    {
        $tenant = $context->getTenant();
        abort_unless($tenant, 404, 'Tenant not found');

        $currency = $request->query('currency');
        $currencies = Package::query()
            ->where('packages.tenant_id', $tenant->id)
            ->distinct()
            ->pluck('currency')
            ->all();
        $currency = in_array($currency, $currencies, true) ? $currency : ($currencies[0] ?? 'UGX');

        $metrics = $this->siteMetrics($tenant->id, $currency);
        $sites = Site::query()->where('sites.tenant_id', $tenant->id)->with('routers')->orderBy('name')->get();
        $filename = 'sites-'.$tenant->slug.'-'.now()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($sites, $metrics, $currency): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Site', 'Location', 'Status', 'Routers online', 'Total routers', 'Customers covered', 'Live connections', 'Month revenue', 'Currency']);

            foreach ($sites as $site) {
                $routers = $site->routers;
                fputcsv($output, [
                    $site->name,
                    $site->location,
                    $this->healthStatus($routers),
                    $routers->where('status', 'online')->count(),
                    $routers->count(),
                    $metrics['sessions'][$site->id]->customers_covered ?? 0,
                    $metrics['sessions'][$site->id]->live_connections ?? 0,
                    $metrics['revenue'][$site->id]->revenue ?? 0,
                    $currency,
                ]);
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{sessions: Collection, revenue: Collection}
     */
    private function siteMetrics(int $tenantId, string $currency): array
    {
        $sessions = DB::table('sessions_log')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('site_id')
            ->select('site_id')
            ->selectRaw('COUNT(DISTINCT customer_id) as customers_covered')
            ->selectRaw(
                'SUM(CASE WHEN status = ? AND expires_at > ? THEN 1 ELSE 0 END) as live_connections',
                ['active', now()]
            )
            ->groupBy('site_id')
            ->get()
            ->keyBy('site_id');

        $revenue = DB::table('vouchers')
            ->join('packages', function ($join): void {
                $join->on('vouchers.package_id', '=', 'packages.id')
                    ->on('vouchers.tenant_id', '=', 'packages.tenant_id');
            })
            ->where('vouchers.tenant_id', $tenantId)
            ->where('packages.tenant_id', $tenantId)
            ->where('packages.currency', $currency)
            ->where('vouchers.status', 'redeemed')
            ->whereNotNull('vouchers.site_id')
            ->whereBetween('vouchers.redeemed_at', ReportingTime::monthRange())
            ->select('vouchers.site_id')
            ->selectRaw('SUM(COALESCE(vouchers.redeemed_price, packages.price)) as revenue')
            ->groupBy('vouchers.site_id')
            ->get()
            ->keyBy('site_id');

        return compact('sessions', 'revenue');
    }

    private function healthStatus(Collection $routers): string
    {
        if ($routers->isEmpty()) {
            return 'unassigned';
        }

        $onlineRouterCount = $routers->where('status', 'online')->count();
        if ($onlineRouterCount === $routers->count()) {
            return 'healthy';
        }

        if ($onlineRouterCount === 0 && $routers->every(fn ($router) => $router->status === 'offline')) {
            return 'offline';
        }

        return 'degraded';
    }
}
