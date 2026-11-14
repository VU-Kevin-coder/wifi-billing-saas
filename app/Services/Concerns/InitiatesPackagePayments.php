<?php

namespace App\Services\Concerns;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait InitiatesPackagePayments
{
    public function initiatePackagePayment(
        Tenant $tenant,
        Package $package,
        string $phone,
        Transaction $transaction,
        ?string $email = null,
        ?string $returnUrl = null,
    ): array {
        if ($transaction->status !== 'pending') {
            return ['status' => $transaction->status, 'error' => 'This payment is no longer pending.'];
        }

        if ((string) $package->tenant_id !== (string) $tenant->id
            || (string) $transaction->tenant_id !== (string) $tenant->id
            || $transaction->type !== 'voucher_sale') {
            return ['status' => 'failed', 'error' => 'Payment details are invalid.'];
        }

        if (! empty($transaction->provider_reference)) {
            return ['status' => 'pending'];
        }

        $msisdn = $this->normalizeUgandanPhone($phone);
        $network = $msisdn ? $this->networkForPhone($msisdn) : null;

        if (! $msisdn || ! $network) {
            $this->markFailedIfPending($transaction);

            return ['status' => 'failed', 'error' => 'Enter a valid MTN or Airtel Uganda number.'];
        }

        // 1. Ensure price is a valid whole number (no decimals)
        if ((float) $package->price <= 0
            || (float) $package->price !== (float) round((float) $package->price)
            || (float) $package->price !== (float) $transaction->amount) {
            $this->markFailedIfPending($transaction);

            return ['status' => 'failed', 'error' => 'Mobile money payments require a valid whole-number package price.'];
        }

        // Simulation must be explicitly enabled AND only run in non-production
        // environments. Both gates are required so a stray config value can
        // never issue vouchers without a real payment in production.
        if (config('payments.simulation_enabled', false)
            && app()->environment(['local', 'testing'])) {
            $voucher = $this->handleSuccessfulPackagePayment($tenant, $package, $phone, $transaction);

            return $voucher ? ['status' => 'simulated_success', 'voucher' => $voucher] : ['status' => 'pending'];
        }

        $pesapalKey = PlatformSetting::get('pesapal_consumer_key');
        $pesapalSecret = PlatformSetting::getSecret('pesapal_consumer_secret') ?: PlatformSetting::get('pesapal_consumer_secret');
        if (PlatformSetting::get('pesapal_enabled', true) && filled($pesapalKey) && filled($pesapalSecret)) {
            return $this->initiatePesapalPayment($tenant, $package, $phone, $transaction, $email);
        }

        $currency = strtoupper((string) ($package->currency ?: PlatformSetting::get('currency', 'UGX')));
        if ($currency !== 'UGX') {
            $this->markFailedIfPending($transaction);

            return [
                'status' => 'failed',
                'error' => "Mobile money currently supports UGX packages only. Configure Pesapal for {$currency} packages.",
            ];
        }

        $providerKey = $network === 'MTN' ? 'mtn_enabled' : 'airtel_enabled';
        if (! PlatformSetting::get($providerKey, true)) {
            $this->markFailedIfPending($transaction);

            return ['status' => 'failed', 'error' => "{$network} payments are disabled by platform settings."];
        }

        $configured = $network === 'MTN' ? $this->mtn->isConfigured() : $this->airtel->isConfigured();
        if (! $configured) {
            $this->markFailedIfPending($transaction);

            return ['status' => 'failed', 'error' => "{$network} payments are not configured."];
        }

        $meta = $transaction->meta ?? [];

        $reference = (string) Str::uuid();
        $this->updatePending($transaction, [
            'provider_reference' => $reference,
            'meta' => array_merge($meta, [
                'payment_provider' => $network,
                'provider_submission' => 'submitting',
            ]),
        ]);

        try {
            if ($network === 'MTN') {
                $response = $this->mtn->initiate($transaction, $package, $msisdn, $reference);
                $accepted = $response->successful();
                $rejected = $response->clientError() && $response->status() !== 409;
                $providerReference = $reference;
            } else {
                $result = $this->airtel->initiate($msisdn, (float) $package->price, $reference);
                $response = $result['response'];
                $accepted = $response->successful()
                    && $response->json('status.success') === true;
                $rejected = ($response->clientError() && $response->status() !== 409)
                    || $response->json('status.success') === false;
                $providerReference = (string) $result['provider_reference'];
            }

            $this->updatePending($transaction, [
                'provider_reference' => $providerReference,
                'meta' => array_merge($transaction->meta ?? [], [
                    'provider_submission' => $accepted ? 'submitted' : ($rejected ? 'failed' : 'unknown'),
                ]),
            ]);

            if (! $accepted) {
                if ($rejected) {
                    $this->markFailedIfPending($transaction);
                }

                Log::warning($rejected
                    ? 'Mobile money provider rejected a collection request.'
                    : 'Mobile money collection submission has an unknown outcome.', [
                        'transaction_id' => $transaction->id,
                        'provider' => $network,
                        'http_status' => $response->status(),
                    ]);

                return $rejected
                    ? ['status' => 'failed', 'error' => "{$network} payment could not be initiated."]
                    : ['status' => 'pending', 'message' => 'Payment status is being confirmed. Check your phone.'];
            }

            return ['status' => 'pending'];
        } catch (\Throwable $exception) {
            $this->updatePending($transaction, [
                'meta' => array_merge($transaction->meta ?? [], [
                    'provider_submission' => 'unknown',
                ]),
            ]);
            Log::error('Mobile money collection submission could not be confirmed.', [
                'transaction_id' => $transaction->id,
                'provider' => $network,
                'exception' => $exception::class,
            ]);

            return ['status' => 'pending', 'message' => 'Payment status is being confirmed. Check your phone.'];
        }
    }

