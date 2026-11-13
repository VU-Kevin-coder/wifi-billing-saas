<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\WithdrawalApproved;
use App\Notifications\WithdrawalRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Platform admins have no tenant context, so these tests go through the HTTP
 * layer (route binding + TenantScope) rather than calling services directly.
 */
class PlatformRevenueControllerTest extends TestCase
{
    use RefreshDatabase;

    private const API_URL = 'https://api.flutterwave.com/v3';

    private User $admin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['tenant_id' => null]);
        $this->admin->assignRole('platform_admin');

        PlatformSetting::setSecret('flutterwave_secret_key', 'test-secret-key');
        PlatformSetting::set('currency', 'UGX');

        $this->tenant = Tenant::create([
            'name' => 'Operator',
            'slug' => 'operator',
            'status' => 'active',
            'contact_name' => 'Operator User',
            'wallet_balance' => 40000,
        ]);
    }

    private function withdrawal(array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'type' => 'withdrawal',
            'amount' => 10000,
            'platform_fee' => 0,
            'net_amount' => -10000,
            'status' => 'pending',
            'reference' => 'WTH-HTTP-001',
            'phone' => '0771234567',
            'meta' => [],
            'description' => 'Withdrawal to 256771234567',
        ], $overrides));
    }

    public function test_revenue_page_reports_commissions_and_pending_withdrawals_without_tenant_context(): void
    {
        $this->withdrawal();
        Transaction::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'voucher_sale',
            'amount' => 10000,
            'platform_fee' => 500,
            'net_amount' => 9500,
            'status' => 'completed',
            'reference' => 'ONL-SALE0001',
        ]);

        $this->actingAs($this->admin)
            ->get(route('platform.revenue.index'))
            ->assertOk()
            ->assertViewHas('totalCommissions', 500.0)
            ->assertSee('Review 1 request(s)')
            ->assertSee('WTH-HTTP-001');
    }

    public function test_platform_admin_can_reject_a_withdrawal_over_http_and_funds_are_returned(): void
    {
        $tx = $this->withdrawal();
        $operatorAdmin = $this->operatorAdmin();

        $this->actingAs($this->admin)
            ->post(route('platform.revenue.withdrawals.reject', $tx->id), ['_idempotency_key' => $this->idempotencyKey()])
            ->assertSessionHasNoErrors();

        $this->assertSame('rejected', Transaction::withoutTenancy()->find($tx->id)->status);
        $this->assertEquals(50000, (float) $this->tenant->fresh()->wallet_balance);
        Notification::assertSentTo($operatorAdmin, WithdrawalRejected::class);
    }

    public function test_platform_admin_can_approve_a_withdrawal_over_http(): void
    {
        $tx = $this->withdrawal();
        $operatorAdmin = $this->operatorAdmin();

        Http::fake([
            self::API_URL.'/transfers' => Http::response([
                'status' => 'success',
                'data' => ['id' => 9911, 'status' => 'NEW'],
            ]),
            self::API_URL.'/transfers/9911' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 9911,
                    'reference' => $tx->reference,
                    'amount' => 10000,
                    'currency' => 'UGX',
                    'status' => 'SUCCESSFUL',
                ],
            ]),
        ]);

        $this->actingAs($this->admin)
            ->post(route('platform.revenue.withdrawals.approve', $tx->id), ['_idempotency_key' => $this->idempotencyKey()])
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', Transaction::withoutTenancy()->find($tx->id)->status);
        Notification::assertSentTo($operatorAdmin, WithdrawalApproved::class);
    }

    public function test_platform_admin_can_review_encrypted_bank_details_and_record_manual_transfer_reference(): void
    {
        $tx = $this->withdrawal([
            'phone' => null,
            'description' => 'Manual bank transfer payout',
            'meta' => [
                'payout_method' => 'bank_transfer',
                'bank_details_encrypted' => Crypt::encryptString(json_encode([
                    'bank_name' => 'Example Bank',
                    'account_name' => 'Operator User',
                    'account_number' => '0123456789',
                ], JSON_THROW_ON_ERROR)),
            ],
        ]);
        $operatorAdmin = $this->operatorAdmin();

        $this->actingAs($this->admin)
            ->get(route('platform.revenue.index'))
            ->assertOk()
            ->assertSee('Example Bank')
            ->assertSee('0123456789')
            ->assertSee('Confirm Bank Transfer');

        $this->actingAs($this->admin)
            ->post(route('platform.revenue.withdrawals.approve', $tx->id), [
                '_idempotency_key' => $this->idempotencyKey(),
            ])
            ->assertSessionHasErrors('payout_reference');
        $this->assertSame('pending', Transaction::withoutTenancy()->find($tx->id)->status);

        $this->actingAs($this->admin)
            ->post(route('platform.revenue.withdrawals.approve', $tx->id), [
                'payout_reference' => 'BANK-REF-2026-001',
                '_idempotency_key' => $this->idempotencyKey(),
            ])
            ->assertSessionHasNoErrors();

        $completed = Transaction::withoutTenancy()->findOrFail($tx->id);
        $this->assertSame('completed', $completed->status);
        $this->assertSame('BANK-REF-2026-001', $completed->meta['manual_payout_reference']);
        $this->assertSame('manual', $completed->meta['flutterwave_transfer_submission']);
        Http::assertNothingSent();
        Notification::assertSentTo($operatorAdmin, WithdrawalApproved::class);
    }

    public function test_manual_bank_approval_stays_pending_if_encrypted_details_are_invalid(): void
    {
        $tx = $this->withdrawal([
            'phone' => null,
            'meta' => [
                'payout_method' => 'bank_transfer',
                'bank_details_encrypted' => 'invalid-ciphertext',
            ],
        ]);

        $this->actingAs($this->admin)
            ->post(route('platform.revenue.withdrawals.approve', $tx->id), [
                'payout_reference' => 'BANK-REF-2026-002',
                '_idempotency_key' => $this->idempotencyKey(),
            ])
            ->assertSessionHasErrors('error');

        $this->assertSame('pending', Transaction::withoutTenancy()->find($tx->id)->status);
        Http::assertNothingSent();
    }

    public function test_only_withdrawals_can_be_targeted_by_the_withdrawal_routes(): void
    {
        $sale = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'voucher_sale',
            'amount' => 5000,
            'status' => 'pending',
            'reference' => 'ONL-SALE0002',
        ]);

        $this->actingAs($this->admin)
            ->post(route('platform.revenue.withdrawals.reject', $sale->id), ['_idempotency_key' => $this->idempotencyKey()])
            ->assertNotFound();
    }

    public function test_non_platform_users_cannot_use_the_withdrawal_routes(): void
    {
        $tx = $this->withdrawal();
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);
        $operator = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $operator->assignRole('operator_admin');

        $this->actingAs($operator)
            ->post(route('platform.revenue.withdrawals.approve', $tx->id), ['_idempotency_key' => $this->idempotencyKey()])
            ->assertForbidden();

        $this->assertSame('pending', Transaction::withoutTenancy()->find($tx->id)->status);
    }

    private function operatorAdmin(): User
    {
        Role::findOrCreate('operator_admin', 'web');
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole('operator_admin');

        return $user;
    }
}
