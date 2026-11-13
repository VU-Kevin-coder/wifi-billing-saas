<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VoucherBatch;
use App\Notifications\ActivityNotification;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ActivityNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_and_tenant_activity_is_persisted_and_visible_in_the_notifications_api(): void
    {
        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);
        $platformAdmin = User::factory()->create(['tenant_id' => null]);
        $platformAdmin->assignRole('platform_admin');

        $tenant = Tenant::create(['name' => 'North Wi-Fi', 'slug' => 'north', 'status' => 'active']);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole('operator_admin');

        $this->actingAs($platformAdmin)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonFragment(['title' => 'New tenant signup'])
            ->assertJsonFragment(['title' => 'New user signup'])
            ->assertJsonPath('unread_count', 2);

        app(TenantContext::class)->runAs($tenant, function () use ($tenant, $operator): void {
            $package = Package::create([
                'name' => 'Daily',
                'price' => 1000,
                'currency' => 'UGX',
                'duration_value' => 1,
                'duration_unit' => 'days',
                'speed_limit_mbps' => 5,
                'device_limit' => 1,
            ]);
            $package->update(['name' => 'Daily Plus']);

            $site = Site::create(['tenant_id' => $tenant->id, 'name' => 'Central']);
            $site->update(['location' => 'Kampala']);

            $router = Router::create([
                'tenant_id' => $tenant->id,
                'name' => 'North-01',
                'status' => 'provisioning',
            ]);
            Cache::forget('router-health-alert:'.$router->id);
            $router->update(['status' => 'offline']);

            VoucherBatch::create([
                'tenant_id' => $tenant->id,
                'package_id' => $package->id,
                'created_by' => $operator->id,
                'batch_code' => 'NORTH-001',
                'quantity' => 25,
            ]);

            $tenant->update(['subscription_status' => 'active']);
        });

        $this->actingAs($operator)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 8);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $operator->id,
            'type' => ActivityNotification::class,
        ]);
    }

    public function test_router_failure_notifications_are_rate_limited_and_recovery_is_reported(): void
    {
        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);
        $platformAdmin = User::factory()->create(['tenant_id' => null]);
        $platformAdmin->assignRole('platform_admin');
        $tenant = Tenant::create(['name' => 'North Wi-Fi', 'slug' => 'north', 'status' => 'active']);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole('operator_admin');

        $router = app(TenantContext::class)->runAs($tenant, fn () => Router::create([
            'name' => 'North-01',
            'status' => 'online',
        ]));

        Cache::forget('router-health-alert:'.$router->id);
        $router->update(['status' => 'offline']);
        $router->update(['status' => 'online']);

        $this->assertSame(3, $operator->notifications()->count());
        $this->assertSame(4, $platformAdmin->notifications()->count());
        $this->assertTrue($operator->notifications()->get()->contains(
            fn ($notification) => $notification->data['title'] === 'Router recovered'
        ));
    }
}
