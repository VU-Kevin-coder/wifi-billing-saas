<?php

namespace App\Services\Payments;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PaymentGatewayService
{
    public function __construct(
        private readonly ObPayGateway $obpay,
        private readonly PawaPayGateway $pawapay,
        private readonly IotecGateway $iotec,
    ) {}

    public function initiateCollection(
        Tenant $tenant,
        Package $package,
        string $phone,
        string $customerName,
        ?string $email,
        Transaction $transaction,
        ?string $returnUrl = null,
    ): array {
        $provider = PaymentGatewaySettings::active();
        if (! $provider || ! PaymentGatewaySettings::configured($provider)) {
            return $this->failCollection($transaction, 'Online payments are not currently configured.');
        }

        $currency = strtoupper((string) ($package->currency ?: PlatformSetting::get('currency', 'UGX')));
        if ($currency !== 'UGX'
            || (float) $package->price <= 0
            || (float) $package->price !== (float) round((float) $package->price)
            || (float) $package->price !== (float) $transaction->amount) {
            return $this->failCollection($transaction, 'Online mobile-money payments require a whole-number UGX package price.');
        }
        if ($provider === 'iotec' && (float) $package->price < 500) {
            return $this->failCollection($transaction, 'ioTec mobile-money payments require a minimum package price of UGX 500.');
        }

        $normalizedPhone = $this->normalizeUgandanPhone($phone);
        $network = $this->networkForPhone($normalizedPhone ?? '');
        if (! $normalizedPhone || ! $network) {
            return $this->failCollection($transaction, 'Enter a valid Uganda MTN or Airtel number.');
        }

        $providerReference = match ($provider) {
            'pawapay' => (string) Str::uuid(),
            default => $transaction->reference,
        };
        $this->updatePending($transaction, [
            'provider_reference' => $providerReference,
            'meta' => [
                'payment_provider' => $provider,
                'gateway_reference' => $transaction->reference,
                'provider_submission' => 'submitting',
                'payment_network' => $network,
                'customer_name' => $customerName,
            ],
        ]);

        try {
            $result = match ($provider) {
                'obpay' => $this->startObPayCollection($transaction, $package, $normalizedPhone, $customerName, $email, $returnUrl),
                'pawapay' => $this->startPawaPayCollection($transaction, $package, $normalizedPhone),
                'iotec' => $this->startIotecCollection($transaction, $package, $normalizedPhone, $customerName),
            };

            $transaction->refresh();
            $meta = array_merge($transaction->meta ?? [], [
                'provider_submission' => $result['accepted'] ? 'submitted' : ($result['rejected'] ? 'failed' : 'unknown'),
                'provider_transaction_id' => $result['provider_transaction_id'] ?? null,
            ]);
            if (is_bool($result['prompt_sent'] ?? null)) {
                $meta['provider_prompt_sent'] = $result['prompt_sent'];
            }
            $attributes = ['meta' => $meta];
            if (is_string($result['provider_reference'] ?? null)) {
                $attributes['provider_reference'] = $result['provider_reference'];
            }
            if ($result['rejected']) {
                $attributes['status'] = 'failed';
            }
            $this->updatePending($transaction, $attributes);

            if ($result['rejected']) {
                return [
                    'status' => 'failed',
                    'error' => $result['error'] ?? 'The payment provider rejected the request.',
                ];
            }
            if (is_string($result['redirect_url'] ?? null)) {
                return ['status' => 'redirect', 'redirect_url' => $result['redirect_url']];
            }

            return ['status' => 'pending'];
        } catch (Throwable $exception) {
            $transaction->refresh();
            $this->updatePending($transaction, [
                'meta' => array_merge($transaction->meta ?? [], ['provider_submission' => 'unknown']),
            ]);
            Log::error('Payment provider collection submission could not be confirmed.', [
                'transaction_id' => $transaction->id,
                'provider' => $provider,
                'exception' => $exception::class,
            ]);

            return ['status' => 'pending', 'message' => 'Payment status is being confirmed. Check your phone.'];
        }
    }

