<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            // The completed sale being (partly) refunded.
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);                    // refunded to the customer
            $table->decimal('tenant_debit', 12, 2)->nullable();  // taken from the operator's wallet when approved
            $table->decimal('platform_fee_reversed', 12, 2)->nullable();
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending');    // pending, approved, rejected, cancelled
            $table->boolean('revoke_access')->default(true);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->string('payout_reference', 100)->nullable(); // how/where the customer was paid back
            $table->foreignId('refund_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->unsignedBigInteger('wallet_ledger_entry_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['transaction_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
