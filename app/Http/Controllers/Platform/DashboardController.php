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

        $hasCustomDomain = config('app.tenant_domain') 
            && ! str_contains(config('app.tenant_domain'), 'railway.app') 
            && ! str_contains(config('app.tenant_domain'), 'localhost');

        if ($hasCustomDomain && \Illuminate\Support\Facades\Route::has('operator.sso.verify')) {
            // Generate signed URL to switch into the tenant subdomain as platform admin
            $ssoUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'operator.sso.verify',
                now()->addMinutes(2),
                [
                    'tenant' => $tenant->slug,
                    'user' => $request->user()->id,
                    'impersonate' => 1,
                ]
            );

            return redirect()->to($ssoUrl);
        }

        return redirect()->route('operator.dashboard');
    }

    public function stopImpersonating(TenantContext $context)
    {
        session()->forget('impersonate_tenant');
        $context->forget();

        return redirect()->route('platform.dashboard');
    }
}
