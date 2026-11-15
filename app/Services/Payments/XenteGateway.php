<?php

namespace App\Services\Payments;

use App\Models\PlatformSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class XenteGateway
{
    private const API_URL = 'https://api.xente.co';

    public function configured(): bool
    {
        return filled(PlatformSetting::getSecret('payment.xente.app_key'))
            && filled(PlatformSetting::getSecret('payment.xente.app_password'))
            && filled(PlatformSetting::getSecret('payment.xente.user_id'));
    }

    public function productItemId(string $operation, string $network): ?string
    {
        $key = "payment.xente.{$operation}_{$network}";
        $value = PlatformSetting::get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function collect(
        string $requestId,
        string $productItemId,
        int $amount,
        string $phone,
        string $customerName,
        ?string $email,
    ): array {
        return $this->request()->post('/api/transactions/collections/mobilemoney', [
            'requestId' => $requestId,
            'batchId' => $requestId,
            'accountNumber' => $phone,
            'amount' => $amount,
            'type' => 'CREDIT',
            'message' => 'Wi-Fi package purchase',
            'metadata' => json_encode(['reference' => $requestId], JSON_THROW_ON_ERROR),
            'provider' => ['providerItemId' => $productItemId],
            'beneficiary' => [
                'id' => $requestId,
                'name' => $customerName,
                'emailAddress' => $email ?? '',
                'phoneNumber' => $phone,
                'data' => ['phoneNumber' => $phone],
            ],
        ])->throw()->json();
    }

    public function payout(
        string $requestId,
        string $productItemId,
        int $amount,
        string $phone,
        string $customerName,
    ): array {
        return $this->request()->post('/api/transactions/payouts/mobilemoney', [
            'requestId' => $requestId,
            'batchId' => $requestId,
            'amount' => $amount,
            'type' => 'DEBIT',
            'message' => 'Operator wallet withdrawal',
            'metadata' => json_encode(['reference' => $requestId], JSON_THROW_ON_ERROR),
            'product' => ['productItemId' => $productItemId],
            'beneficiary' => [
                'name' => $customerName,
                'emailAddress' => '',
                'phoneNumber' => $phone,
                'data' => ['phoneNumber' => $phone],
            ],
        ])->throw()->json();
    }

    public function transaction(string $transactionId): array
    {
        return $this->request()->get('/api/transactions/'.rawurlencode($transactionId))
            ->throw()
            ->json();
    }

    public function products(): array
    {
        return $this->request()->get('/products')->throw()->json();
    }

    public function availableProductItems(): array
    {
        $catalog = $this->products();
        $products = $catalog['data']['collection'] ?? $catalog['data'] ?? [];
        if (! is_array($products)) {
            throw new RuntimeException('Xente product catalog returned an unsupported response.');
        }

        $items = [];
        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }
            $productId = $product['productId'] ?? $product['product_id'] ?? null;
            if (! is_string($productId) || ! preg_match('/MOBILEMONEY|AIRTELMONEY/i', $productId)) {
                continue;
            }

            $detail = $this->request()->get('/products/'.rawurlencode($productId))->throw()->json();
            $productItems = $detail['data']['collection']
                ?? $detail['data']['product_items']
                ?? $detail['data']['productItems']
                ?? [];
            foreach ($productItems as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $itemId = $item['productItemId'] ?? $item['item_id'] ?? null;
                $name = $item['name'] ?? $item['item_name'] ?? $product['name'] ?? $productId;
                if (! is_string($itemId) || $itemId === '') {
                    continue;
                }

                $identity = strtoupper(implode(' ', array_filter([
                    $productId,
                    $itemId,
                    $name,
                    $item['provider'] ?? null,
                    $item['network'] ?? null,
                    $item['operation'] ?? null,
                    $item['transactionType'] ?? null,
                    $item['type'] ?? null,
                    $item['description'] ?? null,
                ], 'is_string')));
                $network = str_contains($identity, 'AIRTEL') ? 'airtel' : (str_contains($identity, 'MTN') ? 'mtn' : null);
                if (! $network) {
                    continue;
                }

                $operation = str_contains($identity, 'PAYOUT') || str_contains($identity, 'DEBIT') ? 'payout'
                    : (str_contains($identity, 'COLLECTION') || str_contains($identity, 'COLLECT') || str_contains($identity, 'CREDIT') ? 'collection' : null);
                if (! $operation) {
                    continue;
                }

                $items[] = [
                    'operation' => $operation,
                    'network' => $network,
                    'id' => $itemId,
                    'name' => (string) $name,
                ];
            }
        }

        return $items;
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withToken($this->accessToken(), 'Token')
            ->connectTimeout(5)
            ->timeout(20)
            ->baseUrl(self::API_URL);
    }

    private function accessToken(): string
    {
        $appKey = PlatformSetting::getSecret('payment.xente.app_key');
        $appPassword = PlatformSetting::getSecret('payment.xente.app_password');
        $userId = PlatformSetting::getSecret('payment.xente.user_id');
        if (! is_string($appKey) || $appKey === ''
            || ! is_string($appPassword) || $appPassword === ''
            || ! is_string($userId) || $userId === '') {
            throw new RuntimeException('Xente application credentials are not configured.');
        }

        $cacheKey = 'payment.xente.token.'.hash('sha256', $appKey);

        return Cache::remember($cacheKey, now()->addMinutes(55), function () use ($appKey, $appPassword, $userId): string {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->post(self::API_URL.'/api/auth/login', [
                    'appKey' => $appKey,
                    'appPassword' => $appPassword,
                    'userId' => $userId,
                    'includeRefereshToken' => false,
                ])
                ->throw()
                ->json();

            $token = $response['Token'] ?? $response['token'] ?? $response['data']['Token'] ?? $response['data']['token'] ?? null;
            if (($response['success'] ?? true) === false
                || ! is_string($token) || $token === '') {
                throw new RuntimeException('Xente authentication did not return an access token.');
            }

            return $token;
        });
    }
}
