<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PortalPaymentActions;
use App\Http\Controllers\Concerns\PortalPurchaseActions;
use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Services\PaymentService;
use App\Services\RadiusAccountingService;
use App\Services\Policy\Policy;
use App\Services\Tenancy\TenantContext;
use App\Services\VoucherActivationService;
use App\Support\HotspotLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PortalController extends Controller
{
    use PortalPaymentActions;
    use PortalPurchaseActions;

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
            'currency' => PlatformSetting::get('currency', 'UGX'),
            'purchaseKeys' => $packages->mapWithKeys(fn (Package $package) => [$package->id => Str::random(32)]),
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

        if (Policy::denies($tenantModel, 'portal_redeem')) {
            return back()->withErrors(['error' => 'This Wi-Fi operator is not redeeming vouchers right now.']);
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
        $code = strtoupper(trim($request->input('voucher_code')));
        $voucher = Voucher::withoutTenancy()->where('tenant_id', $tenantModel->id)->where('code', $code)->first();

        if (! $voucher) {
            return back()->withErrors(['voucher_code' => 'Invalid voucher code.']);
        }

        if ($voucher->status !== 'unused') {
            return back()->withErrors(['voucher_code' => 'This voucher has already been used or voided.']);
        }

        $session = $activationService->activate($tenantModel, $voucher, $request, 'Walk-in');
        $mikrotikUrl = HotspotLogin::forVoucher($voucher);
        $router = $session->router_id ? Router::withoutTenancy()->where('tenant_id', $tenantModel->id)->find($session->router_id) : null;
        $radiusReady = $session->status === 'provisioning' && $router?->access_backend === 'radius';
        if ($radiusReady && app(RadiusAccountingService::class)->authorize(
            (string) $router->radius_nas_identifier, 'readiness-'.bin2hex(random_bytes(16)), $voucher->code
        )['decision'] !== 'accept') {
            return back()->withErrors(['voucher_code' => 'Access cannot be confirmed. Contact the operator; do not redeem again.']);
        }
        // A previous purchase may belong to another tenant or customer.
        $request->session()->forget('payment_token');

        return redirect()->to($this->portalUrl($request, 'success', $tenantModel))
            ->with('voucher_tenant_id', $tenantModel->id)
            ->with('radius_ready', (bool) $radiusReady)
            ->with('mikrotik_url', $mikrotikUrl)
            ->with('voucher_code', $voucher->code);
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
            'currency' => PlatformSetting::get('currency', 'UGX'),
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

        if ($payment->status === 'completed' && empty($payment->meta['voucher_id'])) {
            return response()->json([
                'status' => 'fulfillment_pending',
                'message' => 'Payment confirmed. Access is being prepared. Please do not pay again.',
            ])->header('Cache-Control', 'no-store');
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
        if ($request->query->has('voucher')) {
            return redirect()->to($this->portalUrl($request, 'success', $tenantModel));
        }

        $voucherCode = session('voucher_code');
        $paymentToken = session('payment_token');
        if ($paymentToken && ! Transaction::withoutTenancy()->where('tenant_id', $tenantModel->id)
            ->where('type', 'voucher_sale')->where('payment_token_hash', hash('sha256', $paymentToken))->exists()) {
            $request->session()->forget('payment_token');
            $paymentToken = null;
        }
        if ((string) session('voucher_tenant_id') !== (string) $tenantModel->id) {
            $voucherCode = null;
            $mikrotikUrl = null;
        }

        if (! $mikrotikUrl && ! $paymentToken && ! $voucherCode) {
            return redirect()->route('portal.index', ['tenant' => $tenantModel->slug]);
        }

        return response()->view('portal.success', [
            'tenant' => $tenantModel,
            'mikrotikUrl' => $paymentToken ? null : $mikrotikUrl,
            'voucherCode' => $paymentToken ? null : $voucherCode,
            'radiusReady' => ! $paymentToken && (bool) session('radius_ready'),
            'paymentToken' => $paymentToken,
            'paymentStatusUrl' => $paymentToken
                ? route('api.payments.status', [], false)
                : null,
            'paymentActivationUrl' => $paymentToken
                ? route('api.payments.activate', [], false)
                : null,
        ])->header('Cache-Control', 'no-store');
    }

    /** The bearer token comes from the header only; a query-string or path token is ignored on purpose. */
    private function paymentToken(Request $request): string
    {
        return (string) $request->header('X-Payment-Token', '');
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
}
