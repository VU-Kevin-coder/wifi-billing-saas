<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Site;
use App\Models\SiteAgent;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SiteAgentOffline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SiteAgentOfflineAlertTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'offline-alert-test-token';

    public function test_offline_agent_alert_is_sent_once_and_a_heartbeat_resets_it(): void
    {
        $this->freezeTime();
        Notification::fake();
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);
        $tenant = Tenant::create(['name' => 'North Wi-Fi', 'slug' => 'north-wifi', 'status' => 'active']);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('operator_admin');
        $site = Site::create(['tenant_id' => $tenant->id, 'name' => 'North']);
        $agent = SiteAgent::create([
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'name' => 'North Agent',
            'token_hash' => hash('sha256', self::TOKEN),
            'last_seen_at' => now()->subMinutes(20),
        ]);
        PlatformSetting::set('alerts.agent_offline_after', 15);

        $this->artisan('site-agents:notify-offline')->assertSuccessful();
        $this->artisan('site-agents:notify-offline')->assertSuccessful();

        Notification::assertSentToTimes($admin, SiteAgentOffline::class, 1);
        $this->assertNotNull($agent->fresh()->offline_notified_at);

        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
            ->getJson('/api/site-agent/commands')
            ->assertOk();
        $this->assertNull($agent->fresh()->offline_notified_at);
        $this->assertSame(now()->toDateTimeString(), $agent->fresh()->last_seen_at->toDateTimeString());
    }

    public function test_offline_alerts_can_be_disabled(): void
    {
        $this->freezeTime();
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);
        $tenant = Tenant::create(['name' => 'North Wi-Fi', 'slug' => 'north-wifi', 'status' => 'active']);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('operator_admin');
        $site = Site::create(['tenant_id' => $tenant->id, 'name' => 'North']);
        $agent = SiteAgent::create([
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'name' => 'North Agent',
            'token_hash' => hash('sha256', self::TOKEN),
            'last_seen_at' => now()->subHour(),
        ]);
        Notification::fake();
        PlatformSetting::set('alerts.notify_agent_offline', false);

        $this->artisan('site-agents:notify-offline')->assertSuccessful();

        Notification::assertNotSentTo($admin, SiteAgentOffline::class);
        $this->assertNull($agent->fresh()->offline_notified_at);
    }
}
