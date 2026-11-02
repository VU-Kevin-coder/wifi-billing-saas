<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MomoWebhookController extends Controller
{
    /**
     * Handle incoming payment notifications from MTN MoMo.
     */
    public function handle(Request $request, PaymentService $paymentService): JsonResponse
    {
        Log::info('MTN MoMo Webhook Received', $request->all());

        $referenceId = $request->input('referenceId') 
            ?? $request->input('externalId')
            ?? $request->input('financialTransactionId');
        
        $status = strtoupper($request->input('status', ''));

        if (!$referenceId) {
            return response()->json(['status' => 'missing_reference'], 400);
        }

        $transaction = Transaction::where('reference', $referenceId)->first();

        if (!$transaction) {
            $transaction = Transaction::where('reference', 'LIKE', "%{$referenceId}%")->first();
        }

        if (!$transaction || $transaction->status !== 'pending') {
            return response()->json([
                'status' => 'ignored', 
                'message' => 'Transaction not found or already processed'
            ], 200);
        }

        if ($status === 'SUCCESSFUL' || $status === 'SUCCESS') {
            $tenant = $transaction->tenant;
            $phone = $transaction->phone ?? 'Online Customer';

            // Check if this is an online package purchase: "Online Purchase: {packageName}"
            if (str_starts_with($transaction->description ?? '', 'Online Purchase:')) {
                $packageName = trim(substr($transaction->description, strlen('Online Purchase:')));
                $package = Package::where('tenant_id', $tenant->id)->where('name', $packageName)->first()
                    ?? Package::where('tenant_id', $tenant->id)->where('price', $transaction->amount)->first();

                if ($package) {
                    $voucher = $paymentService->handleSuccessfulPackagePayment($tenant, $package, $phone, $transaction);
                    Log::info("Online voucher {$voucher->code} created and redeemed for transaction {$transaction->id}");
                } else {
                    $transaction->update(['status' => 'completed']);
                }
            } else {
                $parts = explode(' ', $transaction->description ?? '');
                $voucherCode = $parts[1] ?? null;
                $voucher = $voucherCode ? Voucher::where('code', $voucherCode)->first() : null;

                if ($voucher) {
                    $package = $voucher->package;
                    if ($package) {
                        $paymentService->handleSuccessfulPackagePayment($tenant, $package, $phone, $transaction);
                    }
                } else {
                    $transaction->update(['status' => 'completed']);
                }
            }
        } else {
            $transaction->update(['status' => 'failed']);
            Log::warning("MTN MoMo payment failed for transaction {$transaction->id} with status: {$status}");
        }

        return response()->json(['status' => 'ok'], 200);
    }
}
