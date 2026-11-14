<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_fulfillment_work', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('transaction_id')->unique();
            $table->unsignedBigInteger('package_id');
            $table->string('package_fingerprint', 64);
            $table->string('phone');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error_class')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'transaction_id'], 'sale_work_tenant_transaction_fk')
                ->references(['tenant_id', 'id'])->on('transactions')->restrictOnDelete();
            $table->index(['status', 'next_attempt_at']);
        });
        Schema::table('wallet_ledger_entries', function (Blueprint $table): void {
            // No historic backfill. New sale credits carry a unique transaction guard.
            $table->unsignedBigInteger('sale_credit_transaction_id')->nullable()->unique('ledger_sale_credit_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('sale_fulfillment_work')->exists()
            || DB::table('wallet_ledger_entries')->whereNotNull('sale_credit_transaction_id')->exists()) {
            throw new RuntimeException('Cannot remove populated payment durability records.');
        }
        Schema::table('wallet_ledger_entries', function (Blueprint $table): void {
            $table->dropUnique('ledger_sale_credit_unique');
            $table->dropColumn('sale_credit_transaction_id');
        });
        Schema::dropIfExists('sale_fulfillment_work');
    }
};
