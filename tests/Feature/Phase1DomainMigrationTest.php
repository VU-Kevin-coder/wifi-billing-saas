<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
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

        // RefreshDatabase already ran the fresh migrations; rerun each to verify safe re-entry.
        $routerAndSaleMigration->up();
        $accountingMigration->up();

        $this->assertTrue(Schema::hasColumn('routers', 'access_backend'));
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

        $this->assertSame('site_agent', $router->fresh()->access_backend);
        $this->assertNull($voucher->fresh()->sale_transaction_id);
    }

    public function test_typed_sale_link_is_unique_per_transaction(): void
    {
        $tenant = $this->createTenant();
        $transaction = $this->createTransaction($tenant);
        $firstVoucher = $this->createVoucher($tenant);
        $secondVoucher = $this->createVoucher($tenant);
        $firstVoucher->update(['sale_transaction_id' => $transaction->id]);

        try {
            // Roll back the failed statement to a savepoint before checking persisted state.
            DB::transaction(fn () => $secondVoucher->update(['sale_transaction_id' => $transaction->id]));
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
            // PostgreSQL requires a savepoint rollback before another query can run.
            DB::transaction(fn () => $voucher->update(['sale_transaction_id' => $transaction->id]));
            $this->fail('A voucher accepted a sale transaction belonging to another tenant.');
        } catch (QueryException) {
            $this->assertNull($voucher->fresh()->sale_transaction_id);
        }
    }

    public function test_router_migration_refuses_rollback_after_radius_mode_is_configured(): void
    {
        $tenant = $this->createTenant();
        $router = $this->createRouter($tenant);
        $router->forceFill(['access_backend' => 'radius'])->saveOrFail();
        $migration = $this->loadMigration('2026_11_16_000001_add_radius_router_and_sale_links.php');

        $this->assertGuardedRollback(
            fn () => $migration->down(),
            'Cannot roll back router access mode while a router is not using site_agent.'
        );

        $this->assertTrue(Schema::hasColumn('routers', 'access_backend'));
        $this->assertSame('radius', $router->fresh()->access_backend);
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
