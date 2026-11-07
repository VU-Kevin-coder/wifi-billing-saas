<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Support\HotspotLogin;
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
            ?? ($request->query('tenant') ? Tenant::where('slug', $request->query('tenant'))->first() : null);
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
        $voucher = Voucher::withoutTenancy()->where('tenant_id', $tenantModel->id)->where('code', $code)->first();

        if (! $voucher) {
            return back()->withErrors(['voucher_code' => 'Invalid voucher code.']);
        }

        if ($voucher->status !== 'unused') {
            return back()->withErrors(['voucher_code' => 'This voucher has already been used or voided.']);
        }

        $activationService->activate($tenantModel, $voucher, $request, 'Walk-in');
        $mikrotikUrl = HotspotLogin::forVoucher($voucher);

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

        $package = Package::withoutTenancy()
            ->where('tenant_id', $tenantModel->id)
            ->findOrFail($request->integer('package_id'));
        $paymentToken = Str::random(64);

        $transaction = Transaction::create([
            'tenant_id' => $tenantModel->id,
            'type' => 'voucher_sale',
            'amount' => $package->price,
            'status' => 'pending',
            'reference' => 'ONL-'.strtoupper(Str::random(8)),
            'phone' => $request->input('phone'),
            'payment_token_hash' => hash('sha256', $paymentToken),
            'description' => "Online Purchase: {$package->name}",
            'meta' => [
                'package_id' => $package->id,
                'phone' => $request->input('phone'),
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
            $this->portalUrl($request, 'success', $tenantModel)
        );

        if ($result['status'] === 'redirect') {
            session()->put('payment_token', $paymentToken);

            return redirect()->away($result['redirect_url']);
        }

        if (in_array($result['status'], ['simulated_success', 'pending'], true)) {
            session()->put('payment_token', $paymentToken);

            if ($result['authorization_url'] ?? null) {
                return redirect()->away($result['authorization_url']);
            }

            if ($result['status'] === 'simulated_success') {
                return redirect()->to($this->portalUrl($request, 'success', $tenantModel))
                    ->with('voucher_code', $result['voucher']->code);
            }

            return redirect()->to($this->portalUrl($request, 'pending', $tenantModel, $transaction));
        }

        return back()->withErrors([
            'phone' => 'Payment failed: '.($result['error'] ?? 'Please try again.'),
        ]);
    }

    public function pending(
        Request $request,
        TenantContext $context
    ) {
        $tenantModel = $this->resolvePortalTenant($request, $context);

        $payment = $this->findSessionPayment((int) $request->route('transaction'), $tenantModel);

        return view('portal.pending', [
            'tenant' => $tenantModel,
            'transaction' => $payment,
            'checkUrl' => $this->portalUrl($request, 'check', $tenantModel, $payment),
            'successUrl' => $this->portalUrl($request, 'success', $tenantModel),
            'portalUrl' => $this->portalUrl($request, 'index', $tenantModel),
        ]);
    }

    public function check(
        Request $request,
        TenantContext $context,
        PaymentService $paymentService
    ): JsonResponse {
        $tenantModel = $this->resolvePortalTenant($request, $context);

        $payment = $this->findSessionPayment((int) $request->route('transaction'), $tenantModel);
        if ($payment->status === 'pending') {
            $paymentService->reconcilePendingPayment($payment);
            $payment->refresh();
        }

        if ($payment->status === 'completed') {
            return response()->json([
                'status' => 'completed',
                'redirect_url' => $this->portalUrl($request, 'success', $tenantModel),
            ])->header('Cache-Control', 'no-store');
        }

        if ($payment->status === 'failed') {
            return response()->json([
                'status' => 'failed',
                'message' => 'Payment failed. Please return to the portal and try again.',
            ])->header('Cache-Control', 'no-store');
        }

        return response()->json(['status' => 'pending'])
            ->header('Cache-Control', 'no-store');
    }

    public function success(Request $request, TenantContext $context, ?string $tenant = null)
    {
        $tenantModel = $this->resolveTenant($request, $context, $tenant);
        abort_unless($tenantModel, 404);

        $mikrotikUrl = session('mikrotik_url');
        $voucherCode = $request->query('voucher') ?? session('voucher_code');
        $paymentToken = session('payment_token');

        if ($request->query->has('voucher')) {
            $transaction = $paymentToken
                ? Transaction::withoutTenancy()
                    ->where('tenant_id', $tenantModel->id)
                    ->where('type', 'voucher_sale')
                    ->where('payment_token_hash', hash('sha256', $paymentToken))
                    ->where('status', 'completed')
                    ->first()
                : null;
            $voucherId = $transaction?->meta['voucher_id'] ?? null;
            $voucher = $voucherId
                ? Voucher::withoutTenancy()->where('tenant_id', $tenantModel->id)->find($voucherId)
                : null;

            abort_unless($voucher && hash_equals($voucher->code, (string) $voucherCode), 404);
            $voucherCode = $voucher->code;
        }

        if (! $mikrotikUrl && ! $paymentToken && ! $voucherCode) {
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

    private function findSessionPayment(int $transactionId, Tenant $tenant): Transaction
    {
        $payment = Transaction::withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('type', 'voucher_sale')
            ->findOrFail($transactionId);
        $token = session('payment_token');
        $tokenHash = $payment->payment_token_hash;

        abort_unless(
            is_string($token)
                && is_string($tokenHash)
                && hash_equals($tokenHash, hash('sha256', $token)),
            404
        );

        return $payment;
    }

    private function resolvePortalTenant(Request $request, TenantContext $context): Tenant
    {
        $routeTenant = $request->route('tenant');
        $tenant = is_string($routeTenant)
            ? Tenant::where('slug', $routeTenant)->first()
            : $context->getTenant();

        abort_unless(
            $tenant && (! $context->getTenant() || $context->getTenant()->is($tenant)),
            404
        );

        return $tenant;
    }

    private function portalUrl(
        Request $request,
        string $page,
        Tenant $tenant,
        ?Transaction $transaction = null
    ): string {
        $directPortal = $request->routeIs('portal.direct.*');
        $routeName = $directPortal ? 'portal.direct.'.$page : 'portal.'.$page;
        $parameters = ['tenant' => $tenant->slug];

        if ($transaction) {
            $parameters['transaction'] = $transaction->id;
        }

        return route($routeName, $parameters);
    }

    public function activatePayment(
        string $token,
        Request $request,
        VoucherActivationService $activationService
    ): JsonResponse {
        $transaction = Transaction::withoutTenancy()
            ->where('type', 'voucher_sale')
            ->where('payment_token_hash', hash('sha256', $token))
            ->first();

        if (! $transaction) {
            return response()->json(['status' => 'not_found'], 404);
        }

        if ($transaction->status !== 'completed') {
            return response()->json(['status' => 'payment_not_completed'], 409);
        }

        $voucherId = $transaction->meta['voucher_id'] ?? null;
        $voucher = $voucherId
            ? Voucher::withoutTenancy()->where('tenant_id', $transaction->tenant_id)->find($voucherId)
            : null;

        if (! $voucher) {
            return response()->json(['status' => 'fulfillment_pending'], 202);
        }

        try {
            $tenant = Tenant::query()->findOrFail($transaction->tenant_id);
            $session = app(\App\Services\Tenancy\TenantContext::class)->runAs(
                $tenant,
                fn () => $activationService->activate(
                    $tenant,
                    $voucher,
                    $request,
                    $transaction->phone,
                    $transaction->meta ?? [],
                )
            );
        } catch (\LogicException $exception) {
            return response()->json([
                'status' => 'activation_failed',
                'message' => $exception->getMessage(),
            ], 409);
        }

        if ($session->status === 'provisioning') {
            return response()->json([
                'status' => 'provisioning',
                'message' => 'Payment confirmed. Waiting for the site router to activate access.',
            ], 202);
        }

        return response()->json([
            'status' => 'activated',
            'voucher_code' => $voucher->code,
            'mikrotik_url' => HotspotLogin::forVoucher($voucher),
        ]);
    }

    public function paymentStatus(string $token, PaymentService $paymentService): JsonResponse
    {
        $transaction = Transaction::withoutTenancy()
            ->where('type', 'voucher_sale')
            ->where('payment_token_hash', hash('sha256', $token))
            ->first();

        if (! $transaction) {
            return response()->json(['status' => 'not_found'], 404);
        }

        if ($transaction->status === 'pending' && ! empty($transaction->provider_reference)) {
            $paymentService->reconcilePendingPayment($transaction);
            $transaction->refresh();
        }

        if ($transaction->status === 'pending') {
            return response()->json(['status' => 'pending'], 202);
        }

        if ($transaction->status !== 'completed') {
            return response()->json(['status' => 'failed']);
        }

        $voucherId = $transaction->meta['voucher_id'] ?? null;
        $voucher = $voucherId
            ? Voucher::withoutTenancy()->where('tenant_id', $transaction->tenant_id)->find($voucherId)
            : null;

        if (! $voucher) {
            return response()->json(['status' => 'fulfillment_pending'], 202);
        }

        $session = Session::withoutTenancy()
            ->where('tenant_id', $transaction->tenant_id)
            ->where('voucher_id', $voucher->id)
            ->first();

        if ($session?->status === 'provisioning') {
            return response()->json(['status' => 'provisioning'], 202)
                ->header('Cache-Control', 'no-store');
        }

        if (! $session || $session->status !== 'active') {
            return response()->json(['status' => 'activation_pending'], 202)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'status' => 'completed',
            'voucher_code' => $voucher->code,
            'mikrotik_url' => HotspotLogin::forVoucher($voucher),
        ]);
    }

    public function pesapalCallback(
        Request $request,
        TenantContext $context,
        ?string $tenant = null
    ) {
        $tenantModel = $this->resolveTenant($request, $context, $tenant);
        abort_unless($tenantModel, 404);

        $orderTrackingId = $request->query('OrderTrackingId');
        $merchantReference = $request->query('OrderMerchantReference');

        if (! $orderTrackingId) {
            return redirect()->to($this->portalUrl($request, 'index', $tenantModel))
                ->withErrors(['error' => 'Payment was cancelled or missing tracking details.']);
        }

        // Verify with Pesapal API
        $consumerKey = \App\Models\PlatformSetting::get('pesapal_consumer_key');
        $consumerSecret = \App\Models\PlatformSetting::getSecret('pesapal_consumer_secret') ?: \App\Models\PlatformSetting::get('pesapal_consumer_secret');
        $environment = \App\Models\PlatformSetting::get('pesapal_environment', 'sandbox');
        $baseUrl = $environment === 'production' 
            ? 'https://pay.pesapal.com/v3/api' 
            : 'https://cybqa.pesapal.com/pesapalv3/api';

        try {
            $tokenResponse = \Illuminate\Support\Facades\Http::post("{$baseUrl}/Auth/RequestToken", [
                'consumer_key' => $consumerKey,
                'consumer_secret' => $consumerSecret,
            ]);

            $token = $tokenResponse->json('token');

            $statusResponse = \Illuminate\Support\Facades\Http::withToken($token)
                ->get("{$baseUrl}/Transactions/GetTransactionStatus?orderTrackingId={$orderTrackingId}");

            $status = $statusResponse->json('payment_status_description') ?? 'FAILED';

            $transaction = Transaction::withoutGlobalScopes()
                ->where('tenant_id', $tenantModel->id)
                ->where(function ($q) use ($merchantReference, $orderTrackingId) {
                    if ($merchantReference) {
                        $q->where('reference', $merchantReference);
                    }
                    if ($orderTrackingId) {
                        $q->orWhere('provider_reference', $orderTrackingId);
                    }
                })
                ->first();

            if (strtoupper($status) === 'COMPLETED' && $transaction && $transaction->status === 'pending') {
                $package = Package::withoutGlobalScopes()
                    ->where('tenant_id', $tenantModel->id)
                    ->find($transaction->meta['package_id'] ?? null);
                $phone = $transaction->phone ?? ($transaction->meta['phone'] ?? '');

                if ($package) {
                    $voucher = app(PaymentService::class)->handleSuccessfulPackagePayment(
                        $tenantModel,
                        $package,
                        $phone,
                        $transaction
                    );

                    return redirect()->to($this->portalUrl($request, 'success', $tenantModel))
                        ->with('voucher_code', $voucher->code)
                        ->with('auto_activate', true);
                }
            }

            if (strtoupper($status) === 'COMPLETED' && $transaction && $transaction->status === 'completed') {
                $voucherCode = $transaction->meta['voucher_code'] ?? null;

                return redirect()->to($this->portalUrl($request, 'success', $tenantModel))
                    ->with('voucher_code', $voucherCode)
                    ->with('auto_activate', true);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Pesapal callback error: '.$e->getMessage());
        }

        return redirect()->to($this->portalUrl($request, 'index', $tenantModel))
            ->withErrors(['error' => 'Payment was not successful or is still processing.']);
    }
}
