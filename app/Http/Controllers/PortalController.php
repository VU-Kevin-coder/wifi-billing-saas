<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\Session;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\PaymentService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PortalController extends Controller
{
    private function resolveTenant(Request $request, TenantContext $context, ?string $tenant = null): ?Tenant
    {
        return $context->getTenant()
            ?? ($tenant ? Tenant::where('slug', $tenant)->first() : null)
            ?? ($request->query('tenant') ? Tenant::where('slug', $request->query('tenant'))->first() : null)
            ?? Tenant::first();
    }

    public function index(Request $request, TenantContext $context, ?string $tenant = null)
    {
        $tenantModel = $this->resolveTenant($request, $context, $tenant);

        if (! $tenantModel) {
            abort(404);
        }

        $packages = Package::where('tenant_id', $tenantModel->id)
            ->where('is_published', true)
            ->orderBy('price')
            ->get();

        return view('portal.index', [
            'tenant' => $tenantModel,
            'packages' => $packages,
            'routerId' => $request->query('router_id'),
            'routerIdentity' => $request->query('router'),
        ]);
    }

    /**
     * 1. REDEEM EXISTING VOUCHER (Cash / Over-the-counter - No phone number required)
     */
    public function redeem(Request $request, TenantContext $context, ?string $tenant = null)
    {
        $tenantModel = $this->resolveTenant($request, $context, $tenant);

        if (! $tenantModel) {
            abort(404);
        }

        $request->validate([
            'voucher_code' => ['required', 'string'],
        ]);

        $code = strtoupper(trim($request->voucher_code));
        $voucher = Voucher::where('tenant_id', $tenantModel->id)->where('code', $code)->first();

        if (! $voucher) {
            return back()->withErrors(['voucher_code' => 'Invalid voucher code.']);
        }

        if ($voucher->status !== 'unused') {
            return back()->withErrors(['voucher_code' => 'This voucher has already been used or voided.']);
        }

        // Activate immediately (cash paid to vendor - no online payment needed)
        DB::transaction(function () use ($voucher, $tenantModel, $request) {
            $voucher->update([
                'status' => 'redeemed',
                'redeemed_at' => now(),
                'redeemed_by' => 'Cash/Walk-in',
            ]);

            $this->createGuestSession($request, $tenantModel, $voucher, 'Walk-in');
        });

        $mikrotikUrl = "http://10.5.50.1/login?username={$voucher->code}&password={$voucher->code}";

        return redirect()->route('portal.success', ['tenant' => $tenantModel->slug])
            ->with('mikrotik_url', $mikrotikUrl)
            ->with('voucher_code', $voucher->code);
    }

    /**
     * 2. BUY PACKAGE ONLINE (Requires phone number & triggers MoMo payment engine)
     */
    public function purchase(Request $request, TenantContext $context, PaymentService $paymentService, ?string $tenant = null)
    {
        $tenantModel = $this->resolveTenant($request, $context, $tenant);

        if (! $tenantModel) {
            abort(404);
        }

        $request->validate([
            'package_id' => 'required|exists:packages,id',
            'phone' => 'required|string|min:9|max:15',
        ]);

        $package = Package::where('tenant_id', $tenantModel->id)->findOrFail($request->package_id);

        // 1. Create a pending transaction
        $transaction = \App\Models\Transaction::create([
            'tenant_id' => $tenantModel->id,
            'type' => 'voucher_sale',
            'amount' => $package->price,
            'status' => 'pending',
            'phone' => $request->phone,
            'reference' => 'ONL-' . strtoupper(Str::random(8)),
            'description' => "Online Purchase: {$package->name}",
        ]);

        // 2. Trigger the payment engine (Simulation by default, or Real MTN MoMo)
        $result = $paymentService->initiatePackagePayment($tenantModel, $package, $request->phone, $transaction);

        if ($result['status'] === 'simulated_success') {
            // Find the voucher that was just generated upon payment confirmation
            $voucher = Voucher::where('tenant_id', $tenantModel->id)
                ->where('redeemed_by', $request->phone)
                ->latest()
                ->first()
                ?? Voucher::where('tenant_id', $tenantModel->id)->latest()->first();

            $code = $voucher ? $voucher->code : 'ACTIVE';
            $mikrotikUrl = "http://10.5.50.1/login?username={$code}&password={$code}";

            return redirect()->route('portal.success', ['tenant' => $tenantModel->slug])
                ->with('mikrotik_url', $mikrotikUrl)
                ->with('voucher_code', $code);
        }

        if ($result['status'] === 'pending') {
            return back()->with('status', 'Please check your phone for the MTN MoMo prompt.');
        }

        return back()->withErrors(['phone' => 'Payment failed: ' . ($result['error'] ?? 'Please try again.')]);
    }

    public function success(Request $request, TenantContext $context, ?string $tenant = null)
    {
        $tenantModel = $this->resolveTenant($request, $context, $tenant);

        if (! $tenantModel) {
            abort(404);
        }

        $mikrotikUrl = session('mikrotik_url');
        $voucherCode = session('voucher_code');

        if (! $mikrotikUrl) {
            return redirect()->route('portal.index', ['tenant' => $tenantModel->slug]);
        }

        return view('portal.success', [
            'tenant' => $tenantModel,
            'mikrotikUrl' => $mikrotikUrl,
            'voucherCode' => $voucherCode,
        ]);
    }

    /**
     * Create active session for operator live tracking.
     */
    private function createGuestSession(Request $request, Tenant $tenantModel, Voucher $voucher, string $customerPhone): void
    {
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

        $customer = Customer::firstOrCreate(
            [
                'tenant_id' => $tenantModel->id,
                'phone' => $customerPhone,
            ],
            [
                'name' => ($customerPhone === 'Walk-in') ? 'Walk-in Guest' : 'Online Customer',
                'status' => 'active',
                'package_id' => $package?->id,
            ]
        );

        $router = null;
        if ($request->filled('router_id')) {
            $router = Router::where('tenant_id', $tenantModel->id)->find($request->integer('router_id'));
        } elseif ($request->filled('router')) {
            $router = Router::where('tenant_id', $tenantModel->id)
                ->where(function ($query) use ($request): void {
                    $query->where('name', $request->string('router')->toString())
                        ->orWhere('serial_number', $request->string('router')->toString());
                })
                ->first();
        } elseif ($voucher->assigned_site) {
            $router = Router::where('tenant_id', $tenantModel->id)
                ->where('site', $voucher->assigned_site)
                ->first();
        }

        $site = $router?->site_id
            ? Site::where('tenant_id', $tenantModel->id)->find($router->site_id)
            : null;

        if (! $site && $router?->site) {
            $site = Site::where('tenant_id', $tenantModel->id)->where('name', $router->site)->first();
        }

        if (! $site && $voucher->assigned_site) {
            $site = Site::where('tenant_id', $tenantModel->id)->where('name', $voucher->assigned_site)->first();
        }

        $site ??= Site::where('tenant_id', $tenantModel->id)->orderBy('id')->first();

        $clientIp = $request->input('ip') ?: ($request->ip() ?: '10.50.1.'.rand(10, 250));
        $clientMac = $request->input('mac') ?: $request->header('X-Client-Mac');

        Session::create([
            'tenant_id' => $tenantModel->id,
            'site_id' => $site?->id,
            'customer_id' => $customer->id,
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
    }
}
