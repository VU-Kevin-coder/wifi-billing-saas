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
use Throwable;

class PaymentGatewayService
{
    public function __construct(
        private readonly ObPayGateway $obpay,
        private readonly PawaPayGateway $pawapay,
        private readonly XenteGateway $xente,
    ) {}

    public function initiateCollection(
        Tenant $tenant,
        Package $package,
        string $phone,
        string $customerName,
        ?string $email,
        Transaction $transaction,
    ): array {
        $provider = PaymentGatewaySettings::active();
        if (! $provider || ! PaymentGatewaySettings::configured($provider)) {
            return $this->failCollection($transaction, 'Online payments are not currently configured.');
        }

        if (strtoupper((string) ($package->currency ?: PlatformSetting::get('currency', 'UGX'))) !== 'UGX'
            || (float) $package->price <= 0
            || (float) $package->price !== (float) round((float) $package->price)
            || (float) $package->price !== (float) $transaction->amount) {
            return $this->failCollection($transaction, 'Online mobile-money payments require a whole-number UGX package price.');
        }

        $normalizedPhone = $this->normalizeUgandanPhone($phone);
        $network = $this->networkForPhone($normalizedPhone ?? '');
        if (! $normalizedPhone || ! $network) {
            return $this->failCollection($transaction, 'Enter a valid Uganda MTN or Airtel number.');
        }

        $providerReference = $provider === 'pawapay' ? (string) Str::uuid() : $transaction->reference;
        $transaction->update([
            'provider_reference' => $providerReference,
            'meta' => array_merge($transaction->meta ?? [], [
                'payment_provider' => $provider,
                'gateway_reference' => $transaction->reference,
                'provider_submission' => 'submitting',
                'payment_network' => $network,
                'customer_name' => $customerName,
            ]),
        ]);

        try {
            $result = match ($provider) {
                'obpay' => $this->startObPayCollection($transaction, $package, $normalizedPhone, $customerName, $email),
                'pawapay' => $this->startPawaPayCollection($transaction, $package, $normalizedPhone),
                'xente' => $this->startXenteCollection($transaction, $package, $normalizedPhone, $customerName, $email, $network),
            };

            $transaction->refresh();
            $transaction->update([
                'meta' => array_merge($transaction->meta ?? [], [
                    'provider_submission' => $result['accepted'] ? 'submitted' : ($result['rejected'] ? 'failed' : 'unknown'),
                    'provider_transaction_id' => $result['provider_transaction_id'] ?? null,
                ]),
                ...($result['rejected'] ? ['status' => 'failed'] : []),
            ]);

            if ($result['rejected']) {
                return ['status' => 'failed', 'error' => 'The payment provider rejected the request.'];
            }

            return ['status' => 'pending'];
        } catch (Throwable $exception) {
            $transaction->refresh();
            $transaction->update([
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
                'xente' => $this->xente->transaction((string) ($transaction->meta['provider_transaction_id'] ?? '')),
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
            'xente' => $payload['requestId'] ?? null,
            default => null,
        };
        if (! is_string($reference) || $reference === '') {
            return 'missing_reference';
        }

        $query = Transaction::withoutTenancy()
            ->where('type', 'voucher_sale')
            ->where('status', 'pending')
            ->where('meta->payment_provider', $provider);

        if ($provider === 'xente') {
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

    public function networkForUgandanPhone(string $phone): ?string
    {
        $normalizedPhone = $this->normalizeUgandanPhone($phone);

        return $normalizedPhone ? $this->networkForPhone($normalizedPhone) : null;
    }

    private function startObPayCollection(Transaction $transaction, Package $package, string $phone, string $name, ?string $email): array
    {
        $response = $this->obpay->collect(
            $transaction->reference,
            (int) $package->price,
            $phone,
            $name,
            $email,
            route('api.webhooks.obpay'),
        );
        $data = $response['data'] ?? [];
        $accepted = ($response['success'] ?? false) === true
            && ($data['reference'] ?? null) === $transaction->reference
            && strtolower((string) ($data['status'] ?? '')) === 'pending';

        return ['accepted' => $accepted, 'rejected' => ! $accepted, 'provider_transaction_id' => $data['reference'] ?? null];
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

    private function startXenteCollection(Transaction $transaction, Package $package, string $phone, string $name, ?string $email, string $network): array
    {
        $productItemId = $this->xente->productItemId('collection', strtolower($network));
        if (! $productItemId) {
            return ['accepted' => false, 'rejected' => true];
        }

        $response = $this->xente->collect(
            $transaction->reference,
            $productItemId,
            (int) $package->price,
            $phone,
            $name,
            $email,
        );
        $data = $response['data'] ?? [];
        $accepted = (int) ($response['code'] ?? -1) === 0
            && ($data['requestId'] ?? null) === $transaction->reference
            && is_string($data['transactionId'] ?? null)
            && $data['transactionId'] !== '';

        return [
            'accepted' => $accepted,
            'rejected' => (int) ($response['code'] ?? -1) !== 0,
            'provider_transaction_id' => $data['transactionId'] ?? null,
        ];
    }

    private function normalizeCollectionStatus(string $provider, string $reference, Transaction $transaction, array $details): array
    {
        $status = match ($provider) {
            'obpay' => strtolower((string) ($details['status'] ?? '')),
            'pawapay' => strtoupper((string) ($details['status'] ?? '')),
            'xente' => strtoupper((string) ($details['status'] ?? $details['transactionStatus'] ?? '')),
        };
        $returnedReference = match ($provider) {
            'obpay' => $details['reference'] ?? null,
            'pawapay' => $details['depositId'] ?? null,
            'xente' => $details['requestId'] ?? $details['clientReferenceId'] ?? null,
        };
        $returnedAmount = $details['amount'] ?? null;
        $returnedCurrency = strtoupper((string) ($details['currency'] ?? $details['currencyCode'] ?? ''));
        $expectedReference = $provider === 'xente' ? $transaction->reference : $reference;

        if ($returnedReference !== $expectedReference
            || ($provider === 'pawapay' && ($details['clientReferenceId'] ?? null) !== $transaction->reference)
            || ! is_numeric($returnedAmount)
            || (float) $returnedAmount !== (float) $transaction->amount
            || $returnedCurrency !== 'UGX') {
            Log::error('Verified payment details did not match the local transaction.', [
                'transaction_id' => $transaction->id,
                'provider' => $provider,
            ]);

            return ['status' => 'mismatch'];
        }

        if (in_array($status, ['success', 'completed', 'committed', 'successful'], true)) {
            return ['status' => 'completed'];
        }
        if (in_array($status, ['failed', 'rejected', 'reversed'], true)) {
            return ['status' => 'failed'];
        }

        return ['status' => 'pending'];
    }

    private function networkForPhone(string $phone): ?string
    {
        $prefix = substr($phone, 3, 2);

        return match ($prefix) {
            '70', '74', '75' => 'AIRTEL',
            '76', '77', '78', '79' => 'MTN',
            default => null,
        };
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
