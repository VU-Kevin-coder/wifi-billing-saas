<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RESERVATIONS_TABLE = 'radius_device_reservations';

    public function up(): void
    {
        $routerModeAdded = Schema::hasTable('routers') && ! Schema::hasColumn('routers', 'access_mode');
        if ($routerModeAdded) {
            Schema::table('routers', function (Blueprint $table): void {
                $table->string('access_mode', 16)->default('site_agent');
            });

            if (Schema::hasColumn('routers', 'access_backend')) {
                DB::table('routers')->update([
                    'access_mode' => DB::raw('access_backend'),
                ]);
            }
        }

        if (Schema::hasTable('sessions_log') && ! Schema::hasColumn('sessions_log', 'access_mode')) {
            Schema::table('sessions_log', function (Blueprint $table): void {
                $table->string('access_mode', 16)->default('site_agent');
            });
        }

        $this->createReservationTable();
    }

    public function down(): void
    {
        if (Schema::hasTable(self::RESERVATIONS_TABLE)
            && DB::table(self::RESERVATIONS_TABLE)->exists()) {
            throw new RuntimeException('Cannot roll back populated RADIUS device reservations.');
        }

        if (Schema::hasColumn('sessions_log', 'access_mode')
            && DB::table('sessions_log')->where('access_mode', '<>', 'site_agent')->exists()) {
            throw new RuntimeException('Cannot roll back session access mode while a session uses RADIUS.');
        }

        if (Schema::hasColumn('routers', 'access_mode')
            && ! Schema::hasColumn('routers', 'access_backend')
            && DB::table('routers')->where('access_mode', '<>', 'site_agent')->exists()) {
            throw new RuntimeException('Cannot roll back router access mode without preserving legacy selector data.');
        }

        if (Schema::hasColumn('routers', 'access_mode')
            && Schema::hasColumn('routers', 'access_backend')) {
            DB::table('routers')->update([
                'access_backend' => DB::raw('access_mode'),
            ]);
        }

        if (Schema::hasTable(self::RESERVATIONS_TABLE)) {
            Schema::drop(self::RESERVATIONS_TABLE);
        }

        if (Schema::hasColumn('sessions_log', 'access_mode')) {
            Schema::table('sessions_log', function (Blueprint $table): void {
                $table->dropColumn('access_mode');
            });
        }

        if (Schema::hasColumn('routers', 'access_mode')) {
            Schema::table('routers', function (Blueprint $table): void {
                $table->dropColumn('access_mode');
            });
        }
    }

    private function createReservationTable(): void
    {
        if (Schema::hasTable(self::RESERVATIONS_TABLE)) {
            return;
        }

        Schema::create(self::RESERVATIONS_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('identity_id');
            $table->unsignedBigInteger('generation');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('router_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('accounting_session_id')->nullable();
            $table->string('state', 16)->default('reserved');
            $table->timestamp('reserved_at')->useCurrent();
            $table->timestamp('bound_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['identity_id', 'generation'], 'radius_device_reservations_identity_generation_unique');
            $table->index(['tenant_id', 'router_id', 'session_id', 'state'], 'radius_device_reservations_capacity_index');
            $table->foreign(['tenant_id', 'router_id', 'identity_id'], 'radius_device_reservations_identity_fk')
                ->references(['tenant_id', 'router_id', 'id'])
                ->on('radius_nas_session_identities')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'router_id', 'session_id'], 'radius_device_reservations_session_fk')
                ->references(['tenant_id', 'router_id', 'id'])
                ->on('sessions_log')
                ->restrictOnDelete();
            $table->foreign('accounting_session_id', 'radius_device_reservations_accounting_fk')
                ->references('id')
                ->on('radius_accounting_sessions')
                ->restrictOnDelete();
        });
    }
};
