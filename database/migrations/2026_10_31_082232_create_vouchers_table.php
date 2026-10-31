<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voucher_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();

            $table->string('code')->unique();           // e.g., KL-D7XP-4M2A
            $table->string('status')->default('unused'); // unused, redeemed, voided
            $table->string('assigned_site')->nullable(); // Optional site mapping
            $table->string('redeemed_by')->nullable();   // Customer phone/name
            $table->timestamp('redeemed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
