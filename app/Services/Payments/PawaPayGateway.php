<?php

namespace App\Services\Payments;

use App\Models\PlatformSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PawaPayGateway
{
    public function predictProvider(string $phone): array
    {
        return $this->request()->post('/v2/predict-provider', [
            'phoneNumber' => $phone,
        ])->throw()->json();
    }

    public function deposit(
        string $depositId,
        string $reference,
        int $amount,
        string $phone,
        string $provider,
    ): array {
        return $this->request()->post('/v2/deposits', [
            'depositId' => $depositId,
            'amount' => (string) $amount,
            'currency' => 'UGX',
            'payer' => [
                'type' => 'MMO',
                'accountDetails' => [
                    'phoneNumber' => $phone,
                    'provider' => $provider,
                ],
            ],
            'clientReferenceId' => $reference,
        ])->throw()->json();
    }

    public function payout(
        string $payoutId,
        string $reference,
        int $amount,
        string $phone,
        string $provider,
    ): array {
        return $this->request()->post('/v2/payouts', [
            'payoutId' => $payoutId,
            'amount' => (string) $amount,
            'currency' => 'UGX',
            'recipient' => [
                'type' => 'MMO',
                'accountDetails' => [
                    'phoneNumber' => $phone,
                    'provider' => $provider,
                ],
            ],
            'clientReferenceId' => $reference,
        ])->throw()->json();
    }

    public function depositStatus(string $depositId): array
    {
        return $this->request()->get('/v2/deposits/'.rawurlencode($depositId))
            ->throw()
            ->json();
    }

    public function payoutStatus(string $payoutId): array
    {
        return $this->request()->get('/v2/payouts/'.rawurlencode($payoutId))
            ->throw()
            ->json();
    }

    private function request(): PendingRequest
    {
        $token = PlatformSetting::getSecret('payment.pawapay.api_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('PawaPay API token is not configured.');
        }

        $baseUrl = PlatformSetting::get('payment.pawapay.environment', 'sandbox') === 'production'
            ? 'https://api.pawapay.io'
            : 'https://api.sandbox.pawapay.io';

        return Http::acceptJson()
            ->asJson()
            ->withToken($token)
            ->connectTimeout(5)
            ->timeout(20)
            ->baseUrl($baseUrl);
    }
}
