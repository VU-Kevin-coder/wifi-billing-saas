<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\Session;
use App\Models\Voucher;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $context)
    {
        $tenant = $context->getTenant();
        $tenantId = $context->getTenantId();
        $userCount = $tenant ? $tenant->users()->count() : 0;
        
        $customerCount = Customer::where('tenant_id', $tenantId)->count();
        $packageCount = Package::where('tenant_id', $tenantId)->count();
        $voucherCount = Voucher::where('tenant_id', $tenantId)->count();

        // Real router metrics for this tenant
        $routers = Router::where('tenant_id', $tenantId)->get();
        $totalRouters = $routers->count();
        $onlineRouters = $routers->where('status', 'online')->count();
        $offlineRouters = $routers->where('status', 'offline')->count();
        $unreachableRouter = $routers->firstWhere('status', 'offline');

        // Active sessions strictly for this tenant
        $activeSessionsQuery = Session::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('expires_at', '>', now());

        $activeSessions = $activeSessionsQuery->count();
        $downloadThroughput = (clone $activeSessionsQuery)->sum('rate_out'); // bps
        $uploadThroughput = (clone $activeSessionsQuery)->sum('rate_in');   // bps

        return view('operator.dashboard', [
            'tenant' => $tenant,
            'userCount' => $userCount,
            'customerCount' => $customerCount,
            'packageCount' => $packageCount,
            'voucherCount' => $voucherCount,
            'totalRouters' => $totalRouters,
            'onlineRouters' => $onlineRouters,
            'offlineRouters' => $offlineRouters,
            'unreachableRouter' => $unreachableRouter,
            'activeSessions' => $activeSessions,
            'downloadThroughput' => $downloadThroughput,
            'uploadThroughput' => $uploadThroughput,
        ]);
    }
}
