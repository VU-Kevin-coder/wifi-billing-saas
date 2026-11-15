<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Payments\ObPayGateway;
use App\Services\Payments\PaymentGatewayService;
use App\Services\Payments\PaymentPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentGatewayWebhookController extends Controller
{
    public function obpay(
        Request $request,
        ObPayGateway $obpay,
        PaymentGatewayService $payments,
        PaymentPayoutService $payouts,
    ): JsonResponse {
        if (! $obpay->verifyWebhook($request->getContent(), (string) $request->header('X-ObPay-Signature'))) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $payload = $request->json()->all();
        $data = $payload['data'] ?? null;
        if (! is_array($data) || ! is_string($payload['event'] ?? null)) {
            return response()->json(['status' => 'invalid_payload'], 400);
        }

        if (str_starts_with($payload['event'], 'collection.')) {
            $result = $payments->reconcileCollectionWebhook('obpay', $payload);
        } elseif (str_starts_with($payload['event'], 'payout.')) {
            $result = $payouts->handleWebhook('obpay', $payload);
        } else {
            return response()->json(['status' => 'ignored']);
        }

        return $this->response($result);
    }

    public function pawapay(
        Request $request,
        PaymentGatewayService $payments,
        PaymentPayoutService $payouts,
    ): JsonResponse {
        $payload = $request->json()->all();
        if (is_string($payload['depositId'] ?? null)) {
            return $this->response($payments->reconcileCollectionWebhook('pawapay', $payload));
        }
        if (is_string($payload['payoutId'] ?? null)) {
            return $this->response($payouts->handleWebhook('pawapay', $payload));
        }

        return response()->json(['status' => 'invalid_payload'], 400);
    }

    public function xente(
        Request $request,
        PaymentGatewayService $payments,
        PaymentPayoutService $payouts,
    ): JsonResponse {
        if (! in_array($request->ip(), ['52.48.24.237', '34.252.29.119'], true)) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $payload = $request->json()->all();
        if (! is_string($payload['requestId'] ?? null)
            || ! is_string($payload['transactionId'] ?? null)) {
            return response()->json(['status' => 'invalid_payload'], 400);
        }

        $isWithdrawal = Transaction::withoutTenancy()
            ->where('type', 'withdrawal')
            ->where('reference', $payload['requestId'])
            ->where('meta->payout_gateway', 'xente')
            ->exists();
        $result = $isWithdrawal
            ? $payouts->handleWebhook('xente', $payload)
            : $payments->reconcileCollectionWebhook('xente', $payload);

        return $this->response($result);
    }

    private function response(string $result): JsonResponse
    {
        return match ($result) {
            'unavailable' => response()->json(['status' => $result], 503),
            'mismatch', 'fulfillment_data_missing', 'unauthorized' => response()->json(['status' => $result], 422),
            default => response()->json(['status' => $result]),
        };
    }
}
