<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    /**
     * Initiate a Flutterwave Uganda mobile money charge.
     */
    public function initiatePackagePayment(Tenant $tenant, Package $package, string $phone, Transaction $transaction)
    {
        $amount = $package->price;
        $secretKey = PlatformSetting::get('flutterwave_secret_key');

        // ✅ SIMULATION MODE: If no secret key exists, complete immediately
        if (empty($secretKey)) {
            $voucher = $this->handleSuccessfulPackagePayment($tenant, $package, $phone, $transaction);
            return ['status' => 'simulated_success', 'voucher' => $voucher];
        }

        // Detect network from phone prefix
        $msisdn = preg_replace('/\D+/', '', $phone);
        if (strlen($msisdn) === 10 && str_starts_with($msisdn, '0')) {
            $msisdn = '256' . substr($msisdn, 1);
        } elseif (strlen($msisdn) === 9) {
            $msisdn = '256' . $msisdn;
        }

        $network = 'MTN';
        if (preg_match('/^256(70|75|74)/', $msisdn)) {
            $network = 'AIRTEL';
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withToken($secretKey)
                ->post('https://api.flutterwave.com/v3/charges?type=mobile_money_uganda', [
                    'phone_number' => $msisdn,
                    'network' => $network,
                    'amount' => $amount,
                    'currency' => 'UGX',
                    'email' => 'customer@hamnet.com',
                    'tx_ref' => $transaction->reference,
                    'fullname' => 'Hamnet Customer',
                ]);

            if ($response->failed()) {
                \Log::error('Flutterwave Payment Error: ' . $response->body());
                $transaction->update(['status' => 'failed']);
                return ['status' => 'failed', 'error' => 'Payment initiation failed.'];
            }

            $data = $response->json();
            $transaction->update(['provider_reference' => $data['data']['id'] ?? null]);

            return ['status' => 'pending'];

        } catch (\Exception $e) {
            \Log::error('Flutterwave Exception: ' . $e->getMessage());
            return ['status' => 'pending', 'message' => 'Payment request sent. Check your phone.'];
        }
    }

    /**
     * Handle the success logic: split money, credit wallet, and issue an unused voucher.
     */
    public function handleSuccessfulPackagePayment(Tenant $tenant, Package $package, string $phone, Transaction $transaction): Voucher
    {
        return DB::transaction(function () use ($tenant, $package, $phone, $transaction) {
            $transaction = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ($transaction->status !== 'pending') {
                $voucherId = $transaction->meta['voucher_id'] ?? null;

                if ($voucherId) {
                    return Voucher::withoutGlobalScopes()->findOrFail($voucherId);
                }

                throw new \LogicException("Transaction {$transaction->id} has already been processed.");
            }

            $commissionRate = (float) ($tenant->commission_rate ?? PlatformSetting::get('default_commission_rate', 5.00));
            $fee = round($transaction->amount * ($commissionRate / 100), 2);
            $net = round($transaction->amount - $fee, 2);

            // 1. Update Transaction to completed
            $transaction->update([
                'status' => 'completed',
                'platform_fee' => $fee,
                'net_amount' => $net,
                'phone' => $phone,
            ]);

            // 2. Credit Operator Wallet
            $tenant->increment('wallet_balance', $net);

            // 3. Ensure a valid batch exists for online sales
            $batchCode = 'ONLINE-'.$tenant->id.'-'.$package->id;
            $batch = VoucherBatch::withoutGlobalScopes()
                ->where('batch_code', $batchCode)
                ->first()
                ?? VoucherBatch::create([
                    'tenant_id' => $tenant->id,
                    'package_id' => $package->id,
                    'batch_code' => $batchCode,
                    'quantity' => 0,
                    'status' => 'active',
                ]);

            // The voucher timer starts only when the customer activates it.
            $voucher = Voucher::create([
                'tenant_id' => $tenant->id,
                'voucher_batch_id' => $batch->id,
                'package_id' => $package->id,
                'code' => 'ONL-'.strtoupper(Str::random(6)),
                'status' => 'unused',
                'redeemed_by' => $phone,
            ]);

            $transaction->update([
                'meta' => array_merge($transaction->meta ?? [], [
                    'voucher_id' => $voucher->id,
                    'voucher_code' => $voucher->code,
                ]),
            ]);

            // 5. Create or Find the Customer
            Customer::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'phone' => $phone,
                ],
                [
                    'name' => 'Online Customer',
                    'package_id' => $package->id,
                    'status' => 'active',
                ]
            );

            return $voucher;
        });
    }

}