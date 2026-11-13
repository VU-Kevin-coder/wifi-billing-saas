<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('sessions_log')->nullOnDelete();
            $table->string('mac_address', 17)->nullable();
            $table->string('status', 20)->default('blocked');
            $table->text('reason')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['router_id', 'mac_address']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_devices');
    }
};
