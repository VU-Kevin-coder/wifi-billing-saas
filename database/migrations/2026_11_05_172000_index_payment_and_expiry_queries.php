<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('payment_token_hash', 64)->nullable();
            $table->index('payment_token_hash', 'transactions_payment_token_hash_index');
            $table->index(
                ['tenant_id', 'type', 'status', 'created_at'],
                'transactions_tenant_type_status_created_index',
            );
        });

        DB::table('transactions')
            ->select(['id', 'meta'])
            ->whereNotNull('meta')
            ->orderBy('id')
            ->chunkById(500, function ($transactions): void {
                foreach ($transactions as $transaction) {
                    $meta = is_string($transaction->meta)
                        ? json_decode($transaction->meta, true)
                        : null;
                    $hash = is_array($meta) ? ($meta['payment_token_hash'] ?? null) : null;
                    if (is_string($hash) && strlen($hash) === 64) {
                        DB::table('transactions')
                            ->where('id', $transaction->id)
                            ->update(['payment_token_hash' => $hash]);
                    }
                }
            });

        Schema::table('sessions_log', function (Blueprint $table) {
            $table->index(['status', 'expires_at'], 'sessions_log_status_expires_index');
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->index(['tenant_id', 'status'], 'vouchers_tenant_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropIndex('vouchers_tenant_status_index');
        });

        Schema::table('sessions_log', function (Blueprint $table) {
            $table->dropIndex('sessions_log_status_expires_index');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_payment_token_hash_index');
            $table->dropIndex('transactions_tenant_type_status_created_index');
            $table->dropColumn('payment_token_hash');
        });
    }
};
