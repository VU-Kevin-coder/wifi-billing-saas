<?php

namespace App\Services\Payments;

use App\Models\PlatformSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ObPayGateway
{
    private const API_URL = 'https://obpay.online/api/public/v1';

    public function configured(): bool
    {
        return filled($this->secretKey()) && filled($this->webhookSecret());
    }

    public function verifyWebhook(string $rawBody, string $signature): bool
    {
        $secret = $this->webhookSecret();
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
    ): array {
        return $this->request()->post('/collect', [
            'amount' => $amount,
            'currency' => 'UGX',
            'phone_number' => $phone,
            'customer_name' => $customerName,
            'customer_email' => $email ?? '',
            'reference' => $reference,
            'callback_url' => $callbackUrl,
        ])->throw()->json();
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

    public function ping(): bool
    {
        $response = $this->request()->get('/ping');

        return $response->successful() && $response->json('success') === true;
    }

    private function request(): PendingRequest
    {
        $secret = $this->secretKey();
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('ObPay secret key is not configured.');
        }

        return Http::acceptJson()
            ->asJson()
            ->withToken($secret)
            ->connectTimeout(5)
            ->timeout(20)
            ->baseUrl(self::API_URL);
    }

    private function secretKey(): mixed
    {
        return PlatformSetting::getSecret('payment.obpay.secret_key');
    }

    private function webhookSecret(): mixed
    {
        return PlatformSetting::getSecret('payment.obpay.webhook_secret');
    }
}
