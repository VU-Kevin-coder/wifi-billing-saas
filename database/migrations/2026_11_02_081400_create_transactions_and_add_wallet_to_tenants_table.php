<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'wallet_balance')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->decimal('wallet_balance', 14, 2)->default(0);
                $table->decimal('commission_rate', 5, 2)->default(5.00);
            });
        }

        if (! Schema::hasTable('transactions')) {
            Schema::create('transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('type')->default('voucher_sale');
                $table->decimal('amount', 12, 2);
                $table->decimal('platform_fee', 12, 2)->default(0);
                $table->decimal('net_amount', 12, 2)->default(0);
                $table->string('status')->default('pending');
                $table->string('reference')->unique();
                $table->string('phone')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');

        if (Schema::hasColumn('tenants', 'wallet_balance')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn(['wallet_balance', 'commission_rate']);
            });
        }
    }
};
