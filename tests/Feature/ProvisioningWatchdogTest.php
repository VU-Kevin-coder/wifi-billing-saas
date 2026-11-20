<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Router;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ProvisioningStuck;
use App\Services\ProvisioningWatchdog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProvisioningWatchdogTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Session $session;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Notification::fake();
        PlatformSetting::set('refunds.auto_request', false);
        Role::findOrCreate('operator_admin', 'web');

        $this->tenant = Tenant::create(['name' => 'Router tenant', 'slug' => 'router-tenant']);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin->assignRole('operator_admin');
        $package = Package::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'One hour',
            'price' => 1000,
            'currency' => 'UGX',
            'duration_value' => 1,
            'duration_unit' => 'hours',
            'speed_limit_mbps' => 10,
            'device_limit' => 1,
        ]);
        $router = Router::create(['tenant_id' => $this->tenant->id, 'name' => 'RADIUS router']);
        $this->session = Session::create([
            'tenant_id' => $this->tenant->id,
            'router_id' => $router->id,
            'package_id' => $package->id,
            'status' => 'provisioning',
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
    }

    public function test_recent_radius_activation_waits_without_alerting(): void
    {
        $this->assertSame('waiting', app(ProvisioningWatchdog::class)->inspect($this->session));
        Notification::assertNotSentTo($this->admin, ProvisioningStuck::class);
    }

    public function test_stalled_radius_activation_alerts_once_without_agent_commands(): void
    {
        $this->session->update(['started_at' => now()->subMinutes(11)]);
        $watchdog = app(ProvisioningWatchdog::class);

        $this->assertSame('alerted', $watchdog->inspect($this->session->fresh()));
        $this->assertSame('already_alerted', $watchdog->inspect($this->session->fresh()));
        Notification::assertSentToTimes($this->admin, ProvisioningStuck::class, 1);
        $this->assertDatabaseCount('site_agent_commands', 0);
    }

    public function test_activation_expiring_before_radius_confirmation_is_closed(): void
    {
        $this->session->update(['expires_at' => now()->subSecond()]);

        $this->assertSame('expired', app(ProvisioningWatchdog::class)->inspect($this->session->fresh()));
        $this->assertSame('expired', $this->session->fresh()->status);
    }
}
