<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table): void {
            $table->char('polling_token_hash', 64)->nullable()->unique();
            $table->timestamp('polling_last_seen_at')->nullable();
        });

        Schema::create('router_commands', function (Blueprint $table): void {
            $table->id();
            $table->uuid('command_id')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('router_id');
            $table->unsignedBigInteger('session_id');
            $table->string('operation', 32);
            $table->string('idempotency_key', 128);
            $table->string('state', 24)->default('pending_api');
            $table->unsignedSmallInteger('api_attempt_count')->default(0);
            $table->unsignedSmallInteger('poll_attempt_count')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->string('lease_owner', 16)->nullable();
            $table->char('lease_token_hash', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('delivered_via', 16)->nullable();
            $table->string('result_code', 32)->nullable();
            $table->timestamps();

            $table->unique(['router_id', 'idempotency_key'], 'router_commands_router_idempotency_unique');
            $table->index(['state', 'available_at'], 'router_commands_state_available_index');
            $table->index(['router_id', 'state', 'available_at'], 'router_commands_router_state_index');
            $table->foreign(['tenant_id', 'router_id'], 'router_commands_tenant_router_fk')
                ->references(['tenant_id', 'id'])
                ->on('routers')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'router_id', 'session_id'], 'router_commands_session_fk')
                ->references(['tenant_id', 'router_id', 'id'])
                ->on('sessions_log')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('router_commands') && DB::table('router_commands')->exists()) {
            throw new RuntimeException('Cannot roll back populated router command delivery records.');
        }

        Schema::dropIfExists('router_commands');

        if (Schema::hasColumn('routers', 'polling_token_hash')) {
            Schema::table('routers', function (Blueprint $table): void {
                $table->dropUnique(['polling_token_hash']);
                $table->dropColumn(['polling_token_hash', 'polling_last_seen_at']);
            });
        }
    }
};
