<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

class PortalController extends Controller
{
    public function index(Request $request, TenantContext $context, ?string $tenant = null)
    {
        $tenantModel = $context->getTenant() ?? ($tenant ? Tenant::where('slug', $tenant)->first() : null);

        // If no tenant matches the subdomain, 404
        if (!$tenantModel) {
            abort(404);
        }

        // Fetch active packages for the "Buy" section (scoped to tenant)
        $packages = Package::where('is_published', true)->orderBy('price')->get();

        return view('portal.index', [
            'tenant' => $tenantModel,
            'packages' => $packages,
        ]);
    }

    public function redeem(Request $request, TenantContext $context, ?string $tenant = null)
    {
        $tenantModel = $context->getTenant() ?? ($tenant ? Tenant::where('slug', $tenant)->first() : null);

        if (!$tenantModel) {
            abort(404);
        }

        $request->validate([
            'voucher_code' => ['required', 'string'],
        ]);

        $code = strtoupper(trim($request->voucher_code));

        // Find the voucher specifically within this tenant
        $voucher = Voucher::where('code', $code)->first();

        if (!$voucher) {
            return back()->withErrors(['voucher_code' => 'Invalid voucher code. Please check and try again.']);
        }

        if ($voucher->status === 'redeemed') {
            return back()->withErrors(['voucher_code' => 'This voucher has already been used.']);
        }

        if ($voucher->status === 'voided') {
            return back()->withErrors(['voucher_code' => 'This voucher is no longer valid.']);
        }

        // Mark as redeemed
        $voucher->update([
            'status' => 'redeemed',
            'redeemed_at' => now(),
            'redeemed_by' => 'Wi-Fi Guest', // In the future, we can prompt for a phone number
        ]);

        // REDIRECT TO MIKROTIK (Placeholder for now)
        // In production, this URL will be the MikroTik login endpoint.
        $mikrotikLoginUrl = "http://10.5.50.1/login?username={$voucher->code}&password={$voucher->code}";

        return redirect()->route('portal.success', ['tenant' => $tenantModel->slug])
            ->with('mikrotik_url', $mikrotikLoginUrl);
    }

    public function success(Request $request, TenantContext $context, ?string $tenant = null)
    {
        $tenantModel = $context->getTenant() ?? ($tenant ? Tenant::where('slug', $tenant)->first() : null);

        if (!$tenantModel) {
            abort(404);
        }

        $mikrotikUrl = session('mikrotik_url');

        if (!$mikrotikUrl) {
            return redirect()->route('portal.index', ['tenant' => $tenantModel->slug]);
        }

        return view('portal.success', [
            'tenant' => $tenantModel,
            'mikrotikUrl' => $mikrotikUrl,
        ]);
    }
}
