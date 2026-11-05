<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\FlutterwaveService;
use App\Services\FlutterwaveWithdrawalService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FlutterwaveWebhookController extends Controller
{
    public function handle(
        Request $request,
        FlutterwaveService $flutterwave,
        FlutterwaveWithdrawalService $withdrawals,
        PaymentService $payments,
    ): JsonResponse {
        $secretHash = PlatformSetting::getSecret(
            'flutterwave_webhook_secret',
            config('services.flutterwave.webhook_secret')
        );
        $providedHash = $request->header('verif-hash');

        if (! is_string($secretHash) || $secretHash === ''
            || ! is_string($providedHash) || ! hash_equals($secretHash, $providedHash)) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $data = $request->input('data');
        if (! is_array($data)) {
            return response()->json(['status' => 'invalid_payload'], 400);
        }

        if ($request->input('event') === 'transfer.completed') {
            $result = $withdrawals->handleWebhook($data);

            return response()->json(['status' => $result['message']], $result['ok'] ? 200 : 503);
        }

        if ($request->input('event') !== 'charge.completed') {
            return response()->json(['status' => 'ignored']);
        }

        $transactionId = isset($data['id']) ? (string) $data['id'] : null;
        $reference = $data['tx_ref'] ?? null;
        if (! $transactionId || ! is_string($reference) || $reference === '') {
            return response()->json(['status' => 'missing_transaction_details'], 400);
        }

        $transaction = Transaction::withoutTenancy()
            ->where('type', 'voucher_sale')
            ->where('reference', $reference)
            ->first();

        if (! $transaction || $transaction->status !== 'pending') {
            return response()->json(['status' => 'ignored']);
        }

        if ($transaction->provider_reference
            && (string) $transaction->provider_reference !== $transactionId) {
            return response()->json(['status' => 'provider_reference_mismatch'], 422);
        }

        try {
            $verification = $flutterwave->verifyTransaction($transactionId);
        } catch (Throwable $exception) {
            Log::error('Unable to verify Flutterwave payment webhook.', [
                'transaction_id' => $transaction->id,
                'provider_transaction_id' => $transactionId,
                'exception' => $exception::class,
            ]);

            return response()->json(['status' => 'verification_unavailable'], 503);
        }

        $verified = $verification['data'] ?? null;
        if (($verification['status'] ?? null) !== 'success'
            || ! is_array($verified)
            || (string) ($verified['id'] ?? '') !== $transactionId
            || ($verified['tx_ref'] ?? null) !== $transaction->reference) {
            Log::warning('Flutterwave payment verification did not match the transaction reference.', [
                'transaction_id' => $transaction->id,
                'provider_transaction_id' => $transactionId,
            ]);

            return response()->json(['status' => 'verification_mismatch'], 422);
        }

        $verifiedStatus = strtolower((string) ($verified['status'] ?? ''));
        if ($verifiedStatus === 'failed') {
            DB::transaction(function () use ($transaction): void {
                $locked = Transaction::withoutTenancy()
                    ->where('tenant_id', $transaction->tenant_id)
                    ->lockForUpdate()
                    ->findOrFail($transaction->id);
                if ($locked->status === 'pending') {
                    $locked->update(['status' => 'failed']);
                }
            });

            return response()->json(['status' => 'failed']);
        }

        if ($verifiedStatus !== 'successful') {
            return response()->json(['status' => 'pending'], 202);
        }

        $tenant = Tenant::query()->find($transaction->tenant_id);
        $packageId = $transaction->meta['package_id'] ?? null;
        $package = $packageId
            ? Package::withoutTenancy()
                ->where('tenant_id', $transaction->tenant_id)
                ->find($packageId)
            : null;

        $expectedCurrency = strtoupper((string) ($package?->currency ?: PlatformSetting::get('currency', 'UGX')));
        $verifiedCurrency = strtoupper((string) ($verified['currency'] ?? ''));
        $verifiedAmount = $verified['amount'] ?? null;

        if (! $tenant || ! $package
            || $verifiedCurrency !== $expectedCurrency
            || ! is_numeric($verifiedAmount)
            || (float) $verifiedAmount !== (float) $transaction->amount) {
            Log::error('Verified Flutterwave payment did not match expected fulfillment details.', [
                'transaction_id' => $transaction->id,
                'expected_currency' => $expectedCurrency,
                'verified_currency' => $verifiedCurrency,
                'expected_amount' => (float) $transaction->amount,
                'verified_amount' => $verifiedAmount,
            ]);

            return response()->json(['status' => 'payment_details_mismatch'], 422);
        }

        $phone = $transaction->phone ?? $transaction->meta['phone'] ?? null;
        if (! is_string($phone) || $phone === '') {
            return response()->json(['status' => 'fulfillment_data_missing'], 422);
        }

        try {
            $transaction->update(['provider_reference' => $transactionId]);
            $payments->handleSuccessfulPackagePayment($tenant, $package, $phone, $transaction);
        } catch (Throwable $exception) {
            Log::error('Verified Flutterwave payment could not be fulfilled.', [
                'transaction_id' => $transaction->id,
                'exception' => $exception::class,
            ]);

            return response()->json(['status' => 'fulfillment_failed'], 500);
        }

        return response()->json(['status' => 'ok']);
    }
}
