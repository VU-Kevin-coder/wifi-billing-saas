<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Refund;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WalletLedgerEntry;
use App\Notifications\RefundRequested;
use App\Services\PaymentService;
use App\Services\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RefundFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Package $package;
    private User $operator;
    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        foreach (['platform_admin', 'operator_admin', 'operator_finance', 'operator_support'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active', 'commission_rate' => 5]);
        $this->package = Package::create([
            'tenant_id' => $this->tenant->id, 'name' => 'One hour', 'price' => 1000, 'currency' => 'UGX',
            'duration_value' => 1, 'duration_unit' => 'hours', 'speed_limit_mbps' => 5, 'device_limit' => 1,
        ]);
        $this->operator = $this->userFor($this->tenant, 'operator_finance');
        $this->platformAdmin = User::factory()->create(['tenant_id' => null]);
        $this->platformAdmin->assignRole('platform_admin');
        URL::defaults(['tenant' => $this->tenant->slug]);
    }

    private function userFor(Tenant $tenant, string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    /** A completed online sale: gross 1000, platform fee 50, operator share 950. */
    private function sale(float $amount = 1000, string $reference = 'ONL-REFUND1'): Transaction
    {
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id, 'type' => 'voucher_sale', 'amount' => $amount, 'status' => 'pending',
            'reference' => $reference, 'provider_reference' => 'p-'.$reference, 'phone' => '0771234567',
            'meta' => ['package_id' => $this->package->id, 'payment_provider' => 'MTN'],
        ]);
        app(PaymentService::class)->handleSuccessfulPackagePayment($this->tenant, $this->package, '0771234567', $transaction);

        return $transaction->fresh();
    }

    private function request(Transaction $sale, float $amount, string $reason = 'Customer was charged but could not connect', bool $revoke = true)
    {
        return $this->actingAs($this->operator)->post(route('operator.refunds.store'), [
            'transaction_id' => $sale->id,
            'amount' => $amount,
            'reason' => $reason,
            'revoke_access' => $revoke ? 1 : 0,
        ]);
    }

    private function approve(Refund $refund, string $payout = 'MTN-PAYOUT-123')
    {
        return $this->actingAs($this->platformAdmin)
            ->post(route('platform.refunds.approve', $refund->id), ['payout_reference' => $payout]);
    }

    private function balance(): float
    {
        return (float) $this->tenant->fresh()->wallet_balance;
    }

    // ── Requesting ──────────────────────────────────────────────────────────

    public function test_an_operator_can_request_a_refund_and_the_platform_is_told(): void
    {
        $sale = $this->sale();

        $this->request($sale, 400)->assertSessionHasNoErrors();

        $refund = Refund::sole();
        $this->assertSame('pending', $refund->status);
        $this->assertEquals(400, (float) $refund->amount);
        $this->assertSame($this->operator->id, $refund->requested_by);
        $this->assertSame(1, AuditLog::where('action', 'refund.requested')->count());
        Notification::assertSentTo($this->platformAdmin, RefundRequested::class);
        $this->assertEquals(950, $this->balance(), 'requesting must not move money');
    }

    public function test_pending_requests_reserve_the_refundable_amount(): void
    {
        $sale = $this->sale();

        $this->request($sale, 700)->assertSessionHasNoErrors();
        $this->request($sale, 400)->assertSessionHasErrors('amount');   // only 300 left

        $this->request($sale, 300)->assertSessionHasNoErrors();
        $this->assertSame(2, Refund::count());
        $this->assertEquals(0, app(RefundService::class)->refundable($sale->fresh()));
    }

    /**
     * @dataProvider invalidRequests
     */
    public function test_invalid_requests_are_refused(float $amount, string $reason): void
    {
        $this->request($this->sale(), $amount, $reason)->assertSessionHasErrors();

        $this->assertSame(0, Refund::count());
    }

    public static function invalidRequests(): array
    {
        return [
            'more than was paid' => [1000.01, 'Customer was charged twice'],
            'zero' => [0, 'Customer was charged twice'],
            'reason too short' => [100, 'oops'],
        ];
    }

    public function test_only_completed_sales_can_be_refunded(): void
    {
        $sale = $this->sale();
        $sale->update(['status' => 'failed']);

        $this->request($sale, 100)->assertSessionHasErrors('amount');
    }

    public function test_old_sales_fall_outside_the_refund_window(): void
    {
        config(['refunds.window_days' => 90]);
        $sale = $this->sale();
        $sale->forceFill(['created_at' => now()->subDays(91)])->saveQuietly();

        $this->request($sale, 100)->assertSessionHasErrors('amount');
    }

    public function test_an_operator_cannot_request_a_refund_on_another_operators_sale(): void
    {
        $other = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'status' => 'active']);
        $foreign = Transaction::create([
            'tenant_id' => $other->id, 'type' => 'voucher_sale', 'amount' => 1000, 'net_amount' => 950,
            'status' => 'completed', 'reference' => 'ONL-FOREIGN',
        ]);

        $this->request($foreign, 100)->assertNotFound();
        $this->assertSame(0, Refund::withoutTenancy()->count());
    }

    public function test_support_staff_cannot_use_refunds(): void
    {
        $sale = $this->sale();
        $support = $this->userFor($this->tenant, 'operator_support');

        $this->actingAs($support)->get(route('operator.refunds.index'))->assertForbidden();
        $this->actingAs($support)->post(route('operator.refunds.store'), ['transaction_id' => $sale->id, 'amount' => 100, 'reason' => 'Customer was charged twice'])->assertForbidden();
    }

    // ── Approving ───────────────────────────────────────────────────────────

    public function test_a_full_refund_debits_the_operators_share_reverses_the_fee_and_removes_access(): void
    {
        $sale = $this->sale();
        $voucher = Voucher::withoutTenancy()->findOrFail($sale->meta['voucher_id']);
        $this->request($sale, 1000)->assertSessionHasNoErrors();

        $this->approve(Refund::sole())->assertSessionHasNoErrors();

        $refund = Refund::sole();
        $this->assertSame('approved', $refund->status);
        $this->assertEquals(950, (float) $refund->tenant_debit);
        $this->assertEquals(50, (float) $refund->platform_fee_reversed);
        $this->assertSame('MTN-PAYOUT-123', $refund->payout_reference);
        $this->assertSame($this->platformAdmin->id, $refund->decided_by);
        $this->assertEquals(0, $this->balance());

        $refundTx = Transaction::withoutTenancy()->find($refund->refund_transaction_id);
        $this->assertSame('refund', $refundTx->type);
        $this->assertEquals(1000, (float) $refundTx->amount);
        $this->assertEquals(-50, (float) $refundTx->platform_fee);
        $this->assertEquals(-950, (float) $refundTx->net_amount);

        $entry = WalletLedgerEntry::where('type', 'refund_debit')->sole();
        $this->assertEquals(-950, (float) $entry->amount);
        $this->assertSame($refundTx->id, $entry->transaction_id);
        $this->assertSame($entry->id, $refund->wallet_ledger_entry_id);

        $this->assertSame('voided', $voucher->fresh()->status);
        $this->assertSame('expired', Session::withoutTenancy()->where('voucher_id', $voucher->id)->latest('id')->first()->status);
        $this->assertSame(1, AuditLog::where('action', 'refund.approved')->count());
        $this->artisan('wallet:reconcile')->assertSuccessful();
    }

    public function test_a_partial_refund_takes_back_a_proportional_share_and_keeps_access(): void
    {
        $sale = $this->sale();
        $voucher = Voucher::withoutTenancy()->findOrFail($sale->meta['voucher_id']);
        $this->request($sale, 400)->assertSessionHasNoErrors();

        $this->approve(Refund::sole())->assertSessionHasNoErrors();

        $refund = Refund::sole();
        $this->assertEquals(380, (float) $refund->tenant_debit);          // 400 * 950/1000
        $this->assertEquals(20, (float) $refund->platform_fee_reversed);
        $this->assertEquals(570, $this->balance());
        $this->assertNotSame('voided', $voucher->fresh()->status, 'a partial refund must not remove access');
    }

    public function test_two_partial_refunds_that_add_up_to_the_sale_remove_access(): void
    {
        $sale = $this->sale();
        $voucher = Voucher::withoutTenancy()->findOrFail($sale->meta['voucher_id']);
        $this->request($sale, 600);
        $this->request($sale, 400);

        foreach (Refund::orderBy('id')->get() as $refund) {
            $this->approve($refund)->assertSessionHasNoErrors();
        }

        $this->assertEquals(0, $this->balance());
        $this->assertSame('voided', $voucher->fresh()->status);
    }

    public function test_access_is_kept_when_the_operator_asked_to_keep_it(): void
    {
        $sale = $this->sale();
        $voucher = Voucher::withoutTenancy()->findOrFail($sale->meta['voucher_id']);
        $this->request($sale, 1000, revoke: false);

        $this->approve(Refund::sole())->assertSessionHasNoErrors();

        $this->assertNotSame('voided', $voucher->fresh()->status);
    }

    public function test_approval_is_refused_and_rolled_back_when_the_wallet_cannot_cover_it(): void
    {
        $sale = $this->sale();
        // The operator has already withdrawn most of the money (950 -> 50).
        \Illuminate\Support\Facades\DB::transaction(fn () => app(\App\Services\WalletService::class)
            ->apply($this->tenant, -900, 'withdrawal_debit', null, 'simulate an earlier payout'));
        $this->request($sale, 1000)->assertSessionHasNoErrors();
        $before = WalletLedgerEntry::count();

        $this->approve(Refund::sole())->assertSessionHasErrors('error');

        $refund = Refund::sole();
        $this->assertSame('pending', $refund->status);
        $this->assertNull($refund->refund_transaction_id);
        $this->assertSame($before, WalletLedgerEntry::count());
        $this->assertSame(0, Transaction::withoutTenancy()->where('type', 'refund')->count());
        $this->assertEquals(50, $this->balance());
    }

    public function test_a_refund_cannot_be_approved_twice(): void
    {
        $sale = $this->sale();
        $this->request($sale, 400);
        $refund = Refund::sole();

        $this->approve($refund)->assertSessionHasNoErrors();
        $this->approve($refund)->assertSessionHasErrors('error');

        $this->assertEquals(570, $this->balance(), 'the wallet must be debited exactly once');
        $this->assertSame(1, WalletLedgerEntry::where('type', 'refund_debit')->count());
    }

    public function test_approving_requires_a_payout_reference(): void
    {
        $this->request($this->sale(), 400);

        $this->actingAs($this->platformAdmin)
            ->post(route('platform.refunds.approve', Refund::sole()->id), ['payout_reference' => ''])
            ->assertSessionHasErrors('payout_reference');

        $this->assertSame('pending', Refund::sole()->status);
    }

    // ── Rejecting and cancelling ────────────────────────────────────────────

    public function test_rejecting_moves_no_money(): void
    {
        $this->request($this->sale(), 400);

        $this->actingAs($this->platformAdmin)
            ->post(route('platform.refunds.reject', Refund::sole()->id), ['note' => 'Customer already refunded by MTN'])
            ->assertSessionHasNoErrors();

        $refund = Refund::sole();
        $this->assertSame('rejected', $refund->status);
        $this->assertSame('Customer already refunded by MTN', $refund->decision_note);
        $this->assertEquals(950, $this->balance());
        $this->assertSame(0, WalletLedgerEntry::where('type', 'refund_debit')->count());
    }

    public function test_an_operator_can_cancel_a_pending_request_but_not_a_decided_one(): void
    {
        $sale = $this->sale();
        $this->request($sale, 300);
        $this->request($sale, 200);
        [$first, $second] = Refund::orderBy('id')->get();

        $this->actingAs($this->operator)->post(route('operator.refunds.cancel', $first->id))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $first->fresh()->status);

        $this->approve($second);
        $this->actingAs($this->operator)->post(route('operator.refunds.cancel', $second->id))->assertSessionHasErrors('error');
        $this->assertSame('approved', $second->fresh()->status);
    }

    // ── Who may do what ─────────────────────────────────────────────────────

    public function test_operators_cannot_approve_their_own_refunds(): void
    {
        $this->request($this->sale(), 400);
        $admin = $this->userFor($this->tenant, 'operator_admin');

        $this->actingAs($admin)->post(route('platform.refunds.approve', Refund::sole()->id), ['payout_reference' => 'SELF-APPROVED'])->assertForbidden();

        $this->assertSame('pending', Refund::sole()->status);
        $this->assertEquals(950, $this->balance());
    }

    public function test_another_operator_cannot_see_or_cancel_my_refund_request(): void
    {
        $this->request($this->sale(), 400);
        $refund = Refund::sole();
        $other = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'status' => 'active']);
        $outsider = $this->userFor($other, 'operator_admin');
        URL::defaults(['tenant' => 'beta']);

        $this->actingAs($outsider)->get(route('operator.refunds.index'))->assertOk()->assertDontSee('Customer was charged but could not connect');
        $this->actingAs($outsider)->post(route('operator.refunds.cancel', $refund->id))->assertNotFound();
        $this->assertSame('pending', $refund->fresh()->status);
    }

    // ── Pages ───────────────────────────────────────────────────────────────

    public function test_the_pages_render_for_both_sides(): void
    {
        $sale = $this->sale();
        $this->request($sale, 400);

        $this->actingAs($this->operator)->get(route('operator.refunds.index'))
            ->assertOk()->assertSee($sale->reference)->assertSee('Customer was charged but could not connect');

        $this->actingAs($this->platformAdmin)->get(route('platform.refunds.index'))
            ->assertOk()->assertSee('Acme')->assertSee($sale->reference)->assertSee('Waiting for a decision (1)');
    }
}
