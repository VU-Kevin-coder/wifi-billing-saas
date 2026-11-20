<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ObPayGateway
{
    public function configured(): bool
    {
        return PaymentGatewaySettings::configured('obpay');
    }

    public function verifyWebhook(string $rawBody, string $signature): bool
    {
        $secret = PaymentGatewaySettings::obpayWebhookSecret();
        if (! is_string($secret) || $secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    public function collect(
        string $reference,
        int $amount,
        string $phone,
        string $customerName,
        ?string $email,
        string $callbackUrl,
        ?string $redirectUrl = null,
    ): array {
        $payload = [
            'amount' => $amount,
            'currency' => 'UGX',
            'phone_number' => $phone,
            'customer_name' => $customerName,
            'customer_email' => $email ?? '',
            'reference' => $reference,
            'callback_url' => $callbackUrl,
        ];
        if ($redirectUrl !== null) {
            $payload['redirect_url'] = $redirectUrl;
        }

        return $this->request()->post('/collect', $payload)->throw()->json();
    }

    public function callbackUrl(): string
    {
        return PaymentGatewaySettings::obpayCallbackUrl();
    }

    public function payout(
        string $reference,
        int $amount,
        string $phone,
        string $customerName,
        string $callbackUrl,
    ): array {
        return $this->request()->post('/payouts', [
            'amount' => $amount,
            'currency' => 'UGX',
            'phone_number' => $phone,
            'customer_name' => $customerName,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
        ])->throw()->json();
    }

    public function transaction(string $reference): array
    {
        return $this->request()->get('/transactions/'.rawurlencode($reference))
            ->throw()
            ->json();
    }

    private function request(): PendingRequest
    {
        $secret = PaymentGatewaySettings::obpaySecretKey();
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('OBPay secret key is not configured.');
        }

        return Http::acceptJson()
            ->asJson()
            ->withToken($secret)
            ->connectTimeout(5)
            ->timeout(20)
            ->baseUrl(PaymentGatewaySettings::obpayBaseUrl());
    }
}
