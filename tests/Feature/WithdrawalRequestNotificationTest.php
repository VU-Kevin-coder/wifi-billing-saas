<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\WithdrawalRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
