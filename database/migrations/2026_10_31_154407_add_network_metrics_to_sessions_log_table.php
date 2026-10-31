<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the sessions_log table with full network metrics columns.
     * Named as "add_network_metrics" because the base table
     * was part of the earlier planned sprint; this single migration
     * creates it with all columns including network telemetry.
     */
    public function up(): void
    {
        Schema::create('sessions_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->foreignId('router_id')->nullable()->constrained('routers')->nullOnDelete();

            $table->string('status')->default('active'); // active | expired | disconnected
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();

            // Network identity
            $table->string('ip_address', 45)->nullable();
            $table->string('mac_address', 17)->nullable();

            // Data usage metrics (bytes)
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedBigInteger('bytes_out')->default(0);

            // Live rate metrics (bits per second)
            $table->unsignedBigInteger('rate_in')->default(0);
            $table->unsignedBigInteger('rate_out')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions_log');
    }
};
