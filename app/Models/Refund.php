<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'transaction_id', 'amount', 'tenant_debit', 'platform_fee_reversed',
        'reason', 'status', 'revoke_access', 'requested_by', 'decided_by', 'decided_at',
        'decision_note', 'payout_reference', 'refund_transaction_id', 'wallet_ledger_entry_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tenant_debit' => 'decimal:2',
        'platform_fee_reversed' => 'decimal:2',
        'revoke_access' => 'boolean',
        'decided_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
