<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $tenants = Tenant::withCount('users')->orderBy('name')->get();

        return view('platform.dashboard', [
            'tenants' => $tenants,
        ]);
    }

    public function impersonate(Request $request, Tenant $tenant, TenantContext $context)
    {
        // Store the impersonated tenant in the session
        session(['impersonate_tenant' => $tenant->id]);
        
        // Set the context immediately for this request
        $context->setTenant($tenant);

        return redirect()->route('operator.dashboard')
            ->with('status', "Now viewing as {$tenant->name}");
    }

    public function stopImpersonating(TenantContext $context)
    {
        session()->forget('impersonate_tenant');
        $context->forget();

        return redirect()->route('platform.dashboard');
    }
}
