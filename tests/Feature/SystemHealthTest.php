<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('file')->forget('system-health:last-alert-state');
        Cache::store('file')->forget('system-health:platform-admin-emails');
        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
    }

    public function test_readiness_endpoint_reports_live_dependency_and_scheduler_status(): void
    {
        config(['queue.default' => 'sync']);

        $this->artisan('system:health-check')->assertSuccessful();

        $this->getJson('/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('checks.database', 'healthy')
            ->assertJsonPath('checks.cache', 'healthy')
            ->assertJsonPath('checks.scheduler', 'healthy')
            ->assertJsonPath('checks.queue', 'healthy');
    }

    public function test_stale_scheduler_is_reported_as_degraded(): void
    {
        config(['queue.default' => 'sync']);
        Cache::put('system-health:scheduler-heartbeat', now()->subMinutes(5)->timestamp, 300);

        $this->getJson('/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.scheduler', 'unhealthy');
    }

    public function test_health_transition_emails_active_platform_admins_and_configured_recipients(): void
    {
        config(['queue.default' => 'database']);
        Cache::put('system-health:scheduler-heartbeat', now()->timestamp, 300);

        $activeAdmin = User::factory()->create([
            'tenant_id' => null,
            'email' => 'active-admin@example.test',
        ]);
        $activeAdmin->assignRole('platform_admin');
        $inactiveAdmin = User::factory()->create([
            'tenant_id' => null,
            'email' => 'inactive-admin@example.test',
            'deactivated_at' => now(),
        ]);
        $inactiveAdmin->assignRole('platform_admin');
        PlatformSetting::set('alerts.platform_emails', ['platform-ops@example.test', 'invalid-email']);

        Mail::shouldReceive('raw')
            ->twice()
            ->withArgs(fn (string $body, callable $callback): bool => str_contains($body, 'queue'))
            ->andReturnNull();

        $this->getJson('/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.queue', 'unverified');
    }
}
