<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AirtelMoneyService
{
    public function isConfigured(): bool
    {
        return filled(PlatformSetting::getSecret('airtel_client_id'))
            && filled(PlatformSetting::getSecret('airtel_client_secret'));
    }

    public function initiate(string $msisdn, float $amount, string $reference): array
    {
        $tokenResponse = $this->request()
            ->post($this->baseUrl().'/auth/oauth2/token', [
                'client_id' => PlatformSetting::getSecret('airtel_client_id'),
                'client_secret' => PlatformSetting::getSecret('airtel_client_secret'),
                'grant_type' => 'client_credentials',
            ]);
        $tokenResponse->throw();

        $token = $tokenResponse->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Airtel Money did not return an access token.');
        }

        $response = $this->request()
            ->withToken($token)
            ->withHeaders([
                'X-Country' => 'UG',
                'X-Currency' => 'UGX',
            ])
            ->post($this->baseUrl().'/merchant/v1/payments/', [
                'reference' => $reference,
                'subscriber' => [
                    'country' => 'UG',
                    'currency' => 'UGX',
                    'msisdn' => $msisdn,
                ],
                'transaction' => [
                    'amount' => $amount,
                    'country' => 'UG',
                    'currency' => 'UGX',
                    'id' => $reference,
                ],
            ]);

        return [
            'response' => $response,
            'provider_reference' => $response->json('data.transaction.id') ?: $reference,
        ];
    }

    public function verify(string $reference): array
    {
        $tokenResponse = $this->request()
            ->post($this->baseUrl().'/auth/oauth2/token', [
                'client_id' => PlatformSetting::getSecret('airtel_client_id'),
                'client_secret' => PlatformSetting::getSecret('airtel_client_secret'),
                'grant_type' => 'client_credentials',
            ]);
        $tokenResponse->throw();

        $token = $tokenResponse->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Airtel Money did not return an access token.');
        }

        return $this->request()
            ->withToken($token)
            ->withHeaders([
                'X-Country' => 'UG',
                'X-Currency' => 'UGX',
            ])
            ->get($this->baseUrl().'/standard/v1/payments/'.rawurlencode($reference))
            ->throw()
            ->json();
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->asJson()->timeout(20);
    }

    private function baseUrl(): string
    {
        $environment = PlatformSetting::get('airtel_environment', 'sandbox') === 'production'
            ? 'production'
            : 'sandbox';

        return rtrim((string) config('services.airtel_money.'.$environment.'_url'), '/');
    }
}