    public function verifyCollection(Transaction $transaction): array
    {
        $provider = $transaction->meta['payment_provider'] ?? null;
        $reference = $transaction->provider_reference;
        if (! is_string($provider) || ! in_array($provider, PaymentGatewaySettings::PROVIDERS, true)
            || ! is_string($reference) || $reference === '') {
            return ['status' => 'pending'];
        }

        try {
            $response = match ($provider) {
                'obpay' => $this->obpay->transaction($reference),
                'pawapay' => $this->pawapay->depositStatus($reference),
                'iotec' => is_string($transaction->meta['provider_transaction_id'] ?? null)
                    ? $this->iotec->collectionStatus($transaction->meta['provider_transaction_id'])
                    : $this->iotec->collectionStatusByExternalId($transaction->reference),
            };
        } catch (Throwable $exception) {
            Log::warning('Unable to verify an online payment.', [
                'transaction_id' => $transaction->id,
                'provider' => $provider,
                'exception' => $exception::class,
            ]);

            return ['status' => 'unavailable'];
        }

        $details = is_array($response['data'] ?? null) ? $response['data'] : $response;

        return $this->normalizeCollectionStatus($provider, $reference, $transaction, $details);
    }

    public function reconcileCollectionWebhook(string $provider, array $payload): string
    {
        $reference = match ($provider) {
            'obpay' => $payload['data']['reference'] ?? null,
            'pawapay' => $payload['depositId'] ?? null,
            'iotec' => $payload['externalId'] ?? null,
            default => null,
        };
        if (! is_string($reference) || $reference === '') {
            return 'missing_reference';
        }

        $query = Transaction::withoutTenancy()
            ->where('type', 'voucher_sale')
            ->where('status', 'pending')
            ->where('meta->payment_provider', $provider);
        if ($provider === 'iotec') {
            $query->where('reference', $reference);
        } else {
            $query->where('provider_reference', $reference);
        }

        $transaction = $query->first();
        if (! $transaction) {
            return 'ignored';
        }

        return app(PaymentService::class)->reconcilePendingPayment($transaction, $provider);
    }

    public function normalizeUgandanPhone(string $phone): ?string
    {
        $number = preg_replace('/\D+/', '', $phone);
        if (strlen($number) === 10 && str_starts_with($number, '0')) {
            $number = '256'.substr($number, 1);
        } elseif (strlen($number) === 9) {
            $number = '256'.$number;
        }

        return preg_match('/^256\d{9}$/', $number) ? $number : null;
    }

    private function startObPayCollection(
        Transaction $transaction,
        Package $package,
        string $phone,
        string $name,
        ?string $email,
        ?string $returnUrl,
    ): array {
        $response = $this->obpay->collect(
            $transaction->reference,
            (int) $package->price,
            $phone,
            $name,
            $email,
            $this->obpay->callbackUrl(),
            $this->isHttpsUrl($returnUrl) ? $returnUrl : null,
        );
        $data = $response['data'] ?? [];
        $accepted = ($response['success'] ?? false) === true
            && ($data['reference'] ?? null) === $transaction->reference
            && strtolower((string) ($data['status'] ?? '')) === 'pending';
        $promptSent = is_bool($data['prompt_sent'] ?? null) ? $data['prompt_sent'] : null;
        $redirectUrl = $promptSent === false && $this->isHttpsUrl($data['checkout_url'] ?? null)
            ? $data['checkout_url']
            : null;

        return [
            'accepted' => $accepted,
            'rejected' => ! $accepted,
            'provider_transaction_id' => $data['reference'] ?? null,
            'prompt_sent' => $promptSent,
            'redirect_url' => $redirectUrl,
            'error' => is_string($response['error'] ?? null) && trim($response['error']) !== ''
                ? Str::limit(trim($response['error']), 200)
                : null,
        ];
    }

    private function isHttpsUrl(mixed $value): bool
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($value);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    private function startPawaPayCollection(Transaction $transaction, Package $package, string $phone): array
    {
        $prediction = $this->pawapay->predictProvider($phone);
        $provider = $prediction['provider'] ?? null;
        $canonicalPhone = $prediction['phoneNumber'] ?? null;
        if (! is_string($provider) || ! is_string($canonicalPhone) || $canonicalPhone === '') {
            return ['accepted' => false, 'rejected' => true];
        }

        $response = $this->pawapay->deposit(
            (string) $transaction->provider_reference,
            $transaction->reference,
            (int) $package->price,
            $canonicalPhone,
            $provider,
        );
        $accepted = ($response['status'] ?? null) === 'ACCEPTED'
            && ($response['depositId'] ?? null) === $transaction->provider_reference;

        return [
            'accepted' => $accepted,
            'rejected' => ! in_array($response['status'] ?? null, ['ACCEPTED', 'DUPLICATE_IGNORED'], true),
            'provider_transaction_id' => $response['depositId'] ?? null,
        ];
    }

