<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $platformAdmin;

    private User $operatorAdmin;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['platform_admin', 'operator_admin', 'operator_finance', 'operator_support', 'operator_network'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }

        $this->tenant = Tenant::create([
            'name' => 'Acme Wi-Fi',
            'slug' => 'acme',
            'status' => 'active',
            'contact_name' => 'Acme Owner',
            'wallet_balance' => 40000,
        ]);

        $this->platformAdmin = User::factory()->create(['tenant_id' => null]);
        $this->platformAdmin->assignRole('platform_admin');

        $this->operatorAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->operatorAdmin->assignRole('operator_admin');

        $this->finance = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->finance->assignRole('operator_finance');

        PlatformSetting::setSecret('flutterwave_secret_key', 'test-secret-key');
        PlatformSetting::set('currency', 'UGX');
        URL::defaults(['tenant' => $this->tenant->slug]);
    }

    private function adjust(array $payload)
    {
        return $this->actingAs($this->platformAdmin)
            ->post(route('platform.revenue.adjust', $this->tenant->id), $payload + [
                '_idempotency_key' => $this->idempotencyKey(),
            ]);
    }

    private function withdrawal(): Transaction
    {
        return Transaction::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'withdrawal',
            'amount' => 10000,
            'platform_fee' => 0,
            'net_amount' => -10000,
            'status' => 'pending',
            'reference' => 'WTH-AUDIT-1',
            'phone' => '0771234567',
            'meta' => [],
        ]);
    }

    public function test_wallet_adjustment_updates_balance_ledger_and_audit_log(): void
    {
        $this->adjust(['amount' => 5000, 'reason' => 'Router compensation credit'])
            ->assertSessionHasNoErrors();

        $this->assertEquals(45000, (float) $this->tenant->fresh()->wallet_balance);
        $this->assertDatabaseHas('transactions', ['tenant_id' => $this->tenant->id, 'type' => 'adjustment']);

        $log = AuditLog::where('action', 'wallet.adjusted')->firstOrFail();
        $this->assertSame($this->platformAdmin->id, $log->user_id);
        $this->assertSame($this->tenant->id, $log->tenant_id);
        $this->assertEquals(5000, $log->meta['amount']);
        $this->assertEquals(40000, $log->meta['balance_before']);
        $this->assertEquals(45000, $log->meta['balance_after']);
        $this->assertSame('Router compensation credit', $log->meta['reason']);
    }

    #[DataProvider('invalidAdjustments')]
    public function test_invalid_wallet_adjustments_change_nothing(array $payload): void
    {
        $this->adjust($payload)->assertSessionHasErrors();

        $this->assertEquals(40000, (float) $this->tenant->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::withoutTenancy()->where('type', 'adjustment')->count());
        $this->assertSame(0, AuditLog::where('action', 'wallet.adjusted')->count());
    }

    public static function invalidAdjustments(): array
    {
        return [
            'zero amount' => [['amount' => 0, 'reason' => 'Zero adjustment attempt']],
            'overdraw below zero' => [['amount' => -40000.01, 'reason' => 'Debit too large here']],
            'above configured cap' => [['amount' => 1000001, 'reason' => 'Credit above the cap']],
            'reason too short' => [['amount' => 100, 'reason' => 'oops']],
            'missing reason' => [['amount' => 100]],
        ];
    }

    public function test_debit_down_to_exactly_zero_is_allowed(): void
    {
        $this->adjust(['amount' => -40000, 'reason' => 'Clawback of mistaken credit'])
            ->assertSessionHasNoErrors();

        $this->assertEquals(0, (float) $this->tenant->fresh()->wallet_balance);
    }

    public function test_withdrawal_request_is_audited_with_destination(): void
    {
        $this->actingAs($this->finance)
            ->post(route('operator.wallet.withdraw'), [
                'amount' => 10000,
                'phone' => '0771234567',
                'password' => 'password',
                '_idempotency_key' => $this->idempotencyKey(),
            ])
            ->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'withdrawal.requested')->firstOrFail();
        $this->assertSame($this->finance->id, $log->user_id);
        $this->assertSame($this->tenant->id, $log->tenant_id);
        $this->assertSame('0771234567', $log->meta['destination_phone']);
    }

    public function test_withdrawal_rejection_is_audited(): void
    {
        $tx = $this->withdrawal();

        $this->actingAs($this->platformAdmin)
            ->post(route('platform.revenue.withdrawals.reject', $tx->id), ['_idempotency_key' => $this->idempotencyKey()])
            ->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'withdrawal.rejected')->firstOrFail();
        $this->assertSame($this->platformAdmin->id, $log->user_id);
        $this->assertSame($tx->id, $log->auditable_id);
    }

    public function test_withdrawal_approval_is_audited(): void
    {
        $tx = $this->withdrawal();
        Http::fake([
            'https://api.flutterwave.com/v3/transfers' => Http::response([
                'status' => 'success',
                'data' => ['id' => 9911, 'status' => 'NEW'],
            ]),
            'https://api.flutterwave.com/v3/transfers/9911' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 9911, 'reference' => $tx->reference,
                    'amount' => 10000, 'currency' => 'UGX', 'status' => 'SUCCESSFUL',
                ],
            ]),
        ]);

        $this->actingAs($this->platformAdmin)
            ->post(route('platform.revenue.withdrawals.approve', $tx->id), ['_idempotency_key' => $this->idempotencyKey()]);

        $log = AuditLog::where('action', 'withdrawal.approved')->firstOrFail();
        $this->assertSame($tx->id, $log->auditable_id);
        $this->assertEquals(10000, $log->meta['amount']);
    }

    public function test_staff_creation_is_audited(): void
    {
        Notification::fake();

        $this->actingAs($this->operatorAdmin)
            ->post(route('operator.staff.store'), [
                'name' => 'New Hire',
                'email' => 'hire@acme.test',
                'role' => 'operator_support',
            ]);

        $log = AuditLog::where('action', 'staff.created')->firstOrFail();
        $this->assertSame('hire@acme.test', $log->meta['email']);
        $this->assertSame('operator_support', $log->meta['role']);
        $this->assertSame($this->operatorAdmin->id, $log->user_id);
    }

    public function test_voucher_generation_and_package_price_changes_are_audited(): void
    {
        $package = Package::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Daily',
            'price' => 1000,
            'currency' => 'UGX',
            'duration_value' => 1,
            'duration_unit' => 'days',
            'speed_limit_mbps' => 5,
            'device_limit' => 1,
        ]);

        $this->actingAs($this->operatorAdmin)
            ->post(route('operator.vouchers.store'), [
                'package_id' => $package->id,
                'quantity' => 3,
                '_idempotency_key' => $this->idempotencyKey(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, (int) AuditLog::where('action', 'vouchers.generated')->firstOrFail()->meta['quantity']);

        $this->actingAs($this->operatorAdmin)
            ->put(route('operator.packages.update', $package->id), [
                'name' => 'Daily',
                'price' => 1500,
                'duration_value' => 1,
                'duration_unit' => 'days',
                'speed_limit_mbps' => 5,
                'device_limit' => 1,
            ])
            ->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'package.updated')->firstOrFail();
        $this->assertContains('price', $log->meta['changed']);
        $this->assertEquals(1500, $log->meta['new_price']);
    }

    public function test_payment_credential_changes_are_audited_without_secret_values(): void
    {
        $this->actingAs($this->platformAdmin)
            ->put(route('platform.configuration.flutterwave.update'), [
                'flutterwave_secret_key' => 'FLWSECK-super-secret',
                'flutterwave_environment' => 'production',
            ])
            ->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'platform.config.updated')->firstOrFail();
        $this->assertSame('flutterwave', $log->meta['section']);
        $this->assertSame(['flutterwave_secret_key'], $log->meta['credentials_changed']);
        $this->assertStringNotContainsString('super-secret', json_encode($log->meta));
    }

    public function test_audit_log_entries_are_append_only(): void
    {
        $log = AuditLog::record('test.event');

        $this->expectException(\LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_audit_log_entries_cannot_be_deleted(): void
    {
        $log = AuditLog::record('test.event');

        $this->expectException(\LogicException::class);
        $log->delete();
    }

    public function test_customer_deletion_is_audited(): void
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Jane Doe', 'phone' => '0771234567']);

        $this->actingAs($this->operatorAdmin)
            ->delete(route('operator.customers.destroy', $customer->id))
            ->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'customer.deleted')->firstOrFail();
        $this->assertSame('Jane Doe', $log->meta['name']);
        $this->assertSame($this->tenant->id, $log->tenant_id);
    }

    public function test_router_creation_and_removal_are_audited(): void
    {
        $this->actingAs($this->operatorAdmin)
            ->post(route('operator.routers.store'), [
                'name' => 'Main Router',
                '_idempotency_key' => $this->idempotencyKey(),
            ])
            ->assertSessionHasNoErrors();

        $router = Router::withoutTenancy()->where('name', 'Main Router')->firstOrFail();
        $this->assertSame(1, AuditLog::where('action', 'router.created')->count());

        $this->actingAs($this->operatorAdmin)
            ->delete(route('operator.routers.destroy', $router->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, AuditLog::where('action', 'router.deleted')->count());
    }

    public function test_tenant_creation_is_audited(): void
    {
        Notification::fake();

        $this->actingAs($this->platformAdmin)
            ->post(route('platform.tenants.store'), [
                'name' => 'New Co',
                'slug' => 'New Co',
                'status' => 'active',
                'owner_name' => 'Owner',
                'owner_email' => 'owner@newco.test',
            ])
            ->assertSessionHasNoErrors();

        $tenant = Tenant::where('slug', 'new-co')->firstOrFail();
        $owner = User::withoutTenancy()->where('email', 'owner@newco.test')->firstOrFail();
        $log = AuditLog::where('action', 'tenant.created')->firstOrFail();
        $this->assertSame('new-co', $log->meta['slug']);
        $this->assertSame('owner@newco.test', $log->meta['owner_email']);
        $this->assertSame($tenant->id, $owner->tenant_id);
        $this->assertNull($owner->email_verified_at);
        $this->assertSame(now()->toDateString(), $tenant->created_at->toDateString());
        Notification::assertSentTo($owner, ResetPassword::class);
    }

    public function test_successful_login_is_audited(): void
    {
        $this->post('/login', ['email' => $this->operatorAdmin->email, 'password' => 'password']);

        $log = AuditLog::where('action', 'auth.login')->firstOrFail();
        $this->assertSame($this->operatorAdmin->id, $log->user_id);
    }

    public function test_failed_login_is_audited_without_the_password(): void
    {
        $this->post('/login', ['email' => $this->operatorAdmin->email, 'password' => 'wrong-password-123']);

        $log = AuditLog::where('action', 'auth.login_failed')->firstOrFail();
        $this->assertSame($this->operatorAdmin->email, $log->meta['email']);
        $this->assertTrue($log->meta['known_user']);
        $this->assertStringNotContainsString('wrong-password-123', json_encode($log->toArray()));
    }
}
