<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Payments\IotecGateway;
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
        $event = $payload['event'] ?? null;
        if (! is_array($payload['data'] ?? null) || ! is_string($event)) {
            return response()->json(['status' => 'invalid_payload'], 400);
        }

        if (str_starts_with($event, 'collection.')) {
            $result = $payments->reconcileCollectionWebhook('obpay', $payload);
        } elseif (str_starts_with($event, 'payout.')) {
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

    public function iotec(
        Request $request,
        IotecGateway $iotec,
        PaymentGatewayService $payments,
        PaymentPayoutService $payouts,
    ): JsonResponse {
        if (! $iotec->verifyWebhook((string) $request->header('Authorization'))) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $payload = $request->json()->all();
        if (! is_string($payload['id'] ?? null)
            || ! is_string($payload['externalId'] ?? null)
            || ! is_string($payload['status'] ?? null)) {
            return response()->json(['status' => 'invalid_payload'], 400);
        }

        $isWithdrawal = Transaction::withoutTenancy()
            ->where('type', 'withdrawal')
            ->where('reference', $payload['externalId'])
            ->where('meta->payout_gateway', 'iotec')
            ->exists();
        $result = $isWithdrawal
            ? $payouts->handleWebhook('iotec', $payload)
            : $payments->reconcileCollectionWebhook('iotec', $payload);

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
