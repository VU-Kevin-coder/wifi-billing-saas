<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\WithdrawalRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WithdrawalRequestNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_withdrawal_request_notifies_platform_admins(): void
    {
        Notification::fake();
        Role::findOrCreate('operator_admin', 'web');
        Role::findOrCreate('platform_admin', 'web');

        $tenant = Tenant::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'status' => 'active',
            'wallet_balance' => 50000,
        ]);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole('operator_admin');
        $platformAdmin = User::factory()->create(['tenant_id' => null]);
        $platformAdmin->assignRole('platform_admin');
        PlatformSetting::set('currency', 'UGX');
        URL::defaults(['tenant' => $tenant->slug]);

        $this->actingAs($operator)
            ->post(route('operator.wallet.withdraw'), [
                'amount' => 10000,
                'phone' => '0771234567',
                'password' => 'password',
                '_idempotency_key' => $this->idempotencyKey(),
            ])
            ->assertRedirect(route('operator.wallet.index', ['tenant' => $tenant->slug]))
            ->assertSessionHasNoErrors();

        Notification::assertSentTo(
            $platformAdmin,
            WithdrawalRequested::class,
            function (WithdrawalRequested $notification) use ($platformAdmin, $tenant): bool {
                $data = $notification->toDatabase($platformAdmin);
                $this->assertSame($tenant->id, $data['tenant_id']);
                $this->assertSame(
                    rtrim((string) config('app.url'), '/').route('platform.revenue.index', [], false),
                    $data['action_url'],
                );

                return true;
            },
        );
        $this->assertEquals(40000, (float) $tenant->fresh()->wallet_balance);
    }

    public function test_bank_transfer_request_encrypts_account_details_and_debits_the_wallet(): void
    {
        Role::findOrCreate('operator_admin', 'web');
        $tenant = Tenant::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'status' => 'active',
            'wallet_balance' => 50000,
        ]);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole('operator_admin');
        URL::defaults(['tenant' => $tenant->slug]);

        $this->actingAs($operator)
            ->get(route('operator.wallet.index', ['tenant' => $tenant->slug]))
            ->assertOk()
            ->assertSee('Payout method')
            ->assertSee('Bank Transfer');

        $this->actingAs($operator)
            ->post(route('operator.wallet.withdraw'), [
                'amount' => 10000,
                'payout_method' => 'bank_transfer',
                'bank_name' => 'Example Bank',
                'account_name' => 'A. Operator',
                'account_number' => '0123456789',
                'password' => 'password',
                '_idempotency_key' => $this->idempotencyKey(),
            ])
            ->assertRedirect(route('operator.wallet.index', ['tenant' => $tenant->slug]))
            ->assertSessionHasNoErrors();

        $withdrawal = Transaction::where('tenant_id', $tenant->id)->where('type', 'withdrawal')->firstOrFail();
        $this->assertSame('bank_transfer', $withdrawal->meta['payout_method']);
        $this->assertStringNotContainsString('0123456789', $withdrawal->meta['bank_details_encrypted']);
        $this->assertSame([
            'bank_name' => 'Example Bank',
            'account_name' => 'A. Operator',
            'account_number' => '0123456789',
        ], json_decode(Crypt::decryptString($withdrawal->meta['bank_details_encrypted']), true));
        $this->assertEquals(40000, (float) $tenant->fresh()->wallet_balance);
    }

    public function test_request_rejects_a_payout_method_disabled_by_the_platform(): void
    {
        Role::findOrCreate('operator_admin', 'web');
        $tenant = Tenant::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'status' => 'active',
            'wallet_balance' => 50000,
        ]);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole('operator_admin');
        PlatformSetting::set('wallet.payout_method', ['mtn_momo']);
        URL::defaults(['tenant' => $tenant->slug]);

        $this->actingAs($operator)
            ->from(route('operator.wallet.index', ['tenant' => $tenant->slug]))
            ->post(route('operator.wallet.withdraw'), [
                'amount' => 10000,
                'payout_method' => 'bank_transfer',
                'bank_name' => 'Example Bank',
                'account_name' => 'A. Operator',
                'account_number' => '0123456789',
                'password' => 'password',
                '_idempotency_key' => $this->idempotencyKey(),
            ])
            ->assertRedirect(route('operator.wallet.index', ['tenant' => $tenant->slug]))
            ->assertSessionHasErrors('payout_method');

        $this->assertDatabaseMissing('transactions', ['tenant_id' => $tenant->id, 'type' => 'withdrawal']);
    }
}
