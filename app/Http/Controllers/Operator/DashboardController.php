<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Voucher;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $context)
    {
        $tenant = $context->getTenant();
        $userCount = $tenant ? $tenant->users()->count() : 0;
        
        $customerCount = Customer::count();
        $packageCount = Package::count();
        $voucherCount = Voucher::count();
        $activeSessions = Voucher::where('status', 'redeemed')->count();

        return view('operator.dashboard', [
            'tenant' => $tenant,
            'userCount' => $userCount,
            'customerCount' => $customerCount,
            'packageCount' => $packageCount,
            'voucherCount' => $voucherCount,
            'activeSessions' => $activeSessions,
        ]);
    }
}
