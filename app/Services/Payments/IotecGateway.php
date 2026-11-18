<?php

namespace App\Services\Payments;

use App\Models\PlatformSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class IotecGateway
{
    private const API_URL = 'https://pay.iotec.io';

    private const TOKEN_URL = 'https://id.iotec.io/connect/token';

    public function verifyWebhook(string $authorization): bool
    {
        $secret = PlatformSetting::getSecret('payment.iotec.webhook_secret');
        if (! is_string($secret) || $secret === '' || $authorization === '') {
            return false;
        }

        return hash_equals('Bearer '.$secret, trim($authorization));
    }

    public function collect(
        string $reference,
        int $amount,
        string $phone,
        string $customerName,
        string $packageName,
    ): array {
        return $this->request()->post('/api/collections/collect', [
            'category' => 'MobileMoney',
            'currency' => 'UGX',
            'walletId' => PlatformSetting::get('payment.iotec.wallet_id'),
            'externalId' => $reference,
            'payer' => $phone,
            'payerName' => $customerName,
            'amount' => $amount,
            'payerNote' => 'Wi-Fi package: '.$packageName,
            'payeeNote' => 'Payment reference: '.$reference,
        ])->throw()->json();
    }

    public function disburse(
        string $reference,
        int $amount,
        string $phone,
        string $payeeName,
    ): array {
        return $this->request()->post('/api/disbursements/disburse', [
            'category' => 'MobileMoney',
            'currency' => 'UGX',
            'walletId' => PlatformSetting::get('payment.iotec.wallet_id'),
            'externalId' => $reference,
            'payee' => $phone,
            'payeeName' => $payeeName,
            'amount' => $amount,
            'payeeNote' => 'Wi-Fi operator withdrawal: '.$reference,
        ])->throw()->json();
    }

    public function collectionStatus(string $requestId): array
    {
        return $this->request()->get('/api/collections/status/'.rawurlencode($requestId))
            ->throw()
            ->json();
    }

    public function collectionStatusByExternalId(string $reference): array
    {
        return $this->request()->get('/api/collections/external-id/'.rawurlencode($reference))
            ->throw()
            ->json();
    }

    public function disbursementStatusByExternalId(string $reference): array
    {
        return $this->request()->get('/api/disbursements/external-id/'.rawurlencode($reference))
            ->throw()
            ->json();
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withToken($this->accessToken())
            ->connectTimeout(5)
            ->timeout(20)
            ->baseUrl(self::API_URL);
    }

    private function accessToken(): string
    {
        $clientId = PlatformSetting::getSecret('payment.iotec.client_id');
        $clientSecret = PlatformSetting::getSecret('payment.iotec.client_secret');
        if (! is_string($clientId) || $clientId === ''
            || ! is_string($clientSecret) || $clientSecret === '') {
            throw new RuntimeException('ioTec client credentials are not configured.');
        }

        $cacheKey = 'payment.iotec.access-token.'.hash('sha256', $clientId."\0".$clientSecret);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->connectTimeout(5)
            ->timeout(20)
            ->post(self::TOKEN_URL, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
            ])->throw();

        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in');
        if (! is_string($token) || $token === '' || ! is_numeric($expiresIn) || (int) $expiresIn < 1) {
            throw new RuntimeException('ioTec did not return a valid access token.');
        }

        Cache::put($cacheKey, $token, now()->addSeconds(max(1, (int) $expiresIn - 30)));

        return $token;
    }
}
