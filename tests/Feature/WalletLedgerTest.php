<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientWalletBalance;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Services\PaymentService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WalletLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active', 'commission_rate' => 5]);
    }

    private function wallet(): WalletService
    {
        return app(WalletService::class);
    }

    private function balance(): float
    {
        return (float) $this->tenant->fresh()->wallet_balance;
    }

    // ── The rule: only WalletService writes the balance ─────────────────────

    public function test_nothing_else_in_the_application_writes_the_wallet_balance(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with($file->getFilename(), 'WalletService.php')) {
                continue;
            }
            $code = file_get_contents($file->getPathname());

            if (preg_match("/(increment|decrement)\(\s*'wallet_balance'/", $code)
                || preg_match("/(update|forceFill|fill)\(\s*\[\s*'wallet_balance'/", $code)
                || preg_match("/->wallet_balance\s*=[^=]/", $code)) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'These files change the wallet balance without the ledger (use WalletService::apply).');
    }

    // ── WalletService ───────────────────────────────────────────────────────

    public function test_credits_and_debits_update_the_balance_and_chain_the_ledger(): void
    {
        DB::transaction(function () {
            $this->wallet()->apply($this->tenant, 1000, WalletService::SALE_CREDIT, null, 'first');
            $this->wallet()->apply($this->tenant, -250.5, WalletService::WITHDRAWAL_DEBIT, null, 'second');
        });

        $this->assertEquals(749.5, $this->balance());
        $entries = WalletLedgerEntry::orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertEquals([0, 1000], [(float) $entries[0]->balance_before, (float) $entries[0]->balance_after]);
        $this->assertEquals([1000, 749.5], [(float) $entries[1]->balance_before, (float) $entries[1]->balance_after]);
        $this->assertEquals(-250.5, (float) $entries[1]->amount);
    }

    public function test_a_debit_below_zero_is_refused_and_changes_nothing(): void
    {
        DB::transaction(fn () => $this->wallet()->apply($this->tenant, 100, WalletService::SALE_CREDIT));

        try {
            DB::transaction(fn () => $this->wallet()->apply($this->tenant, -100.01, WalletService::WITHDRAWAL_DEBIT));
            $this->fail('expected InsufficientWalletBalance');
        } catch (InsufficientWalletBalance $e) {
            $this->assertEquals(100, $e->balance);
            $this->assertEquals(100.01, $e->required);
        }

        $this->assertEquals(100, $this->balance());
        $this->assertSame(1, WalletLedgerEntry::count());
    }

    public function test_a_held_tenant_model_is_kept_in_step(): void
    {
        DB::transaction(fn () => $this->wallet()->apply($this->tenant, 500, WalletService::SALE_CREDIT));

        $this->assertEquals(500, (float) $this->tenant->wallet_balance);
    }

    public function test_ledger_entries_are_immutable(): void
    {
        $entry = DB::transaction(fn () => $this->wallet()->apply($this->tenant, 10, WalletService::SALE_CREDIT));

        try {
            $entry->update(['amount' => 99999]);
            $this->fail('update must be refused');
        } catch (\LogicException) {
            $this->assertEquals(10, (float) $entry->fresh()->amount);
        }

        $this->expectException(\LogicException::class);
        $entry->delete();
    }

    // ── Every existing money movement leaves a ledger line ──────────────────

    private function sale(float $amount = 1000): Transaction
    {
        $package = Package::create([
            'tenant_id' => $this->tenant->id, 'name' => 'One hour', 'price' => $amount, 'currency' => 'UGX',
            'duration_value' => 1, 'duration_unit' => 'hours', 'speed_limit_mbps' => 5, 'device_limit' => 1,
        ]);
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id, 'type' => 'voucher_sale', 'amount' => $amount, 'status' => 'pending',
            'reference' => 'ONL-LEDGER1', 'provider_reference' => 'p-ledger-1', 'phone' => '0771234567',
            'meta' => ['package_id' => $package->id, 'payment_provider' => 'MTN'],
        ]);

        app(PaymentService::class)->handleSuccessfulPackagePayment($this->tenant, $package, '0771234567', $transaction);

        return $transaction->fresh();
    }

    public function test_an_online_sale_credits_the_operators_share_through_the_ledger(): void
    {
        $sale = $this->sale(1000);

        $entry = WalletLedgerEntry::sole();
        $this->assertSame('sale_credit', $entry->type);
        $this->assertEquals(950, (float) $entry->amount);
        $this->assertSame($sale->id, $entry->transaction_id);
        $this->assertSame('ONL-LEDGER1', $entry->reference);
        $this->assertEquals(950, $this->balance());
    }

    private function operator(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($role);
        URL::defaults(['tenant' => $this->tenant->slug]);

        return $user;
    }

    public function test_a_withdrawal_request_debits_through_the_ledger(): void
    {
        $this->sale(20000);          // net 19,000
        $finance = $this->operator('operator_finance');

        $this->actingAs($finance)
            ->post(route('operator.wallet.withdraw'), [
                'amount' => 10000, 'phone' => '0771234567', 'password' => 'password',
                '_idempotency_key' => $this->idempotencyKey(),
            ])
            ->assertSessionHasNoErrors();

        $entry = WalletLedgerEntry::where('type', 'withdrawal_debit')->sole();
        $this->assertEquals(-10000, (float) $entry->amount);
        $this->assertSame($finance->id, $entry->user_id);
        $this->assertEquals(9000, $this->balance());
        $this->assertSame('withdrawal', Transaction::find($entry->transaction_id)->type);
    }

    public function test_rejecting_a_withdrawal_returns_the_funds_through_the_ledger(): void
    {
        $this->sale(20000);
        $this->actingAs($this->operator('operator_finance'))
            ->post(route('operator.wallet.withdraw'), [
                'amount' => 10000, 'phone' => '0771234567', 'password' => 'password',
                '_idempotency_key' => $this->idempotencyKey(),
            ]);
        $withdrawal = Transaction::where('type', 'withdrawal')->firstOrFail();

        Role::findOrCreate('platform_admin', 'web');
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');

        $this->actingAs($admin)
            ->post(route('platform.revenue.withdrawals.reject', $withdrawal->id), ['_idempotency_key' => $this->idempotencyKey()])
            ->assertSessionHasNoErrors();

        $this->assertEquals(19000, $this->balance());
        $this->assertEquals(10000, (float) WalletLedgerEntry::where('type', 'withdrawal_refund')->sole()->amount);
    }

    public function test_a_manual_adjustment_is_in_the_ledger_with_its_author(): void
    {
        Role::findOrCreate('platform_admin', 'web');
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');

        $this->actingAs($admin)
            ->post(route('platform.revenue.adjust', $this->tenant->id), [
                'amount' => 5000,
                'reason' => 'Router compensation credit',
                '_idempotency_key' => $this->idempotencyKey(),
            ])
            ->assertSessionHasNoErrors();

        $entry = WalletLedgerEntry::where('type', 'adjustment')->sole();
        $this->assertEquals(5000, (float) $entry->amount);
        $this->assertSame($admin->id, $entry->user_id);
        $this->assertSame('Router compensation credit', $entry->description);
    }

    // ── Reconciliation ──────────────────────────────────────────────────────

    public function test_reconcile_passes_when_every_change_went_through_the_ledger(): void
    {
        $this->sale(1000);

        $this->artisan('wallet:reconcile')->expectsOutputToContain('match their ledger')->assertSuccessful();
    }

    public function test_reconcile_detects_a_balance_changed_behind_the_ledgers_back(): void
    {
        $this->sale(1000);
        DB::table('tenants')->where('id', $this->tenant->id)->update(['wallet_balance' => 5000]);

        $this->artisan('wallet:reconcile')
            ->expectsOutputToContain("Tenant #{$this->tenant->id}")
            ->assertFailed();
    }

    public function test_reconcile_is_scheduled_hourly(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('wallet:reconcile');
    }
}
