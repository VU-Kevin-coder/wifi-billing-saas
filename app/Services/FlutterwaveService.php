<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FlutterwaveService
{
    private const API_URL = 'https://api.flutterwave.com/v3';

    public function isConfigured(): bool
    {
        return filled($this->secretKey());
    }

    public function charge(array $payload): Response
    {
        return $this->request()->post(self::API_URL.'/charges?type=mobile_money_uganda', $payload);
    }

    public function verifyTransaction(string $transactionId): array
    {
        return $this->request()->get(self::API_URL.'/transactions/'.rawurlencode($transactionId).'/verify')
            ->throw()
            ->json();
    }

    public function createTransfer(array $payload): array
    {
        return $this->request()->post(self::API_URL.'/transfers', $payload)
            ->throw()
            ->json();
    }

    public function verifyTransfer(string $transferId): array
    {
        return $this->request()->get(self::API_URL.'/transfers/'.rawurlencode($transferId))
            ->throw()
            ->json();
    }

    /**
     * Look up one of our transfers by the merchant reference we sent when
     * creating it. Returns the transfer only on an exact reference match, so a
     * loose or unsupported provider-side filter can never match another payout.
     */
    public function findTransferByReference(string $reference): ?array
    {
        $response = $this->request()
            ->get(self::API_URL.'/transfers', ['reference' => $reference])
            ->throw()
            ->json();

        foreach ((array) ($response['data'] ?? []) as $transfer) {
            if (is_array($transfer) && ($transfer['reference'] ?? null) === $reference) {
                return $transfer;
            }
        }

        return null;
    }

    public static function normalizeUgandanPhone(string $phone): ?string
    {
        $number = preg_replace('/\D+/', '', $phone);

        if (strlen($number) === 10 && str_starts_with($number, '0')) {
            $number = '256'.substr($number, 1);
        } elseif (strlen($number) === 9) {
            $number = '256'.$number;
        }

        return preg_match('/^256\d{9}$/', $number) ? $number : null;
    }

    public static function networkForUgandanPhone(string $phone): string
    {
        $number = self::normalizeUgandanPhone($phone);
        $prefix = $number ? substr($number, 3, 2) : '';

        return in_array($prefix, ['70', '74', '75'], true) ? 'AIRTEL' : 'MTN';
    }

    private function request(): PendingRequest
    {
        $secretKey = $this->secretKey();
        if (blank($secretKey)) {
            throw new RuntimeException('Flutterwave secret key is not configured.');
        }

        return Http::acceptJson()
            ->asJson()
            ->withToken($secretKey)
            ->timeout(20);
    }

    private function secretKey(): ?string
    {
        $key = PlatformSetting::getSecret('flutterwave_secret_key', config('services.flutterwave.secret_key'));

        return is_string($key) && $key !== '' ? $key : null;
    }
}