    public function initiatePesapalPayment(
        Tenant $tenant,
        Package $package,
        string $phone,
        Transaction $transaction,
        ?string $email = null,
    ): array {
        if (! PlatformSetting::get('pesapal_enabled', true)) {
            $this->markFailedIfPending($transaction);

            return ['status' => 'failed', 'error' => 'Pesapal payments are disabled by platform settings.'];
        }

        $amount = (float) $package->price;
        $consumerKey = PlatformSetting::get('pesapal_consumer_key');
        $consumerSecret = PlatformSetting::getSecret('pesapal_consumer_secret') ?: PlatformSetting::get('pesapal_consumer_secret');
        $environment = PlatformSetting::get('pesapal_environment', 'sandbox');
        $ipnId = PlatformSetting::get('pesapal_ipn_id');

        $msisdn = preg_replace('/\D+/', '', $phone);
        if (strlen($msisdn) === 10 && str_starts_with($msisdn, '0')) {
            $msisdn = '256'.substr($msisdn, 1);
        }

        $baseUrl = $environment === 'production'
            ? 'https://pay.pesapal.com/v3/api'
            : 'https://cybqa.pesapal.com/pesapalv3/api';

        try {
            // 1. Get Access Token
            $tokenResponse = Http::post("{$baseUrl}/Auth/RequestToken", [
                'consumer_key' => $consumerKey,
                'consumer_secret' => $consumerSecret,
            ]);

            if ($tokenResponse->failed()) {
                Log::error('Pesapal Auth Error: '.$tokenResponse->body());
                $this->markFailedIfPending($transaction);

                return ['status' => 'failed', 'error' => 'Pesapal authentication failed.'];
            }

            $token = $tokenResponse->json('token');
            if (! $token) {
                $this->markFailedIfPending($transaction);

                return ['status' => 'failed', 'error' => 'Pesapal auth token not returned.'];
            }

            $callbackUrl = route('portal.pesapal.callback', ['tenant' => $tenant->slug]);

            // 2. Submit Order Request
            $orderResponse = Http::withToken($token)
                ->post("{$baseUrl}/Transactions/SubmitOrderRequest", [
                    'id' => $transaction->reference,
                    'currency' => strtoupper((string) ($package->currency ?: PlatformSetting::get('currency', 'UGX'))),
                    'amount' => $amount,
                    'description' => "Wi-Fi Package: {$package->name}",
                    'callback_url' => $callbackUrl,
                    'notification_id' => $ipnId,
                    'billing_address' => [
                        'phone_number' => $msisdn,
                        'email_address' => $email ?: config('mail.from.address'),
                        'first_name' => 'Hamnet',
                        'last_name' => 'Customer',
                        'country_code' => 'UG',
                    ],
                ]);

            if ($orderResponse->failed()) {
                Log::error('Pesapal Order Error: '.$orderResponse->body());
                $this->markFailedIfPending($transaction);

                return ['status' => 'failed', 'error' => 'Could not create Pesapal order.'];
            }

            $orderData = $orderResponse->json();
            $orderTrackingId = $orderData['order_tracking_id'] ?? null;
            $redirectUrl = $orderData['redirect_url'] ?? null;

            $this->updatePending($transaction, [
                'provider_reference' => $orderTrackingId,
                'meta' => array_merge($transaction->meta ?? [], [
                    'payment_provider' => 'pesapal',
                    'order_tracking_id' => $orderTrackingId,
                    'pesapal_redirect_url' => $redirectUrl,
                ]),
            ]);

            return [
                'status' => 'redirect',
                'redirect_url' => $redirectUrl,
            ];
        } catch (\Throwable $e) {
            Log::error('Pesapal Exception: '.$e->getMessage());

            return ['status' => 'pending', 'message' => 'Payment request sent.'];
        }
    }
}
