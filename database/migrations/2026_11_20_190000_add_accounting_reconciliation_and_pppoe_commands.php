<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions_log', function (Blueprint $table): void {
            $table->unsignedBigInteger('session_duration_seconds')->default(0);
            $table->timestamp('last_interim_at')->nullable();
            $table->timestamp('accounting_stopped_at')->nullable();
        });

        Schema::table('radius_accounting_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('session_time_seconds')->default(0);
            $table->string('acct_unique_session_id', 255)->nullable();
        });

        Schema::table('radius_accounting_events', function (Blueprint $table): void {
            $table->string('acct_unique_session_id', 255)->nullable();
        });

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteRouterCommands(true);
        } else {
            Schema::table('router_commands', function (Blueprint $table): void {
                $table->dropForeign('router_commands_session_fk');
                $table->unsignedBigInteger('session_id')->nullable()->change();
                $table->string('target_service', 16)->default('hotspot');
                $table->string('target_username', 64)->nullable();
                $table->unsignedBigInteger('subscriber_session_id')->nullable();
                $table->foreign(['tenant_id', 'router_id', 'session_id'], 'router_commands_session_fk')
                    ->references(['tenant_id', 'router_id', 'id'])
                    ->on('sessions_log')
                    ->restrictOnDelete();
                $table->foreign('subscriber_session_id', 'router_commands_subscriber_session_fk')
                    ->references('id')
                    ->on('radius_subscriber_sessions')
                    ->restrictOnDelete();
                $table->index(
                    ['router_id', 'subscriber_session_id', 'state'],
                    'router_commands_subscriber_state_index',
                );
            });
        }

        DB::table('router_commands')
            ->whereNotNull('session_id')
            ->whereNull('target_username')
            ->orderBy('id')
            ->chunkById(100, function ($commands): void {
                foreach ($commands as $command) {
                    $username = DB::table('sessions_log')
                        ->join('vouchers', 'vouchers.id', '=', 'sessions_log.voucher_id')
                        ->where('sessions_log.id', $command->session_id)
                        ->value('vouchers.code');
                    if (is_string($username)) {
                        DB::table('router_commands')
                            ->where('id', $command->id)
                            ->update(['target_username' => $username]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('router_commands')
            && DB::table('router_commands')
                ->whereNull('session_id')
                ->orWhereNotNull('subscriber_session_id')
                ->exists()) {
            throw new RuntimeException('Cannot roll back router commands targeting PPPoE sessions.');
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteRouterCommands(false);
        } else {
            Schema::table('router_commands', function (Blueprint $table): void {
                $table->dropIndex('router_commands_subscriber_state_index');
                $table->dropForeign('router_commands_subscriber_session_fk');
                $table->dropForeign('router_commands_session_fk');
                $table->dropColumn(['target_service', 'target_username', 'subscriber_session_id']);
                $table->unsignedBigInteger('session_id')->nullable(false)->change();
                $table->foreign(['tenant_id', 'router_id', 'session_id'], 'router_commands_session_fk')
                    ->references(['tenant_id', 'router_id', 'id'])
                    ->on('sessions_log')
                    ->restrictOnDelete();
            });
        }

        Schema::table('radius_accounting_events', function (Blueprint $table): void {
            $table->dropColumn('acct_unique_session_id');
        });

        Schema::table('radius_accounting_sessions', function (Blueprint $table): void {
            $table->dropColumn(['session_time_seconds', 'acct_unique_session_id']);
        });

        Schema::table('sessions_log', function (Blueprint $table): void {
            $table->dropColumn([
                'session_duration_seconds',
                'last_interim_at',
                'accounting_stopped_at',
            ]);
        });
    }

    private function rebuildSqliteRouterCommands(bool $includePppoeColumns): void
    {
        Schema::create('router_commands_rebuild', function (Blueprint $table) use ($includePppoeColumns): void {
            $table->id();
            $table->uuid('command_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('router_id');
            $table->unsignedBigInteger('session_id')->nullable($includePppoeColumns);
            if ($includePppoeColumns) {
                $table->string('target_service', 16)->default('hotspot');
                $table->string('target_username', 64)->nullable();
                $table->unsignedBigInteger('subscriber_session_id')->nullable();
            }
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

            $table->foreign(['tenant_id', 'router_id'], 'router_commands_tenant_router_fk')
                ->references(['tenant_id', 'id'])
                ->on('routers')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'router_id', 'session_id'], 'router_commands_session_fk')
                ->references(['tenant_id', 'router_id', 'id'])
                ->on('sessions_log')
                ->restrictOnDelete();
            if ($includePppoeColumns) {
                $table->foreign('subscriber_session_id', 'router_commands_subscriber_session_fk')
                    ->references('id')
                    ->on('radius_subscriber_sessions')
                    ->restrictOnDelete();
            }
        });

        $columns = [
            'id',
            'command_id',
            'tenant_id',
            'router_id',
            'session_id',
        ];
        $source = DB::table('router_commands')->select($columns);
        $destination = $columns;
        if ($includePppoeColumns) {
            array_push($destination, 'target_service', 'target_username', 'subscriber_session_id');
            $source->addSelect(DB::raw("'hotspot'"), DB::raw('NULL'), DB::raw('NULL'));
        }
        array_push(
            $destination,
            'operation',
            'idempotency_key',
            'state',
            'api_attempt_count',
            'poll_attempt_count',
            'available_at',
            'lease_owner',
            'lease_token_hash',
            'lease_expires_at',
            'delivered_via',
            'result_code',
            'created_at',
            'updated_at',
        );
        $source->addSelect([
            'operation',
            'idempotency_key',
            'state',
            'api_attempt_count',
            'poll_attempt_count',
            'available_at',
            'lease_owner',
            'lease_token_hash',
            'lease_expires_at',
            'delivered_via',
            'result_code',
            'created_at',
            'updated_at',
        ]);
        DB::table('router_commands_rebuild')->insertUsing($destination, $source);

        Schema::drop('router_commands');
        Schema::rename('router_commands_rebuild', 'router_commands');
        Schema::table('router_commands', function (Blueprint $table) use ($includePppoeColumns): void {
            $table->unique('command_id', 'router_commands_command_id_unique');
            $table->unique(['router_id', 'idempotency_key'], 'router_commands_router_idempotency_unique');
            $table->index(['state', 'available_at'], 'router_commands_state_available_index');
            $table->index(['router_id', 'state', 'available_at'], 'router_commands_router_state_index');
            if ($includePppoeColumns) {
                $table->index(
                    ['router_id', 'subscriber_session_id', 'state'],
                    'router_commands_subscriber_state_index',
                );
            }
        });
    }
};
