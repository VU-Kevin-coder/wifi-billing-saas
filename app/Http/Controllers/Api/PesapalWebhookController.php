<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\PaymentService;
use App\Services\PesapalOrderVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PesapalWebhookController extends Controller
{
    public function handle(
        Request $request,
        PaymentService $paymentService,
        PesapalOrderVerifier $orderVerifier
    ): JsonResponse {
        $orderTrackingId = $request->input('OrderTrackingId', $request->query('OrderTrackingId'));
        $merchantReference = $request->input('OrderMerchantReference', $request->query('OrderMerchantReference'));
        $notificationType = $request->input('OrderNotificationType', $request->query('OrderNotificationType', 'IPNCHANGE'));

        if (! $orderTrackingId) {
            return response()->json(['status' => 'missing_tracking_id'], 400);
        }

        $consumerKey = PlatformSetting::get('pesapal_consumer_key');
        $consumerSecret = PlatformSetting::getSecret('pesapal_consumer_secret') ?: PlatformSetting::get('pesapal_consumer_secret');
        $environment = PlatformSetting::get('pesapal_environment', 'sandbox');
        $baseUrl = $environment === 'production'
            ? 'https://pay.pesapal.com/v3/api'
            : 'https://cybqa.pesapal.com/pesapalv3/api';

        try {
            $tokenResponse = Http::post("{$baseUrl}/Auth/RequestToken", [
                'consumer_key' => $consumerKey,
                'consumer_secret' => $consumerSecret,
            ]);

            if ($tokenResponse->failed()) {
                Log::error('Pesapal Webhook Auth Error: '.$tokenResponse->body());

                return response()->json(['status' => 'auth_failed'], 502);
            }

            $token = $tokenResponse->json('token');

            $statusResponse = Http::withToken($token)
                ->get("{$baseUrl}/Transactions/GetTransactionStatus?orderTrackingId={$orderTrackingId}");

            if ($statusResponse->failed()) {
                Log::error('Pesapal Webhook Status Error: '.$statusResponse->body());

                return response()->json(['status' => 'status_check_failed'], 502);
            }

            $providerDetails = $statusResponse->json();
            $status = strtoupper($providerDetails['payment_status_description'] ?? 'FAILED');

            $transaction = $orderVerifier->findTransaction($orderTrackingId, $merchantReference);

            if (! $transaction) {
                return response()->json(['status' => 'transaction_not_found'], 404);
            }

            if (! $orderVerifier->matchesMerchantReference($transaction, $providerDetails)) {
                return $this->acknowledge($notificationType, $orderTrackingId, $merchantReference);
            }

            $tenant = Tenant::withoutGlobalScopes()->find($transaction->tenant_id);
            if (! $tenant) {
                return response()->json(['status' => 'tenant_not_found'], 404);
            }

            if ($status === 'COMPLETED' && $transaction->status === 'pending') {
                $package = Package::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->find($transaction->meta['package_id'] ?? null);
                $phone = $transaction->phone ?? ($transaction->meta['phone'] ?? '');

                if ($package && $orderVerifier->matchesCompletedPayment($transaction, $package, $providerDetails)) {
                    $paymentService->handleSuccessfulPackagePayment(
                        $tenant,
                        $package,
                        $phone,
                        $transaction
                    );
                }
            } elseif ($status === 'FAILED' && $transaction->status === 'pending') {
                Transaction::withoutTenancy()->where('tenant_id', $transaction->tenant_id)
                    ->whereKey($transaction->id)->where('status', 'pending')->update(['status' => 'failed']);
            }

            return $this->acknowledge($notificationType, $orderTrackingId, $merchantReference);
        } catch (\Throwable $e) {
            Log::error('Pesapal Webhook Exception: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    private function acknowledge(string $notificationType, string $orderTrackingId, ?string $merchantReference): JsonResponse
    {
        return response()->json([
            'orderNotificationType' => $notificationType,
            'orderTrackingId' => $orderTrackingId,
            'orderMerchantReference' => $merchantReference,
            'status' => 200,
        ]);
    }
}