    private function startIotecCollection(
        Transaction $transaction,
        Package $package,
        string $phone,
        string $customerName,
    ): array {
        $response = $this->iotec->collect(
            $transaction->reference,
            (int) $package->price,
            $phone,
            $customerName,
            $package->name,
        );
        $id = $response['id'] ?? null;
        $status = strtoupper((string) ($response['status'] ?? ''));
        if (! is_string($id) || $id === '' || ($response['externalId'] ?? null) !== $transaction->reference) {
            throw new RuntimeException('ioTec returned a collection without matching transaction details.');
        }
        if (in_array($status, ['FAILED', 'REJECTED', 'CANCELLED'], true)) {
            return ['accepted' => false, 'rejected' => true, 'provider_transaction_id' => $id];
        }
        if (! in_array($status, ['PENDING', 'SENTTOVENDOR', 'SUCCESS'], true)) {
            throw new RuntimeException('ioTec returned an unrecognized collection status.');
        }

        return [
            'accepted' => true,
            'rejected' => false,
            'provider_reference' => $id,
            'provider_transaction_id' => $id,
        ];
    }

    private function normalizeCollectionStatus(
        string $provider,
        string $reference,
        Transaction $transaction,
        array $details,
    ): array {
        $status = strtoupper((string) ($details['status'] ?? $details['transactionStatus'] ?? ''));
        $returnedAmount = $details['amount'] ?? null;
        $returnedCurrency = strtoupper((string) ($details['currency'] ?? $details['currencyCode'] ?? ''));
        $referenceMatches = match ($provider) {
            'obpay' => ($details['reference'] ?? null) === $reference,
            'pawapay' => ($details['depositId'] ?? null) === $reference
                && ($details['clientReferenceId'] ?? null) === $transaction->reference,
            'iotec' => ($details['externalId'] ?? null) === $transaction->reference
                && (! is_string($transaction->meta['provider_transaction_id'] ?? null)
                    || ($details['id'] ?? null) === $transaction->meta['provider_transaction_id']),
        };

        if (! $referenceMatches
            || ! is_numeric($returnedAmount)
            || (float) $returnedAmount !== (float) $transaction->amount
            || $returnedCurrency !== 'UGX') {
            Log::error('Verified payment details did not match the local transaction.', [
                'transaction_id' => $transaction->id,
                'provider' => $provider,
            ]);

            return ['status' => 'mismatch'];
        }

        if (in_array($status, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'COMMITTED'], true)) {
            return ['status' => 'completed'];
        }
        if (in_array($status, ['FAILED', 'REJECTED', 'REVERSED', 'CANCELLED'], true)) {
            return ['status' => 'failed'];
        }

        return ['status' => 'pending'];
    }

    public function networkForPhone(string $phone): ?string
    {
        return match (substr($phone, 3, 2)) {
            '70', '74', '75' => 'AIRTEL',
            '76', '77', '78', '79' => 'MTN',
            default => null,
        };
    }

    private function updatePending(Transaction $transaction, array $attributes): void
    {
        DB::transaction(function () use ($transaction, $attributes): void {
            $locked = Transaction::withoutTenancy()
                ->where('tenant_id', $transaction->tenant_id)
                ->lockForUpdate()
                ->findOrFail($transaction->id);
            if ($locked->status !== 'pending') {
                return;
            }
            if (isset($attributes['meta'])) {
                $attributes['meta'] = array_merge($locked->meta ?? [], $attributes['meta']);
            }
            $locked->update($attributes);
        });
        $transaction->refresh();
    }

    private function failCollection(Transaction $transaction, string $error): array
    {
        DB::transaction(function () use ($transaction): void {
            $locked = Transaction::withoutTenancy()
                ->where('tenant_id', $transaction->tenant_id)
                ->lockForUpdate()
                ->findOrFail($transaction->id);
            if ($locked->status === 'pending' && empty($locked->provider_reference)) {
                $locked->update(['status' => 'failed']);
            }
        });

        return ['status' => 'failed', 'error' => $error];
    }
}
