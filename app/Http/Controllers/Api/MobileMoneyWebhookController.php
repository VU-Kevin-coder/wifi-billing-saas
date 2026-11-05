<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileMoneyWebhookController extends Controller
{
    public function handleMtn(Request $request, PaymentService $payments): JsonResponse
    {
        $reference = $request->input('referenceId')
            ?? $request->input('reference_id')
            ?? $request->header('X-Reference-Id');

        return $this->reconcile($reference, 'MTN', $payments);
    }

    public function handleAirtel(Request $request, PaymentService $payments): JsonResponse
    {
        $reference = $request->input('transaction.id')
            ?? $request->input('data.transaction.id')
            ?? $request->input('id');

        return $this->reconcile($reference, 'AIRTEL', $payments);
    }

    private function reconcile(mixed $reference, string $provider, PaymentService $payments): JsonResponse
    {
        if (! is_string($reference) || $reference === '') {
            return response()->json(['status' => 'missing_reference'], 400);
        }

        $transaction = Transaction::withoutTenancy()
            ->where('type', 'voucher_sale')
            ->where('provider_reference', $reference)
            ->where('meta->payment_provider', $provider)
            ->first();

        if (! $transaction || $transaction->status !== 'pending') {
            return response()->json(['status' => 'ignored']);
        }

        $status = $payments->reconcilePendingPayment($transaction, $provider);

        return match ($status) {
            'unavailable' => response()->json(['status' => $status], 503),
            'mismatch', 'fulfillment_data_missing' => response()->json(['status' => $status], 422),
            default => response()->json(['status' => $status]),
        };
    }
}
