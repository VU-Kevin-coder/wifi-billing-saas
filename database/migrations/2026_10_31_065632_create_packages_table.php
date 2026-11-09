<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->string('currency', 3)->default('UGX');

            // Duration
            $table->integer('duration_value');
            $table->string('duration_unit'); // 'hours', 'days', 'months'

            // Network Limits
            $table->integer('speed_limit_mbps');
            $table->integer('device_limit')->default(1);
            $table->string('data_limit')->nullable(); // e.g., 'Unlimited' or '1GB'

            // Network Mapping
            $table->string('radius_profile_name')->nullable();

            // Status & Features
            $table->boolean('is_published')->default(true);
            $table->boolean('is_popular')->default(false);
            $table->json('features')->nullable(); // ["Hotspot", "PPPoE", "Unlimited data"]

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
