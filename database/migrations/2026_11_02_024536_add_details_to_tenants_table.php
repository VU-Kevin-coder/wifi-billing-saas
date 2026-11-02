<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Account Information
            $table->string('legal_name')->nullable();
            $table->text('address')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();

            // Plan & Subscription
            $table->string('plan')->default('Growth');
            $table->decimal('plan_fee', 10, 2)->default(180000);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();
            $table->string('subscription_status')->default('trial'); // trial, active, past_due, suspended
            $table->boolean('auto_renew')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'legal_name', 'address', 'contact_name', 'contact_email', 'contact_phone',
                'plan', 'plan_fee', 'trial_ends_at', 'subscription_ends_at', 'subscription_status', 'auto_renew',
            ]);
        });
    }
};
