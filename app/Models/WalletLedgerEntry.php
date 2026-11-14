<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable line of a tenant's wallet statement.
 */
class WalletLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'transaction_id', 'user_id', 'type', 'amount',
        'balance_before', 'balance_after', 'reference', 'description', 'meta', 'sale_credit_transaction_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Wallet ledger entries cannot be modified.'));
        static::deleting(fn () => throw new \LogicException('Wallet ledger entries cannot be deleted.'));
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
