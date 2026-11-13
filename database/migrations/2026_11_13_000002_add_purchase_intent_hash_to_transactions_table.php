<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('purchase_intent_hash', 64)->nullable();
            $table->index(
                ['tenant_id', 'status', 'purchase_intent_hash'],
                'transactions_purchase_intent_lookup_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_purchase_intent_lookup_index');
            $table->dropColumn('purchase_intent_hash');
        });
    }
};
