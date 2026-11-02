<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., Growth
            $table->decimal('price', 10, 2); // e.g., 180000
            $table->integer('trial_days')->default(14); // Free trial period
            $table->integer('max_customers')->default(2000); // Capacity
            $table->integer('max_routers')->default(10);
            $table->json('features')->nullable(); // e.g., ["Priority Support", "Custom Domain"]
            $table->timestamps();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('platform_plan_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['platform_plan_id']);
            $table->dropColumn('platform_plan_id');
        });

        Schema::dropIfExists('platform_plans');
    }
};
