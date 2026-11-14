<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const IDENTITY_TENANT_ROUTER_KEY = 'radius_nas_identities_tenant_router_id_unique';

    private const SESSION_IDENTITY_ID_KEY = 'radius_accounting_sessions_identity_id_unique';

    private const SESSION_IDENTITY_GENERATION_KEY = 'radius_accounting_sessions_identity_generation_unique';

    private const SESSION_TENANT_ROUTER_ID_KEY = 'radius_accounting_sessions_tenant_router_id_unique';

    private const IDENTITY_CURRENT_GENERATION_FK = 'radius_nas_identities_current_generation_fk';

    public function up(): void
    {
        $this->createIdentityTable();
        $this->createAccountingSessionTable();
        $this->createAccountingEventTable();
        $this->createDisconnectCommandTable();
        $this->addCurrentGenerationForeignKeyIfMissing();
    }

    public function down(): void
    {
        $this->assertEvidenceTablesAreEmpty();

        $this->dropForeignKeyIfPresent('radius_nas_session_identities', self::IDENTITY_CURRENT_GENERATION_FK);
        Schema::dropIfExists('radius_disconnect_commands');
        Schema::dropIfExists('radius_accounting_events');
        Schema::dropIfExists('radius_accounting_sessions');
        Schema::dropIfExists('radius_nas_session_identities');
    }

    private function createIdentityTable(): void
    {
        if (Schema::hasTable('radius_nas_session_identities')) {
            return;
        }

        Schema::create('radius_nas_session_identities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('router_id');
            $table->string('nas_session_id', 255);
            $table->unsignedBigInteger('last_generation')->default(0);
            $table->unsignedBigInteger('current_generation_id')->nullable();
            $table->timestamps();

            $table->unique(['router_id', 'nas_session_id'], 'radius_nas_identities_router_nas_unique');
            $table->unique(['tenant_id', 'router_id', 'id'], self::IDENTITY_TENANT_ROUTER_KEY);
            $table->foreign(['tenant_id', 'router_id'], 'radius_nas_identities_tenant_router_fk')
                ->references(['tenant_id', 'id'])
                ->on('routers')
                ->restrictOnDelete();
        });
    }

    private function createAccountingSessionTable(): void
    {
        if (Schema::hasTable('radius_accounting_sessions')) {
            return;
        }

        Schema::create('radius_accounting_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('identity_id');
            $table->unsignedBigInteger('generation');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('router_id');
            $table->unsignedBigInteger('session_id')->nullable();
            $table->string('state', 16)->default('open');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_accounting_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->unsignedBigInteger('input_bytes')->default(0);
            $table->unsignedBigInteger('output_bytes')->default(0);
            $table->timestamps();

            $table->unique(['identity_id', 'generation'], self::SESSION_IDENTITY_GENERATION_KEY);
            $table->unique(['identity_id', 'id'], self::SESSION_IDENTITY_ID_KEY);
            $table->unique(['tenant_id', 'router_id', 'id'], self::SESSION_TENANT_ROUTER_ID_KEY);
            $table->index(['router_id', 'state'], 'radius_accounting_sessions_router_state_index');
            $table->index('session_id', 'radius_accounting_sessions_session_id_index');
            $table->index(['state', 'last_accounting_at'], 'radius_accounting_sessions_reconcile_index');

            $table->foreign(['tenant_id', 'router_id', 'identity_id'], 'radius_accounting_sessions_identity_fk')
                ->references(['tenant_id', 'router_id', 'id'])
                ->on('radius_nas_session_identities')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'router_id', 'session_id'], 'radius_accounting_sessions_application_session_fk')
                ->references(['tenant_id', 'router_id', 'id'])
                ->on('sessions_log')
                ->restrictOnDelete();
        });
    }

    private function createAccountingEventTable(): void
    {
        if (Schema::hasTable('radius_accounting_events')) {
            return;
        }

        Schema::create('radius_accounting_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('accounting_session_id');
            $table->char('fingerprint', 64);
            $table->string('status_type', 24);
            $table->timestamp('event_time')->nullable();
            $table->unsignedInteger('session_time')->nullable();
            $table->unsignedInteger('input_octets')->nullable();
            $table->unsignedInteger('input_gigawords')->nullable();
            $table->unsignedInteger('output_octets')->nullable();
            $table->unsignedInteger('output_gigawords')->nullable();
            $table->string('termination_cause', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('mac_address', 17)->nullable();
            $table->boolean('is_quarantined')->default(false);
            $table->string('quarantine_reason', 64)->nullable();
            $table->timestamp('received_at')->useCurrent();

            $table->unique(['accounting_session_id', 'fingerprint'], 'radius_accounting_events_session_fingerprint_unique');
            $table->index(['accounting_session_id', 'event_time'], 'radius_accounting_events_session_time_index');
            $table->foreign('accounting_session_id', 'radius_accounting_events_session_fk')
                ->references('id')
                ->on('radius_accounting_sessions')
                ->restrictOnDelete();
        });
    }

    private function createDisconnectCommandTable(): void
    {
        if (Schema::hasTable('radius_disconnect_commands')) {
            return;
        }

        Schema::create('radius_disconnect_commands', function (Blueprint $table): void {
            $table->id();
            $table->uuid('command_id')->unique('radius_disconnect_commands_command_id_unique');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('router_id');
            $table->unsignedBigInteger('accounting_session_id');
            $table->string('idempotency_key', 128);
            $table->string('state', 32)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->string('lease_owner', 128)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('delivery_acknowledged_at')->nullable();
            $table->string('delivery_result_code', 64)->nullable();
            $table->timestamps();

            $table->unique(['accounting_session_id', 'idempotency_key'], 'radius_disconnect_commands_session_key_unique');
            $table->index(['state', 'available_at'], 'radius_disconnect_commands_ready_index');
            $table->index('lease_expires_at', 'radius_disconnect_commands_lease_index');
            $table->foreign(['tenant_id', 'router_id', 'accounting_session_id'], 'radius_disconnect_commands_session_fk')
                ->references(['tenant_id', 'router_id', 'id'])
                ->on('radius_accounting_sessions')
                ->restrictOnDelete();
        });
    }

    private function addCurrentGenerationForeignKeyIfMissing(): void
    {
        if ($this->hasForeignKey('radius_nas_session_identities', self::IDENTITY_CURRENT_GENERATION_FK)) {
            return;
        }

        Schema::table('radius_nas_session_identities', function (Blueprint $table): void {
            $table->foreign(['id', 'current_generation_id'], self::IDENTITY_CURRENT_GENERATION_FK)
                ->references(['identity_id', 'id'])
                ->on('radius_accounting_sessions')
                ->restrictOnDelete();
        });
    }

    private function assertEvidenceTablesAreEmpty(): void
    {
        foreach ([
            'radius_disconnect_commands',
            'radius_accounting_events',
            'radius_accounting_sessions',
            'radius_nas_session_identities',
        ] as $tableName) {
            if (Schema::hasTable($tableName) && DB::table($tableName)->exists()) {
                throw new RuntimeException("Cannot roll back populated RADIUS evidence table: {$tableName}.");
            }
        }
    }

    private function hasForeignKey(string $tableName, string $name): bool
    {
        return Schema::hasTable($tableName)
            && collect(Schema::getForeignKeys($tableName))
                ->contains(fn (array $foreignKey): bool => $foreignKey['name'] === $name);
    }

    private function dropForeignKeyIfPresent(string $tableName, string $name): void
    {
        if ($this->hasForeignKey($tableName, $name)) {
            Schema::table($tableName, function (Blueprint $table) use ($name): void {
                $table->dropForeign($name);
            });
        }
    }
};
