<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SESSION_ROUTER_NAS_GENERATION_UNIQUE = 'rss_router_nas_generation_unique';

    private const SESSION_TENANT_SUBSCRIBER_STATE_INDEX = 'rss_tenant_subscriber_state_idx';

    public function up(): void
    {
        $addPppoeEnabled = ! Schema::hasColumn('routers', 'enable_pppoe');
        $addPppoeConfig = ! Schema::hasColumn('routers', 'pppoe_config');

        if ($addPppoeEnabled || $addPppoeConfig) {
            Schema::table('routers', function (Blueprint $table) use ($addPppoeEnabled, $addPppoeConfig): void {
                if ($addPppoeEnabled) {
                    $table->boolean('enable_pppoe')->default(false);
                }

                if ($addPppoeConfig) {
                    $table->json('pppoe_config')->nullable();
                }
            });
        }

        if (! Schema::hasTable('subscribers')) {
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
        }

        if (! Schema::hasTable('radius_subscriber_sessions')) {
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

                $table->unique(['router_id', 'nas_session_id', 'generation'], self::SESSION_ROUTER_NAS_GENERATION_UNIQUE);
                $table->index(['tenant_id', 'subscriber_id', 'state'], self::SESSION_TENANT_SUBSCRIBER_STATE_INDEX);
            });
        }

        $this->addSessionIndexIfMissing(
            ['router_id', 'nas_session_id', 'generation'],
            self::SESSION_ROUTER_NAS_GENERATION_UNIQUE,
            true
        );
        $this->addSessionIndexIfMissing(
            ['tenant_id', 'subscriber_id', 'state'],
            self::SESSION_TENANT_SUBSCRIBER_STATE_INDEX
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('radius_subscriber_sessions');
        Schema::dropIfExists('subscribers');

        Schema::table('routers', function (Blueprint $table): void {
            $table->dropColumn(['enable_pppoe', 'pppoe_config']);
        });
    }

    private function addSessionIndexIfMissing(array $columns, string $name, bool $unique = false): void
    {
        if (Schema::hasIndex('radius_subscriber_sessions', $name)) {
            return;
        }

        Schema::table('radius_subscriber_sessions', function (Blueprint $table) use ($columns, $name, $unique): void {
            if ($unique) {
                $table->unique($columns, $name);
            } else {
                $table->index($columns, $name);
            }
        });
    }
};
