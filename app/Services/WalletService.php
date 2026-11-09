<?php

namespace App\Services;

use App\Exceptions\InsufficientWalletBalance;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\WalletLedgerEntry;

/**
 * The ONLY place that may change `tenants.wallet_balance`. Every change locks
 * the tenant row, updates the balance and appends a ledger entry in the caller's
 * database transaction, so the balance can always be explained line by line.
 * (tests/Feature/WalletLedgerTest fails if any other code writes the balance.)
 */
class WalletService
{
    public const SALE_CREDIT = 'sale_credit';
    public const WITHDRAWAL_DEBIT = 'withdrawal_debit';
    public const WITHDRAWAL_REFUND = 'withdrawal_refund';
    public const ADJUSTMENT = 'adjustment';
    public const REFUND_DEBIT = 'refund_debit';

    /**
     * MUST be called inside a DB::transaction().
     *
     * @param  float|int|string  $amount  signed: credits positive, debits negative
     *
     * @throws InsufficientWalletBalance when a debit would take the balance below zero
     */
    public function apply(
        Tenant|int $tenant,
        float|int|string $amount,
        string $type,
        ?Transaction $transaction = null,
        ?string $description = null,
        ?string $reference = null,
        ?int $userId = null,
        ?array $meta = null,
    ): WalletLedgerEntry {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;
        $amount = round((float) $amount, 2);

        $locked = Tenant::query()->lockForUpdate()->findOrFail($tenantId);
        $before = round((float) $locked->wallet_balance, 2);
        $after = round($before + $amount, 2);

        if ($after < 0) {
            throw new InsufficientWalletBalance($before, abs($amount));
        }

        $locked->forceFill(['wallet_balance' => $after])->save();

        // Keep a caller-held model in step with the database.
        if ($tenant instanceof Tenant) {
            $tenant->setAttribute('wallet_balance', $after);
            $tenant->syncOriginalAttribute('wallet_balance');
        }

        return WalletLedgerEntry::create([
            'tenant_id' => $tenantId,
            'transaction_id' => $transaction?->getKey(),
            'user_id' => $userId ?? auth()->id(),
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'reference' => $reference ?? $transaction?->reference,
            'description' => $description,
            'meta' => $meta,
        ]);
    }
}
