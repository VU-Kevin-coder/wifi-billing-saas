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
        return filled(PlatformSetting::getSecret('payment.obpay.secret_key'))
            && filled(PlatformSetting::getSecret('payment.obpay.webhook_secret'));
    }

    public function verifyWebhook(string $rawBody, string $signature): bool
    {
        $secret = PlatformSetting::getSecret('payment.obpay.webhook_secret');
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

    private function request(): PendingRequest
    {
        $secret = PlatformSetting::getSecret('payment.obpay.secret_key');
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('OBPay secret key is not configured.');
        }

        return Http::acceptJson()
            ->asJson()
            ->withToken($secret)
            ->connectTimeout(5)
            ->timeout(20)
            ->baseUrl(self::API_URL);
    }
}
