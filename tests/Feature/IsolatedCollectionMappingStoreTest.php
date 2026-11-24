<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Payments\IsolatedCollectionMappingStore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class IsolatedCollectionMappingStoreTest extends TestCase
{
    use RefreshDatabase {
        beginDatabaseTransaction as private beginRefreshDatabaseTransaction;
    }

    /**
     * MySQL implicitly commits DDL, so install and remove the fixture outside
     * RefreshDatabase's per-test transaction. Other drivers keep the fixture
     * inside the transaction and roll it back with the test data.
     */
    public function beginDatabaseTransaction()
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->beginRefreshDatabaseTransaction();

            return;
        }

        $migration = require base_path('tests/fixtures/migrations/2026_11_24_110000_create_isolated_collection_mappings.php');
        $migration->up();
        $this->beginRefreshDatabaseTransaction();

        // RefreshDatabase registers its rollback first; its callback also
        // disconnects, so the migration reconnects outside the transaction.
        $this->beforeApplicationDestroyed(fn () => $migration->down());
    }

    private int $tenant;

    private int $other;

    private IsolatedCollectionMappingStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        // The prototype is deliberately outside the application's migration path.
        if (DB::connection()->getDriverName() !== 'mysql') {
            (require base_path('tests/fixtures/migrations/2026_11_24_110000_create_isolated_collection_mappings.php'))->up();
        }
        Http::preventStrayRequests();
        $this->tenant = DB::table('tenants')->insertGetId(['name' => 'A', 'slug' => 'map-a', 'status' => 'active']);
        $this->other = DB::table('tenants')->insertGetId(['name' => 'B', 'slug' => 'map-b', 'status' => 'active']);
        $this->store = new IsolatedCollectionMappingStore;
    }

    private function voucher(string $reference, int $tenant, string $amount = '5000.00', string $type = 'voucher_sale'): int
    {
        return DB::table('transactions')->insertGetId(compact('reference', 'amount', 'type') + ['tenant_id' => $tenant, 'status' => 'pending', 'platform_fee' => 0, 'net_amount' => 0]);
    }

    private function attempt(string $reference, int $tenant, ?int $invoiceTenant = null, int $amount = 5000, string $currency = 'UGX'): int
    {
        $invoice = DB::table('subscription_invoices')->insertGetId(['tenant_id' => $invoiceTenant ?? $tenant, 'plan_id' => 1, 'plan_name' => 'test', 'amount' => $amount, 'currency' => $currency, 'status' => 'open']);

        return DB::table('subscription_attempts')->insertGetId(['tenant_id' => $tenant, 'subscription_invoice_id' => $invoice, 'idempotency_key' => (string) Str::uuid(), 'phone_hash' => str_repeat('a', 64), 'reference' => $reference, 'status' => 'unknown']);
    }

    private function args(string $ref, string $purpose, int $target): array
    {
        return [$ref, 'obpay', 'shared-account', 'sandbox', $this->tenant, $purpose, $target, 5000, 'UGX'];
    }

    private function persist(array $args): void
    {
        DB::transaction(fn () => $this->store->create(...$args));
    }

    public function test_separate_purposes_share_account_and_save_exact_facts(): void
    {
        $vref = (string) Str::uuid();
        $sref = (string) Str::uuid();
        $v = $this->voucher($vref, $this->tenant);
        $s = $this->attempt($sref, $this->tenant);
        $this->persist($this->args($vref, 'voucher', $v));
        $this->persist($this->args($sref, 'subscription', $s));
        $vr = $this->store->find($vref);
        $sr = $this->store->find($sref);
        self::assertSame('voucher', $vr->purpose);
        self::assertSame('subscription', $sr->purpose);
        self::assertSame('shared-account', $vr->account_key);
        self::assertSame($vr->account_key, $sr->account_key);
        self::assertSame('sandbox', $sr->environment);
        self::assertSame(5000, (int) $sr->amount);
        self::assertSame('UGX', $sr->currency);
        self::assertSame($v, (int) $vr->voucher_transaction_id);
        self::assertNull($vr->subscription_attempt_id);
        self::assertNull($this->store->find('unknown'));
        self::assertNull($this->store->find(strtoupper($vref)));
    }

    public function test_validation_rejects_bad_context_money_and_cross_tenant_targets(): void
    {
        $ref = (string) Str::uuid();
        $target = $this->voucher($ref, $this->tenant);
        $good = $this->args($ref, 'voucher', $target);
        $otherTarget = $this->voucher((string) Str::uuid(), $this->other);
        $bad = [];
        foreach ([0 => ['not-a-uuid', strtoupper($ref)], 1 => ['', 'OBPAY', "a\n"], 2 => ['', 'account space'], 3 => ['test'],
            4 => [0, (string) $this->tenant, $this->other], 5 => ['refund'], 6 => [0, (string) $target, $otherTarget, 999999],
            7 => [0, -1, '5000', 5000.0, 5001], 8 => ['ugx', 'USD']] as $index => $values) {
            foreach ($values as $value) {
                $args = $good;
                $args[$index] = $value;
                $bad[] = $args;
            }
        }
        foreach ($bad as $args) {
            try {
                $this->persist($args);
                self::fail('Invalid mapping accepted');
            } catch (InvalidArgumentException $e) {
                self::assertStringNotContainsString('account space', $e->getMessage());
            }
        }
        self::assertSame(0, DB::table('isolated_collection_mappings')->count());
    }

    public function test_target_reference_type_amount_and_invoice_tenant_are_checked(): void
    {
        $ref = (string) Str::uuid();
        $wrongType = $this->voucher($ref, $this->tenant, '5000.00', 'refund');
        $fractionRef = (string) Str::uuid();
        $fraction = $this->voucher($fractionRef, $this->tenant, '5000.01');
        $aRef = (string) Str::uuid();
        $attempt = $this->attempt($aRef, $this->tenant, $this->other);
        $bRef = (string) Str::uuid();
        $wrongCurrency = $this->attempt($bRef, $this->tenant, null, 5000, 'USD');
        foreach ([$this->args($ref, 'voucher', $wrongType), $this->args($fractionRef, 'voucher', $fraction),
            $this->args($aRef, 'subscription', $attempt), $this->args($bRef, 'subscription', $wrongCurrency),
            $this->args((string) Str::uuid(), 'voucher', $wrongType)] as $args) {
            try {
                $this->persist($args);
                self::fail('Bad target accepted');
            } catch (InvalidArgumentException $e) {
                self::assertSame('Invalid collection target.', $e->getMessage());
            }
        }
    }

    public function test_cross_purpose_global_reference_collision_and_repeat_fail_closed(): void
    {
        $ref = (string) Str::uuid();
        $v = $this->voucher($ref, $this->tenant);
        $a = $this->attempt($ref, $this->tenant);
        $this->persist($this->args($ref, 'voucher', $v));
        foreach ([$this->args($ref, 'voucher', $v), $this->args($ref, 'subscription', $a)] as $args) {
            try {
                $this->persist($args);
                self::fail('Collision accepted');
            } catch (RuntimeException $e) {
                self::assertSame('Collection mapping could not be stored.', $e->getMessage());
                self::assertNull($e->getPrevious());
            }
        }
        self::assertSame(1, DB::table('isolated_collection_mappings')->count());
    }

    public function test_database_rejects_invalid_direct_writes_and_parent_deletion(): void
    {
        $ref = (string) Str::uuid();
        $v = $this->voucher($ref, $this->tenant);
        $base = ['local_reference' => $ref, 'provider' => 'obpay', 'account_key' => 'account', 'environment' => 'live',
            'tenant_id' => $this->tenant, 'purpose' => 'voucher', 'voucher_transaction_id' => $v,
            'subscription_attempt_id' => null, 'amount' => 5000, 'currency' => 'UGX', 'created_at' => now()];
        $subscriptionRef = (string) Str::uuid();
        $subscription = $this->attempt($subscriptionRef, $this->tenant);
        foreach ([['purpose' => 'subscription'], ['voucher_transaction_id' => null],
            ['subscription_attempt_id' => $subscription], ['tenant_id' => $this->other],
            ['tenant_id' => 999999], ['amount' => 0], ['amount' => -1], ['currency' => 'USD'], ['environment' => 'other'],
            ['purpose' => 'subscription', 'voucher_transaction_id' => null, 'subscription_attempt_id' => 999999],
            ['purpose' => 'subscription', 'tenant_id' => $this->other, 'voucher_transaction_id' => null, 'subscription_attempt_id' => $subscription]] as $change) {
            try {
                DB::transaction(fn () => DB::table('isolated_collection_mappings')->insert(array_replace($base, $change)));
                self::fail('Invalid database write accepted');
            } catch (QueryException $e) {
                self::assertTrue(true);
            }
        }
        DB::table('isolated_collection_mappings')->insert($base);
        foreach ([fn () => DB::table('transactions')->where('id', $v)->delete(),
            fn () => DB::table('isolated_collection_mappings')->insert($base),
            fn () => DB::table('isolated_collection_mappings')->insert(array_replace($base, ['local_reference' => (string) Str::uuid()]))] as $operation) {
            try {
                DB::transaction($operation);
                self::fail('Constraint not enforced');
            } catch (QueryException $e) {
                self::assertTrue(true);
            }
        }
    }

    public function test_rollback_removes_mapping_and_target_together(): void
    {
        $ref = (string) Str::uuid();
        try {
            DB::transaction(function () use ($ref) {
                $v = $this->voucher($ref, $this->tenant);
                $this->store->create(...$this->args($ref, 'voucher', $v));
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $e) {
            self::assertSame('rollback', $e->getMessage());
        }
        self::assertNull($this->store->find($ref));
        self::assertFalse(DB::table('transactions')->where('reference', $ref)->exists());
    }
}
