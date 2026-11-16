<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Router;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Services\PaymentService;
use App\Services\Tenancy\TenantContext;
use App\Services\VoucherActivationService;
use App\Support\HotspotLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait PortalPaymentActions
{
    public function activatePayment(
        Request $request,
        VoucherActivationService $activationService
    ): JsonResponse {
        $token = $this->paymentToken($request);
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
            return response()->json(['status' => 'fulfillment_pending', 'message' => 'Payment confirmed. Access may require operator assistance. Please do not pay again; contact the operator if it does not arrive.'], 202)->header('Cache-Control', 'no-store');
        }
        if ((int) $voucher->sale_transaction_id !== (int) $transaction->id
            || (int) $voucher->package_id !== (int) ($transaction->meta['package_id'] ?? 0)) {
            return response()->json(['status' => 'fulfillment_pending', 'message' => 'Payment confirmed. Access requires operator review. Please do not pay again.'], 202)->header('Cache-Control', 'no-store');
        }

        try {
            $tenant = Tenant::query()->findOrFail($transaction->tenant_id);
            $session = app(TenantContext::class)->runAs(
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

        if ($this->paidRadiusOrAmbiguous($session, (int) $transaction->tenant_id)) {
            return response()->json(['status' => 'activation_failed', 'message' => 'Access requires operator review. Please do not pay again.'], 409)
                ->header('Cache-Control', 'no-store');
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

    private function paidRadiusOrAmbiguous(Session $session, int $tenantId): bool
    {
        if ($session->access_backend_origin === 'radius') {
            return true;
        }
        if (! $session->router_id) {
            return false;
        }
        $router = Router::withoutTenancy()->where('tenant_id', $tenantId)->find($session->router_id);

        return ! $router || $router->access_backend !== 'site_agent'
            || $router->radius_nas_identifier !== null || $router->getRawOriginal('radius_shared_secret') !== null;
    }

    public function paymentStatus(Request $request, PaymentService $paymentService): JsonResponse
    {
        $token = $this->paymentToken($request);
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
            return response()->json(['status' => 'fulfillment_pending', 'message' => 'Payment confirmed. Access may require operator assistance. Please do not pay again; contact the operator if it does not arrive.'], 202)->header('Cache-Control', 'no-store');
        }
        if ((int) $voucher->sale_transaction_id !== (int) $transaction->id
            || (int) $voucher->package_id !== (int) ($transaction->meta['package_id'] ?? 0)) {
            return response()->json(['status' => 'fulfillment_pending', 'message' => 'Payment confirmed. Access requires operator review. Please do not pay again.'], 202)->header('Cache-Control', 'no-store');
        }

        $session = Session::withoutTenancy()
            ->where('tenant_id', $transaction->tenant_id)
            ->where('voucher_id', $voucher->id)
            ->first();

        // Never reveal a paid voucher for an existing RADIUS session. The
        // integration is deliberately blocked even if old data says active.
        if ($session && $this->paidRadiusOrAmbiguous($session, (int) $transaction->tenant_id)) {
            return response()->json(['status' => 'fulfillment_pending', 'message' => 'Payment confirmed. Access requires operator review. Please do not pay again.'], 202)
                ->header('Cache-Control', 'no-store');
        }

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
}
