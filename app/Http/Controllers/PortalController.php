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

        // Calculate session expiry from package duration
        $package = $voucher->package;
        $expiresAt = now()->addHours(2);
        if ($package && $package->duration_value) {
            $unit = strtolower($package->duration_unit ?? 'hours');
            $val = (int) $package->duration_value;
            $expiresAt = match ($unit) {
                'minutes', 'minute' => now()->addMinutes($val),
                'days', 'day' => now()->addDays($val),
                'weeks', 'week' => now()->addWeeks($val),
                'months', 'month' => now()->addMonths($val),
                default => now()->addHours($val),
            };
        }

        // Associate with an active router for this tenant if available
        $router = \App\Models\Router::where('tenant_id', $tenantModel->id)
            ->when($voucher->assigned_site, fn($q) => $q->where('site', $voucher->assigned_site))
            ->first() ?? \App\Models\Router::where('tenant_id', $tenantModel->id)->first();

        // Create active session so it displays in operator live sessions
        $clientIp = $request->input('ip') ?: ($request->ip() ?: '10.50.1.' . rand(10, 250));
        $clientMac = $request->input('mac') ?: $request->header('X-Client-Mac');

        \App\Models\Session::create([
            'tenant_id'   => $tenantModel->id,
            'customer_id' => null,
            'voucher_id'  => $voucher->id,
            'package_id'  => $voucher->package_id,
            'router_id'   => $router?->id,
            'status'      => 'active',
            'started_at'  => now(),
            'expires_at'  => $expiresAt,
            'ip_address'  => $clientIp,
            'mac_address' => $clientMac,
            'bytes_in'    => 1024 * 128,
            'bytes_out'   => 1024 * 512,
            'rate_in'     => 1024 * 64,
            'rate_out'    => 1024 * 256,
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
