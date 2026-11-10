<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every SMS the platform sends on a tenant's behalf. Enables the monthly cap,
 * per-tenant cost metering, and "did the reminder bring the customer back?".
 * The message body is deliberately not stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->string('type', 30);                 // expiry_reminder
            $table->string('recipient', 20);            // E.164
            $table->string('status', 20);               // sent | failed
            $table->unsignedSmallInteger('segments')->default(1);
            $table->string('detail', 255)->nullable();  // gateway message, truncated
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'type', 'status', 'created_at']);
            $table->index(['session_id', 'status']);
            $table->index(['recipient', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
    }
};
