<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Transaction;

class PesapalOrderVerifier
{
    public function findTransaction(
        ?string $orderTrackingId,
        ?string $merchantReference,
        ?string $tenantId = null
    ): ?Transaction {
        if (! filled($orderTrackingId) || ! filled($merchantReference)) {
            return null;
        }

        $query = Transaction::withoutGlobalScopes()
            ->where('type', 'voucher_sale')
            ->where('provider_reference', $orderTrackingId)
            ->where('reference', $merchantReference);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->first();
    }

    public function matchesMerchantReference(Transaction $transaction, array $providerDetails): bool
    {
        $providerReference = $providerDetails['merchant_reference'] ?? null;

        return is_string($providerReference)
            && $providerReference !== ''
            && hash_equals((string) $transaction->reference, $providerReference);
    }

    public function matchesCompletedPayment(
        Transaction $transaction,
        Package $package,
        array $providerDetails
    ): bool {
        if ((string) $package->tenant_id !== (string) $transaction->tenant_id
            || ! $this->matchesMerchantReference($transaction, $providerDetails)) {
            return false;
        }

        $providerCurrency = strtoupper(trim((string) ($providerDetails['currency'] ?? '')));
        $expectedCurrency = strtoupper(trim((string) (
            $package->currency ?: PlatformSetting::get('currency', 'UGX')
        )));

        return $providerCurrency !== ''
            && $providerCurrency === $expectedCurrency
            && $this->amountsMatch($providerDetails['amount'] ?? null, $transaction->amount)
            && $this->amountsMatch($transaction->amount, $package->price);
    }

    private function amountsMatch(mixed $actual, mixed $expected): bool
    {
        return is_numeric($actual)
            && is_numeric($expected)
            && is_finite((float) $actual)
            && is_finite((float) $expected)
            && (float) $actual === (float) $expected;
    }
}
