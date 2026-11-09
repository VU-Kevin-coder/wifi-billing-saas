<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An append-only ledger for every change to a tenant's wallet. The
 * `tenants.wallet_balance` column stays as the fast, spendable balance, but it
 * must always equal the sum of the ledger (checked by `wallet:reconcile`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_ledger_entries', function (Blueprint $table) {
            $table->id();
            // restrict: a tenant with financial history must not be deletable by accident.
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 40);            // opening_balance, sale_credit, withdrawal_debit, withdrawal_refund, adjustment, refund_debit
            $table->decimal('amount', 14, 2);      // signed: credits positive, debits negative
            $table->decimal('balance_before', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->string('reference', 100)->nullable();
            $table->string('description', 255)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'id']);
            $table->index(['tenant_id', 'type', 'created_at']);
            $table->index('transaction_id');
        });

        // Opening balance: make the ledger agree with the balances that already exist.
        DB::table('tenants')
            ->where('wallet_balance', '!=', 0)
            ->orderBy('id')
            ->chunkById(100, function ($tenants): void {
                $rows = [];
                foreach ($tenants as $tenant) {
                    $rows[] = [
                        'tenant_id' => $tenant->id,
                        'type' => 'opening_balance',
                        'amount' => $tenant->wallet_balance,
                        'balance_before' => 0,
                        'balance_after' => $tenant->wallet_balance,
                        'description' => 'Balance carried over when the wallet ledger was introduced',
                        'created_at' => now(),
                    ];
                }
                DB::table('wallet_ledger_entries')->insert($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger_entries');
    }
};
