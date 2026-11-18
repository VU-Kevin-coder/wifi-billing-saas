<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table): void {
            $table->boolean('enable_pppoe')->default(false);
            $table->json('pppoe_config')->nullable();
        });

        Schema::create('subscribers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('phone', 50)->nullable();
            $table->string('username', 64);
            $table->text('password');
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('bytes_used')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'username']);
            $table->index(['tenant_id', 'status', 'expires_at']);
        });

        Schema::create('radius_subscriber_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->string('nas_session_id', 255);
            $table->unsignedInteger('generation')->default(1);
            $table->unsignedBigInteger('input_bytes')->default(0);
            $table->unsignedBigInteger('output_bytes')->default(0);
            $table->string('state', 16)->default('open');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();

            $table->unique(['router_id', 'nas_session_id', 'generation']);
            $table->index(['tenant_id', 'subscriber_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radius_subscriber_sessions');
        Schema::dropIfExists('subscribers');

        Schema::table('routers', function (Blueprint $table): void {
            $table->dropColumn(['enable_pppoe', 'pppoe_config']);
        });
    }
};
