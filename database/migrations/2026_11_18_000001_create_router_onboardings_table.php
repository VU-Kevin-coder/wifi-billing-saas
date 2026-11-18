<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_onboardings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('bootstrap_token_hash', 64)->unique();
            $table->text('bootstrap_script');
            $table->text('bootstrap_command');
            $table->string('status', 32)->default('awaiting_bootstrap');
            $table->unsignedTinyInteger('bootstrap_fetches')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_onboardings');
    }
};
