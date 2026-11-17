<?php

namespace App\Http\Controllers\Concerns;

use App\Models\RadiusAccountingSession;
use App\Models\Router;
use App\Models\Session;
use App\Models\SiteAgentCommand;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Services\PaymentService;
use App\Services\RadiusAccountingService;
use App\Services\RadiusVoucherEligibility;
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
            return response()->json(['status' => 'fulfillment_pending', 'message' => 'Payment confirmed. Access is being prepared. Please do not pay again.'], 202)->header('Cache-Control', 'no-store');
        }
        if ((int) $voucher->sale_transaction_id !== (int) $transaction->id) {
            return response()->json(['status' => 'activation_unavailable', 'message' => 'Payment confirmed, but the voucher link needs operator review. Please do not pay again.'], 202)->header('Cache-Control', 'no-store');
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
                    (int) $transaction->id,
                )
            );
        } catch (\LogicException $exception) {
            return response()->json([
                'status' => 'activation_failed',
                'message' => $exception->getMessage(),
            ], 409);
        }

        // Activation locks and updates a fresh model instance; discard the
        // pre-activation snapshot before evaluating credential readiness.
        $voucher->refresh();
        if ($radiusResponse = $this->radiusPaymentResponse($transaction, $voucher, $session, true)) {
            return $radiusResponse;
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
        ])->header('Cache-Control', 'no-store');
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
            return response()->json(['status' => 'fulfillment_pending', 'message' => 'Payment confirmed. Access is being prepared. Please do not pay again.'], 202)->header('Cache-Control', 'no-store');
        }
        if ((int) $voucher->sale_transaction_id !== (int) $transaction->id) {
            return response()->json(['status' => 'activation_unavailable', 'message' => 'Payment confirmed, but the voucher link needs operator review. Please do not pay again.'], 202)->header('Cache-Control', 'no-store');
        }

        $session = Session::withoutTenancy()
            ->where('tenant_id', $transaction->tenant_id)
            ->where('voucher_id', $voucher->id)
            ->first();

        if ($session && (! in_array($session->status, ['active', 'provisioning'], true)
            || ($session->expires_at && $session->expires_at->lte(now())))) {
            return response()->json(['status' => 'activation_unavailable', 'message' => 'The access session ended before connection. Contact the operator; please do not pay again.'], 202)->header('Cache-Control', 'no-store');
        }

        if ($session && ($radiusResponse = $this->radiusPaymentResponse($transaction, $voucher, $session))) {
            return $radiusResponse;
        }

        if ($session?->status === 'provisioning') {
            return response()->json(['status' => 'provisioning'], 202)
                ->header('Cache-Control', 'no-store');
        }

        if (! $session) {
            return response()->json(['status' => 'activation_required'], 202)
                ->header('Cache-Control', 'no-store');
        }

        if ($session->status !== 'active') {
            return response()->json(['status' => 'activation_pending'], 202)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'status' => 'completed',
            'voucher_code' => $voucher->code,
            'mikrotik_url' => HotspotLogin::forVoucher($voucher),
        ])->header('Cache-Control', 'no-store');
    }

    /** Null means the session is not RADIUS and retains its existing behavior. */
    private function radiusPaymentResponse(Transaction $sale, Voucher $voucher, Session $session, bool $activation = false): ?JsonResponse
    {
        if (! $session->router_id) {
            return response()->json(['status' => 'activation_unavailable', 'message' => 'Access cannot be confirmed. Contact the operator; please do not pay again.'], 202)->header('Cache-Control', 'no-store');
        }
        $router = Router::withoutTenancy()->where('tenant_id', $sale->tenant_id)->find($session->router_id);
        if (! $router) {
            return response()->json(['status' => 'activation_pending'], 202)->header('Cache-Control', 'no-store');
        }
        if ($router->access_backend === 'site_agent') {
            return null;
        }
        if ($router->access_backend !== 'radius') {
            return response()->json(['status' => 'activation_unavailable', 'message' => 'Access cannot be confirmed. Contact the operator; please do not pay again.'], 202)->header('Cache-Control', 'no-store');
        }

        $eligible = (int) $voucher->sale_transaction_id === (int) $sale->id
            && (int) $voucher->tenant_id === (int) $session->tenant_id
            && (int) $voucher->package_id === (int) $session->package_id
            && $router->hasUsableRadiusConfiguration()
            && app(RadiusVoucherEligibility::class)->permits($voucher)
            && ! $session->pending_revocation && ! $session->pending_device_ban
            && $session->expires_at && $session->expires_at->gt(now())
            && ! SiteAgentCommand::query()->where('tenant_id', $sale->tenant_id)
                ->where('router_id', $router->id)->where('session_id', $session->id)->exists();

        // For an already active session use its proven NAS identity, rather
        // than a fictitious new device which may exceed the device limit.
        $nasSessionId = 'readiness-'.bin2hex(random_bytes(16));
        if ($session->status === 'active') {
            $nasSessionId = RadiusAccountingSession::query()
                ->join('radius_nas_session_identities as identities', 'identities.current_generation_id', '=', 'radius_accounting_sessions.id')
                ->where('radius_accounting_sessions.tenant_id', $sale->tenant_id)
                ->where('radius_accounting_sessions.router_id', $router->id)
                ->where('radius_accounting_sessions.session_id', $session->id)
                ->where('radius_accounting_sessions.state', 'open')
                ->whereColumn('identities.id', 'radius_accounting_sessions.identity_id')
                ->where('identities.tenant_id', $sale->tenant_id)
                ->where('identities.router_id', $router->id)
                ->value('identities.nas_session_id');
            if (! $nasSessionId) {
                return response()->json(['status' => 'activation_unavailable', 'message' => 'Access cannot be confirmed. Contact the operator; please do not pay again.'], 202)->header('Cache-Control', 'no-store');
            }
        }

        $policy = $eligible ? app(RadiusAccountingService::class)->authorize(
            $router->radius_nas_identifier, $nasSessionId, $voucher->code
        ) : ['decision' => 'reject'];
        if (! $eligible || ! in_array($session->status, ['provisioning', 'active'], true)
            || $policy['decision'] !== 'accept') {
            return response()->json(['status' => 'activation_unavailable', 'message' => 'Access cannot be confirmed. Contact the operator; please do not pay again.'], 202)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'status' => $session->status === 'provisioning' ? 'radius_ready' : ($activation ? 'activated' : 'completed'),
            'voucher_code' => $voucher->code,
            'mikrotik_url' => HotspotLogin::forVoucher($voucher),
        ], $session->status === 'provisioning' ? 202 : 200)->header('Cache-Control', 'no-store');
    }
}
