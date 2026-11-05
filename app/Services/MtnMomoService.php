<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Transaction;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MtnMomoService
{
    public function isConfigured(): bool
    {
        return filled(PlatformSetting::getSecret('mtn_subscription_key'))
            && filled(PlatformSetting::getSecret('mtn_api_user'))
            && filled(PlatformSetting::getSecret('mtn_api_key'));
    }

    public function initiate(Transaction $transaction, Package $package, string $msisdn, string $referenceId): Response
    {
        return $this->request()
            ->withToken($this->accessToken())
            ->withHeaders([
                'X-Reference-Id' => $referenceId,
                'X-Target-Environment' => $this->targetEnvironment(),
            ])
            ->post($this->baseUrl().'/collection/v1_0/requesttopay', [
                'amount' => rtrim(rtrim(number_format((float) $package->price, 2, '.', ''), '0'), '.'),
                'currency' => $this->environment() === 'sandbox' ? 'EUR' : 'UGX',
                'externalId' => $transaction->reference,
                'payer' => [
                    'partyIdType' => 'MSISDN',
                    'partyId' => $msisdn,
                ],
                'payerMessage' => 'Payment for '.$package->name,
                'payeeNote' => 'WiFi voucher',
            ]);
    }

    public function verify(string $referenceId): array
    {
        return $this->request()
            ->withToken($this->accessToken())
            ->withHeaders(['X-Target-Environment' => $this->targetEnvironment()])
            ->get($this->baseUrl().'/collection/v1_0/requesttopay/'.rawurlencode($referenceId))
            ->throw()
            ->json();
    }

    private function accessToken(): string
    {
        $tokenResponse = $this->request()
            ->withBasicAuth(
                (string) PlatformSetting::getSecret('mtn_api_user'),
                (string) PlatformSetting::getSecret('mtn_api_key')
            )
            ->withHeader('X-Target-Environment', $this->targetEnvironment())
            ->post($this->baseUrl().'/collection/token/');
        $tokenResponse->throw();

        $token = $tokenResponse->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('MTN MoMo did not return an access token.');
        }

        return $token;
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withHeader('Ocp-Apim-Subscription-Key', (string) PlatformSetting::getSecret('mtn_subscription_key'))
            ->timeout(20);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.mtn_momo.'.$this->environment().'_url'), '/');
    }

    private function environment(): string
    {
        return PlatformSetting::get('mtn_environment', 'sandbox') === 'production' ? 'production' : 'sandbox';
    }

    private function targetEnvironment(): string
    {
        return $this->environment() === 'sandbox' ? 'sandbox' : 'mtnuganda';
    }
}
