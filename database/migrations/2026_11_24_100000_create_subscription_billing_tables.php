<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('plan_id'); // snapshot; deliberately not cascaded
            $table->string('plan_name');
            $table->unsignedBigInteger('amount'); // whole UGX, not float
            $table->string('currency', 3);
            $table->string('status')->default('open');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('entitlement_applied_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'id']);
        });
        Schema::create('subscription_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_invoice_id')->constrained('subscription_invoices')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('phone_hash', 64);
            $table->uuid('reference')->unique();
            $table->string('status')->default('unknown');
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique('subscription_invoice_id'); // no retry until reconciliation is implemented
        });
        Schema::create('subscription_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_invoice_id')->constrained('subscription_invoices')->restrictOnDelete();
            $table->foreignId('subscription_attempt_id')->nullable()->constrained('subscription_attempts')->restrictOnDelete();
            $table->string('event');
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_history');
        Schema::dropIfExists('subscription_attempts');
        Schema::dropIfExists('subscription_invoices');
    }
};
