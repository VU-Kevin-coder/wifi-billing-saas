<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Basic Info
            $table->string('name'); // e.g., NTD-CORE-01
            $table->string('serial_number')->nullable();
            $table->string('hardware_model')->nullable(); // e.g., CCR2004

            // Network
            $table->string('management_ip')->nullable();
            $table->string('site')->nullable(); // e.g., Ntinda Hub

            // Status & Telemetry
            $table->string('status')->default('offline'); // online, offline, provisioning
            $table->integer('cpu_usage')->default(0);
            $table->integer('memory_usage')->default(0);
            $table->integer('active_users')->default(0);
            $table->timestamp('last_seen_at')->nullable();

            // Credentials (Encrypted)
            $table->string('api_username')->default('sopraent_user');
            $table->text('api_password')->nullable();
            $table->integer('api_port')->default(8728);

            // Onboarding & Service Setup
            $table->boolean('has_wireless')->default(false);
            $table->boolean('enable_hotspot')->default(true);
            $table->json('hotspot_config')->nullable();
            $table->json('ethernet_ports')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routers');
    }
};
