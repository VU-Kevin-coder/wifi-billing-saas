<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class Phase1DomainMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_one_migrations_are_repeatable_and_keep_legacy_defaults(): void
    {
        $routerAndSaleMigration = $this->loadMigration('2026_11_16_000001_add_radius_router_and_sale_links.php');
        $accountingMigration = $this->loadMigration('2026_11_16_000002_create_radius_accounting_tables.php');
        $accessModeMigration = $this->loadMigration('2026_11_17_000001_align_access_mode_and_add_radius_device_reservations.php');

        // RefreshDatabase already ran the fresh migrations; rerun each to verify safe re-entry.
        $routerAndSaleMigration->up();
        $accountingMigration->up();
        $accessModeMigration->up();

        $this->assertTrue(Schema::hasColumn('routers', 'access_mode'));
        $this->assertTrue(Schema::hasColumn('sessions_log', 'access_mode'));
        $this->assertTrue(Schema::hasTable('radius_device_reservations'));
        $this->assertTrue(Schema::hasColumn('vouchers', 'sale_transaction_id'));
        $this->assertTrue(Schema::hasTable('radius_accounting_sessions'));
        $this->assertTrue(Schema::hasTable('radius_accounting_events'));
        $this->assertTrue(Schema::hasTable('radius_disconnect_commands'));
        $this->assertTrue(Schema::hasIndex('routers', 'routers_tenant_id_id_unique'));
        $this->assertTrue(Schema::hasIndex('vouchers', 'vouchers_sale_transaction_id_unique'));
        $this->assertTrue(Schema::hasIndex('radius_accounting_events', 'radius_accounting_events_session_fingerprint_unique'));

        $tenant = $this->createTenant();
        $router = $this->createRouter($tenant);
        $voucher = $this->createVoucher($tenant);

        $this->assertSame('site_agent', $router->fresh()->access_mode);
        $this->assertNull($voucher->fresh()->sale_transaction_id);
    }

    public function test_access_mode_migration_copies_legacy_selector_and_round_trips_rollback(): void
    {
        $tenant = $this->createTenant();
        $router = $this->createRouter($tenant);
        $historicalSession = Session::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'status' => 'active',
        ]);
        DB::table('routers')->where('id', $router->id)->update(['access_backend' => 'radius']);
        Schema::table('routers', fn ($table) => $table->dropColumn('access_mode'));
        Schema::table('sessions_log', fn ($table) => $table->dropColumn('access_mode'));
        $migration = $this->loadMigration('2026_11_17_000001_align_access_mode_and_add_radius_device_reservations.php');

        $migration->up();
        $this->assertSame('radius', DB::table('routers')->where('id', $router->id)->value('access_mode'));
        $this->assertSame('site_agent', $historicalSession->fresh()->access_mode);
        $session = Session::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'status' => 'provisioning',
        ]);
        $this->assertSame('site_agent', $session->fresh()->access_mode);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('routers', 'access_mode'));
        $this->assertSame('radius', DB::table('routers')->where('id', $router->id)->value('access_backend'));
        $this->assertFalse(Schema::hasTable('radius_device_reservations'));

        $migration->up();
        $this->assertSame('radius', DB::table('routers')->where('id', $router->id)->value('access_mode'));
    }

    public function test_access_mode_rollback_refuses_radius_session_and_keeps_migration_objects(): void
    {
        $tenant = $this->createTenant();
        $router = $this->createRouter($tenant);
        Session::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'access_mode' => 'radius',
            'status' => 'provisioning',
        ]);
        $migration = $this->loadMigration('2026_11_17_000001_align_access_mode_and_add_radius_device_reservations.php');

        $this->assertGuardedRollback(
            fn () => $migration->down(),
            'Cannot roll back session access mode while a session uses RADIUS.'
        );
        $this->assertTrue(Schema::hasColumn('routers', 'access_mode'));
        $this->assertTrue(Schema::hasColumn('sessions_log', 'access_mode'));
        $this->assertTrue(Schema::hasTable('radius_device_reservations'));
    }

    public function test_access_mode_rollback_refuses_populated_reservations(): void
    {
        $tenant = $this->createTenant();
        $router = $this->createRouter($tenant);
        $session = Session::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'access_mode' => 'radius',
            'status' => 'provisioning',
        ]);
        $now = now();
        $identityId = DB::table('radius_nas_session_identities')->insertGetId([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'nas_session_id' => 'migration-reservation',
            'last_generation' => 0,
            'current_generation_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('radius_device_reservations')->insert([
            'identity_id' => $identityId,
            'generation' => 1,
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'session_id' => $session->id,
            'state' => 'reserved',
            'reserved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $migration = $this->loadMigration('2026_11_17_000001_align_access_mode_and_add_radius_device_reservations.php');

        $this->assertGuardedRollback(
            fn () => $migration->down(),
            'Cannot roll back populated RADIUS device reservations.'
        );
        $this->assertSame(1, DB::table('radius_device_reservations')->count());
    }

    public function test_access_mode_rollback_refuses_to_drop_radius_mode_without_legacy_copy_target(): void
    {
        $tenant = $this->createTenant();
        $router = $this->createRouter($tenant);
        $router->forceFill(['access_mode' => 'radius'])->saveOrFail();
        Schema::table('routers', fn ($table) => $table->dropColumn('access_backend'));
        $migration = $this->loadMigration('2026_11_17_000001_align_access_mode_and_add_radius_device_reservations.php');

        $this->assertGuardedRollback(
            fn () => $migration->down(),
            'Cannot roll back router access mode without preserving legacy selector data.'
        );
        $this->assertSame('radius', DB::table('routers')->where('id', $router->id)->value('access_mode'));
    }

    public function test_typed_sale_link_is_unique_per_transaction(): void
    {
        $tenant = $this->createTenant();
        $transaction = $this->createTransaction($tenant);
        $firstVoucher = $this->createVoucher($tenant);
        $secondVoucher = $this->createVoucher($tenant);
        $firstVoucher->update(['sale_transaction_id' => $transaction->id]);

        try {
            $secondVoucher->update(['sale_transaction_id' => $transaction->id]);
            $this->fail('A sale transaction was linked to more than one voucher.');
        } catch (QueryException) {
            $this->assertSame($transaction->id, $firstVoucher->fresh()->sale_transaction_id);
            $this->assertNull($secondVoucher->fresh()->sale_transaction_id);
        }

        $migration = $this->loadMigration('2026_11_16_000001_add_radius_router_and_sale_links.php');
        $this->assertGuardedRollback(
            fn () => $migration->down(),
            'Cannot roll back populated typed sale-to-voucher links.'
        );
        $this->assertTrue(Schema::hasColumn('vouchers', 'sale_transaction_id'));
    }

    public function test_typed_sale_link_rejects_a_transaction_from_another_tenant(): void
    {
        $saleTenant = $this->createTenant();
        $otherTenant = $this->createTenant();
        $transaction = $this->createTransaction($saleTenant);
        $voucher = $this->createVoucher($otherTenant);

        try {
            $voucher->update(['sale_transaction_id' => $transaction->id]);
            $this->fail('A voucher accepted a sale transaction belonging to another tenant.');
        } catch (QueryException) {
            $this->assertNull($voucher->fresh()->sale_transaction_id);
        }
    }

    public function test_router_migration_refuses_rollback_after_radius_mode_is_configured(): void
    {
        $tenant = $this->createTenant();
        $router = $this->createRouter($tenant);
        $router->forceFill(['access_mode' => 'radius'])->saveOrFail();
        $accessModeMigration = $this->loadMigration('2026_11_17_000001_align_access_mode_and_add_radius_device_reservations.php');
        $migration = $this->loadMigration('2026_11_16_000001_add_radius_router_and_sale_links.php');

        // The new rollback copies the canonical selector back to the legacy
        // column; the original schema rollback must then refuse to lose it.
        $accessModeMigration->down();
        $this->assertGuardedRollback(
            fn () => $migration->down(),
            'Cannot roll back router access mode while a router is not using site_agent.'
        );

        $this->assertFalse(Schema::hasColumn('routers', 'access_mode'));
        $this->assertSame('radius', DB::table('routers')->where('id', $router->id)->value('access_backend'));
    }

    public function test_accounting_migration_refuses_rollback_when_identity_evidence_exists(): void
    {
        $tenant = $this->createTenant();
        $router = $this->createRouter($tenant);

        DB::table('radius_nas_session_identities')->insert([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'nas_session_id' => 'isolated-migration-session',
            'last_generation' => 0,
            'current_generation_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = $this->loadMigration('2026_11_16_000002_create_radius_accounting_tables.php');
        $this->assertGuardedRollback(
            fn () => $migration->down(),
            'Cannot roll back populated RADIUS evidence table: radius_nas_session_identities.'
        );

        $this->assertTrue(Schema::hasTable('radius_nas_session_identities'));
        $this->assertDatabaseCount('radius_nas_session_identities', 1);
    }

    private function loadMigration(string $file): Migration
    {
        return require database_path('migrations/'.$file);
    }

    private function createTenant(): Tenant
    {
        $suffix = Str::lower(Str::random(10));

        return Tenant::create([
            'name' => 'Migration Test '.$suffix,
            'slug' => 'migration-'.$suffix,
            'status' => 'active',
        ]);
    }

    private function createRouter(Tenant $tenant): Router
    {
        return Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'Migration Test Router',
        ]);
    }

    private function createVoucher(Tenant $tenant): Voucher
    {
        $suffix = Str::lower(Str::random(10));
        $package = Package::create([
            'tenant_id' => $tenant->id,
            'name' => 'Migration Test Package '.$suffix,
            'price' => 1000,
            'duration_value' => 1,
            'duration_unit' => 'hours',
            'speed_limit_mbps' => 10,
        ]);
        $batch = VoucherBatch::create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'batch_code' => 'MIG-'.$suffix,
            'quantity' => 1,
        ]);

        return Voucher::create([
            'tenant_id' => $tenant->id,
            'voucher_batch_id' => $batch->id,
            'package_id' => $package->id,
            'code' => 'MIG-'.$suffix,
        ]);
    }

    private function createTransaction(Tenant $tenant): Transaction
    {
        return Transaction::create([
            'tenant_id' => $tenant->id,
            'amount' => 1000,
            'reference' => 'MIG-SALE-'.Str::upper(Str::random(12)),
        ]);
    }

    private function assertGuardedRollback(callable $rollback, string $expectedMessage): void
    {
        try {
            $rollback();
            $this->fail('The migration rollback removed configured or populated Phase 1 data.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
        }
    }
}
