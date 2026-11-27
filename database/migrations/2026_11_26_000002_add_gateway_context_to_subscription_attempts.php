<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_attempts', function (Blueprint $table) {
            $table->string('gateway_provider', 32)->nullable();
            $table->char('gateway_account_fingerprint', 64)->nullable();
            $table->string('gateway_environment', 16)->nullable();
            $table->text('checkout_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_attempts', function (Blueprint $table) {
            $table->dropColumn([
                'gateway_provider',
                'gateway_account_fingerprint',
                'gateway_environment',
                'checkout_url',
            ]);
        });
    }
};
