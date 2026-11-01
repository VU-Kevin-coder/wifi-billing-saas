<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\Router;
use App\Models\Session;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PortalController extends Controller
{
    public function index(Request $request, TenantContext $context, ?string $tenant = null)
    {
        $tenantModel = $context->getTenant() ?? ($tenant ? Tenant::where('slug', $tenant)->first() : null);

        // If no tenant matches the subdomain, 404
        if (! $tenantModel) {
            abort(404);
        }

        // Fetch active packages for the "Buy" section (scoped to tenant)
        $packages = Package::where('is_published', true)->orderBy('price')->get();

        return view('portal.index', [
            'tenant' => $tenantModel,
            'packages' => $packages,
            'routerId' => $request->query('router_id'),
            'routerIdentity' => $request->query('router'),
        ]);
    }

    public function redeem(Request $request, TenantContext $context, ?string $tenant = null)
    {
        $tenantModel = $context->getTenant() ?? ($tenant ? Tenant::where('slug', $tenant)->first() : null);

        if (! $tenantModel) {
            abort(404);
        }

        $request->validate([
            'voucher_code' => ['required', 'string'],
            'router_id' => [
                'nullable',
                'integer',
                Rule::exists('routers', 'id')->where('tenant_id', $tenantModel->id),
            ],
            'router' => ['nullable', 'string', 'max:255'],
        ]);

        $code = strtoupper(trim($request->voucher_code));

        // Find the voucher specifically within this tenant
        $voucher = Voucher::where('code', $code)->first();

        if (! $voucher) {
            return back()->withErrors(['voucher_code' => 'Invalid voucher code. Please check and try again.']);
        }

        if ($voucher->status === 'redeemed') {
            return back()->withErrors(['voucher_code' => 'This voucher has already been used.']);
        }

        if ($voucher->status === 'voided') {
            return back()->withErrors(['voucher_code' => 'This voucher is no longer valid.']);
        }

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

        $router = null;
        if ($request->filled('router_id')) {
            $router = Router::where('tenant_id', $tenantModel->id)->findOrFail($request->integer('router_id'));
        } elseif ($request->filled('router')) {
            $router = Router::where('tenant_id', $tenantModel->id)
                ->where(function ($query) use ($request): void {
                    $query->where('name', $request->string('router')->toString())
                        ->orWhere('serial_number', $request->string('router')->toString());
                })
                ->first();

            if (! $router) {
                throw ValidationException::withMessages([
                    'router' => 'The access point identifier is not recognized for this workspace.',
                ]);
            }
        } elseif ($voucher->assigned_site) {
            $router = Router::where('tenant_id', $tenantModel->id)
                ->where('site', $voucher->assigned_site)
                ->first();
        }

        $site = $router?->site_id
            ? Site::where('tenant_id', $tenantModel->id)->find($router->site_id)
            : null;

        if (! $site && $router?->site) {
            $site = Site::where('tenant_id', $tenantModel->id)
                ->where('name', $router->site)
                ->first();
        }

        if (! $site && $voucher->assigned_site) {
            $site = Site::where('tenant_id', $tenantModel->id)
                ->where('name', $voucher->assigned_site)
                ->first();
        }

        // Until routers pass an identity, use the tenant's first site for the demo flow.
        $site ??= Site::where('tenant_id', $tenantModel->id)->orderBy('id')->first();

        // Create active session so it displays in operator live sessions
        $clientIp = $request->input('ip') ?: ($request->ip() ?: '10.50.1.'.rand(10, 250));
        $clientMac = $request->input('mac') ?: $request->header('X-Client-Mac');

        DB::transaction(function () use ($voucher, $tenantModel, $site, $router, $expiresAt, $clientIp, $clientMac): void {
            $voucher->update([
                'status' => 'redeemed',
                'redeemed_at' => now(),
                'redeemed_by' => 'Wi-Fi Guest',
                'site_id' => $site?->id,
            ]);

            Session::create([
                'tenant_id' => $tenantModel->id,
                'site_id' => $site?->id,
                'customer_id' => null,
                'voucher_id' => $voucher->id,
                'package_id' => $voucher->package_id,
                'router_id' => $router?->id,
                'status' => 'active',
                'started_at' => now(),
                'expires_at' => $expiresAt,
                'ip_address' => $clientIp,
                'mac_address' => $clientMac,
                'bytes_in' => 1024 * 128,
                'bytes_out' => 1024 * 512,
                'rate_in' => 1024 * 64,
                'rate_out' => 1024 * 256,
            ]);
        });

        // REDIRECT TO MIKROTIK (Placeholder for now)
        // In production, this URL will be the MikroTik login endpoint.
        $mikrotikLoginUrl = "http://10.5.50.1/login?username={$voucher->code}&password={$voucher->code}";

        return redirect()->route('portal.success', ['tenant' => $tenantModel->slug])
            ->with('mikrotik_url', $mikrotikLoginUrl);
    }

    public function success(Request $request, TenantContext $context, ?string $tenant = null)
    {
        $tenantModel = $context->getTenant() ?? ($tenant ? Tenant::where('slug', $tenant)->first() : null);

        if (! $tenantModel) {
            abort(404);
        }

        $mikrotikUrl = session('mikrotik_url');

        if (! $mikrotikUrl) {
            return redirect()->route('portal.index', ['tenant' => $tenantModel->slug]);
        }

        return view('portal.success', [
            'tenant' => $tenantModel,
            'mikrotikUrl' => $mikrotikUrl,
        ]);
    }
}
