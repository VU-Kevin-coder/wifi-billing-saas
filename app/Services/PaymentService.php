<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mtn\MomoApi\DTO\Party;
use Mtn\MomoApi\Facades\Momo;
use Throwable;

class PaymentService
{
    /**
     * Initiate payment for a package (Online Purchase).
     */
    public function initiatePackagePayment(Tenant $tenant, Package $package, string $phone, Transaction $transaction): array
    {
        $amount = (float) $package->price;
        
        // Check if we are in Simulation Mode or Real Sandbox/Production
        $mtnApiKey = PlatformSetting::get('mtn_api_key');
        
        if (empty($mtnApiKey)) {
            // SIMULATION MODE: Instantly complete the transaction and grant access
            $this->handleSuccessfulPackagePayment($tenant, $package, $phone, $transaction);
            return [
                'status' => 'simulated_success',
                'message' => 'Simulated payment approved instantly. Voucher generated and operator wallet credited.',
            ];
        }

        // REAL MTN API CALL (When credentials are added)
        try {
            self::configureMomo();

            $currency = PlatformSetting::get('currency', 'UGX');
            $env = PlatformSetting::get('mtn_environment', 'sandbox');
            if ($env === 'sandbox' && $currency === 'UGX') {
                $currency = 'EUR';
            }

            $referenceId = Momo::collection()->requestToPay(
                amount: (string) $amount,
                payer: Party::msisdn($phone),
                externalId: $transaction->reference,
                payerMessage: "Payment for {$package->name}",
                payeeNote: "WiFi Voucher",
                currency: $currency
            );
            
            $transaction->update(['reference' => $referenceId]);
            return [
                'status' => 'pending',
                'reference_id' => $referenceId,
            ];
            
        } catch (Throwable $e) {
            $transaction->update(['status' => 'failed']);
            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle the success logic: split money, credit wallet, create voucher & session.
     */
    public function handleSuccessfulPackagePayment(Tenant $tenant, Package $package, string $phone, Transaction $transaction): Voucher
    {
        return DB::transaction(function () use ($tenant, $package, $phone, $transaction) {
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
            $batch = VoucherBatch::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'package_id' => $package->id,
                    'batch_code' => 'ONLINE-' . $package->id,
                ],
                [
                    'quantity' => 0,
                    'status' => 'active',
                ]
            );

            // 4. Generate the Online Voucher NOW (so it doesn't clash with printed ones)
            $voucher = Voucher::create([
                'tenant_id' => $tenant->id,
                'voucher_batch_id' => $batch->id,
                'package_id' => $package->id,
                'code' => 'ONL-' . strtoupper(Str::random(6)),
                'status' => 'redeemed', // Instantly redeemed
                'redeemed_at' => now(),
                'redeemed_by' => $phone,
            ]);

            // 5. Create or Find the Customer
            $customer = Customer::firstOrCreate(
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

            // 6. Calculate expiration date based on duration
            $val = (int) ($package->duration_value ?: 2);
            $unit = strtolower($package->duration_unit ?: 'hours');
            $expiresAt = match ($unit) {
                'minutes', 'minute' => now()->addMinutes($val),
                'days', 'day' => now()->addDays($val),
                'weeks', 'week' => now()->addWeeks($val),
                'months', 'month' => now()->addMonths($val),
                default => now()->addHours($val),
            };

            $clientIp = (app()->bound('request') && request()) ? (request()->ip() ?? '10.50.1.' . rand(10, 250)) : '10.50.1.' . rand(10, 250);

            // 7. Create the Session
            Session::create([
                'tenant_id' => $tenant->id,
                'customer_id' => $customer->id,
                'voucher_id' => $voucher->id,
                'package_id' => $package->id,
                'status' => 'active',
                'started_at' => now(),
                'expires_at' => $expiresAt,
                'ip_address' => $clientIp,
                'bytes_in' => 1024 * 128,
                'bytes_out' => 1024 * 512,
                'rate_in' => 1024 * 64,
                'rate_out' => 1024 * 256,
            ]);

            // 8. Send SMS if SMS gateway is configured
            if (!empty(PlatformSetting::get('sms_api_key')) && !empty($phone) && $phone !== 'Walk-in') {
                try {
                    $brand = $tenant->name ?? PlatformSetting::get('platform_name', 'Hamnet');
                    $duration = "{$package->duration_value} {$package->duration_unit}";
                    $smsMsg = "{$brand}: Payment of UGX " . number_format($package->price) . " received. Your WiFi Voucher Code is: {$voucher->code} ({$duration}). Thank you!";
                    SmsService::send($phone, $smsMsg);
                } catch (Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('SMS dispatch error during package purchase: ' . $e->getMessage());
                }
            }

            return $voucher;
        });
    }

    /**
     * Dynamically configure the MTN MoMo client from PlatformSetting credentials.
     */
    public static function configureMomo(): void
    {
        $env = PlatformSetting::get('mtn_environment', 'sandbox');
        $apiUser = PlatformSetting::get('mtn_api_user');
        $apiKey = PlatformSetting::get('mtn_api_key');
        $subKey = PlatformSetting::get('mtn_subscription_key');
        $callback = PlatformSetting::get('mtn_callback_url', url('/api/webhooks/mtn'));

        config([
            'momo.environment' => $env,
            'momo.collection.subscription_key' => $subKey,
            'momo.collection.api_user' => $apiUser,
            'momo.collection.api_key' => $apiKey,
            'momo.collection.target_environment' => $env,
            'momo.collection.callback_host' => parse_url($callback, PHP_URL_HOST) ?? 'localhost',
        ]);
    }
}
