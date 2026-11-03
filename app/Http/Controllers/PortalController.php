<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Services\PaymentService;
use App\Services\Tenancy\TenantContext;
use App\Services\VoucherActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
        abort_unless($tenantModel, 404);

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

    public function redeem(
        Request $request,
        TenantContext $context,
        VoucherActivationService $activationService,
        ?string $tenant = null
    ) {
        $tenantModel = $this->resolveTenant($request, $context, $tenant);
        abort_unless($tenantModel, 404);

        $request->validate([
            'voucher_code' => ['required', 'string'],
            'router_id' => [
                'nullable',
                'integer',
                Rule::exists('routers', 'id')->where('tenant_id', $tenantModel->id),
            ],
            'router' => ['nullable', 'string', 'max:255'],
        ]);
        $code = strtoupper(trim($request->input('voucher_code')));
        $voucher = Voucher::where('tenant_id', $tenantModel->id)->where('code', $code)->first();

        if (! $voucher) {
            return back()->withErrors(['voucher_code' => 'Invalid voucher code.']);
        }

        if ($voucher->status !== 'unused') {
            return back()->withErrors(['voucher_code' => 'This voucher has already been used or voided.']);
        }

        $activationService->activate($tenantModel, $voucher, $request, 'Walk-in');
        $mikrotikUrl = 'http://10.5.50.1/login?username='.rawurlencode($voucher->code);

        return redirect()->route('portal.success', ['tenant' => $tenantModel->slug])
            ->with('mikrotik_url', $mikrotikUrl)
            ->with('voucher_code', $voucher->code);
    }

    public function purchase(
        Request $request,
        TenantContext $context,
        PaymentService $paymentService,
        ?string $tenant = null
    ) {
        $tenantModel = $this->resolveTenant($request, $context, $tenant);
        abort_unless($tenantModel, 404);

        $request->validate([
            'package_id' => [
                'required',
                'integer',
                Rule::exists('packages', 'id')->where('tenant_id', $tenantModel->id),
            ],
            'phone' => ['required', 'string', 'min:9', 'max:15'],
            'email' => ['nullable', 'email', 'max:255'],
            'router_id' => [
                'nullable',
                'integer',
                Rule::exists('routers', 'id')->where('tenant_id', $tenantModel->id),
            ],
            'router' => ['nullable', 'string', 'max:255'],
        ]);

        $package = Package::where('tenant_id', $tenantModel->id)->findOrFail($request->integer('package_id'));
        $paymentToken = Str::random(64);

        $transaction = Transaction::create([
            'tenant_id' => $tenantModel->id,
            'type' => 'voucher_sale',
            'amount' => $package->price,
            'status' => 'pending',
            'reference' => 'ONL-'.strtoupper(Str::random(8)),
            'phone' => $request->input('phone'),
            'description' => "Online Purchase: {$package->name}",
            'meta' => [
                'package_id' => $package->id,
                'phone' => $request->input('phone'),
                'payment_token_hash' => hash('sha256', $paymentToken),
                'router_id' => $request->input('router_id'),
                'router' => $request->input('router'),
                'ip' => $request->ip(),
                'mac' => $request->input('mac') ?? $request->header('X-Client-Mac'),
                'email' => $request->input('email'),
            ],
        ]);

        $result = $paymentService->initiatePackagePayment(
            $tenantModel,
            $package,
            $request->input('phone'),
            $transaction,
            $request->input('email'),
            route('portal.success', ['tenant' => $tenantModel->slug])
        );

        if (in_array($result['status'], ['simulated_success', 'pending'], true)) {
            session()->put('payment_token', $paymentToken);

            if ($result['authorization_url'] ?? null) {
                return redirect()->away($result['authorization_url']);
            }

            return redirect()->route('portal.success', ['tenant' => $tenantModel->slug])
                ->with('payment_token', $paymentToken);
        }

        return back()->withErrors([
            'phone' => 'Payment failed: '.($result['error'] ?? 'Please try again.'),
        ]);
    }

    public function success(Request $request, TenantContext $context, ?string $tenant = null)
    {
        $tenantModel = $this->resolveTenant($request, $context, $tenant);
        abort_unless($tenantModel, 404);

        $mikrotikUrl = session('mikrotik_url');
        $voucherCode = session('voucher_code');
        $paymentToken = session('payment_token');

        if (! $mikrotikUrl && ! $paymentToken) {
            return redirect()->route('portal.index', ['tenant' => $tenantModel->slug]);
        }

        return view('portal.success', [
            'tenant' => $tenantModel,
            'mikrotikUrl' => $mikrotikUrl,
            'voucherCode' => $voucherCode,
            'paymentStatusUrl' => $paymentToken
                ? route('api.payments.status', ['token' => $paymentToken], false)
                : null,
            'paymentActivationUrl' => $paymentToken
                ? route('api.payments.activate', ['token' => $paymentToken], false)
                : null,
        ]);
    }

    public function activatePayment(
        string $token,
        Request $request,
        VoucherActivationService $activationService
    ): JsonResponse {
        $transaction = Transaction::query()
            ->where('type', 'voucher_sale')
            ->where('meta->payment_token_hash', hash('sha256', $token))
            ->first();

        if (! $transaction) {
            return response()->json(['status' => 'not_found'], 404);
        }

        if ($transaction->status !== 'completed') {
            return response()->json(['status' => 'payment_not_completed'], 409);
        }

        $voucherId = $transaction->meta['voucher_id'] ?? null;
        $voucher = $voucherId
            ? Voucher::where('tenant_id', $transaction->tenant_id)->find($voucherId)
            : null;

        if (! $voucher) {
            return response()->json(['status' => 'fulfillment_pending'], 202);
        }

        try {
            $activationService->activate(
                Tenant::query()->findOrFail($transaction->tenant_id),
                $voucher,
                $request,
                $transaction->phone,
                $transaction->meta ?? [],
            );
        } catch (\LogicException $exception) {
            return response()->json([
                'status' => 'activation_failed',
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'status' => 'activated',
            'voucher_code' => $voucher->code,
            'mikrotik_url' => 'http://10.5.50.1/login?username='.rawurlencode($voucher->code),
        ]);
    }

    public function paymentStatus(string $token): JsonResponse
    {
        $transaction = Transaction::query()
            ->where('type', 'voucher_sale')
            ->where('meta->payment_token_hash', hash('sha256', $token))
            ->first();

        if (! $transaction) {
            return response()->json(['status' => 'not_found'], 404);
        }

        if ($transaction->status === 'pending') {
            return response()->json(['status' => 'pending'], 202);
        }

        if ($transaction->status !== 'completed') {
            return response()->json(['status' => 'failed']);
        }

        $voucherId = $transaction->meta['voucher_id'] ?? null;
        $voucher = $voucherId
            ? Voucher::where('tenant_id', $transaction->tenant_id)->find($voucherId)
            : null;

        if (! $voucher) {
            return response()->json(['status' => 'fulfillment_pending'], 202);
        }

        return response()->json([
            'status' => 'completed',
            'voucher_code' => $voucher->code,
            'mikrotik_url' => 'http://10.5.50.1/login?username='.rawurlencode($voucher->code),
        ]);
    }
}
