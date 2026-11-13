<?php

namespace Tests\Feature;

use App\Models\BlockedDevice;
use App\Models\Package;
use App\Models\Router;
use App\Models\Session;
use App\Models\Site;
use App\Models\SiteAgent;
use App\Models\SiteAgentCommand;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\AccessRevoker;
use App\Services\RadiusRouterCredentialService;
use App\Services\RouterConnectionService;
use App\Services\Tenancy\TenantContext;
use App\Services\VoucherActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SiteAgentControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_waits_for_site_agent_to_provision_router_access(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, $site, $router, $agent, $voucher] = $this->createSiteSetup();

        $this->assertSame('site_agent', $router->fresh()->access_backend);

        $session = app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)
            ->activate($tenant, $voucher, Request::create('/'), '0771234567'));

        $this->assertSame('provisioning', $session->status);
        $command = SiteAgentCommand::query()->sole();
        $this->assertSame('provision_user', $command->operation);
        $this->assertSame($agent->id, $command->site_agent_id);
        $this->assertSame($router->id, $command->router_id);
        $this->assertSame($voucher->code, $command->payload['username']);
        $this->assertSame('package-'.$session->package_id, $command->payload['profile']);
        $this->assertSame('10M/10M', $command->payload['profile_settings']['rate-limit']);
        $this->assertSame(2, $command->payload['profile_settings']['shared-users']);
        $this->assertArrayNotHasKey('limit-uptime', $command->payload['profile_settings']);
        $this->assertArrayNotHasKey('limit-bytes-total', $command->payload['profile_settings']);
        $this->assertSame(5368709120, $command->payload['limit_bytes_total']);
        $this->assertArrayNotHasKey('rate_limit', $command->payload);
        $this->assertArrayNotHasKey('shared_users', $command->payload);
        $this->assertMatchesRegularExpression('/^00:59:[0-5][0-9]$/', $command->payload['limit_uptime']);
        $this->assertArrayNotHasKey('api_password', $command->payload);
    }

    public function test_radius_activation_is_pending_and_explicit_router_rollback_uses_site_agent(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, $site, $router, $agent, $radiusVoucher] = $this->createSiteSetup();
        app(RadiusRouterCredentialService::class)->optIn($router, 'radius-rollback-test');
        $agent->delete();

        $this->post("http://{$tenant->slug}.localhost/portal/redeem", [
            'voucher_code' => $radiusVoucher->code,
            'router_id' => $router->id,
        ])->assertRedirect("http://{$tenant->slug}.localhost/portal/success");
        $radiusSession = Session::query()->where('voucher_id', $radiusVoucher->id)->sole();

        $this->assertSame('provisioning', $radiusSession->status);
        $this->assertSame('redeemed', $radiusVoucher->fresh()->status);
        $this->assertDatabaseCount('site_agent_commands', 0);

        // Rollback is an explicit per-router backend change, not automatic fallback.
        $router->forceFill(['access_backend' => 'site_agent'])->saveOrFail();
        $agent = SiteAgent::create([
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'name' => 'Replacement Site Agent',
            'token_hash' => hash('sha256', 'replacement-agent-token'),
        ]);

        $agentSession = app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)
            ->activate($tenant, $radiusVoucher, Request::create('/'), '0771234567'));

        $this->assertSame($radiusSession->id, $agentSession->id);
        $this->assertSame('provisioning', $agentSession->status);
        $command = SiteAgentCommand::query()->sole();
        $this->assertSame('provision_user', $command->operation);
        $this->assertSame($agent->id, $command->site_agent_id);
        $this->assertSame($agentSession->id, $command->session_id);
        $this->assertSame('redeemed', $radiusVoucher->fresh()->status);
        $this->assertDatabaseCount('sessions_log', 1);
        $this->assertSame('site_agent', $router->fresh()->access_backend);
    }

    public function test_incomplete_radius_router_fails_closed_without_agent_fallback_or_entitlement_mutation(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , $router, , $voucher] = $this->createSiteSetup();
        $router->forceFill([
            'access_backend' => 'radius',
            'radius_nas_identifier' => 'radius-incomplete-test',
            'radius_shared_secret' => null,
        ])->saveOrFail();

        try {
            app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)
                ->activate($tenant, $voucher, Request::create('/'), '0771234567'));
            $this->fail('Activation unexpectedly fell back from an incomplete RADIUS router.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('RADIUS router is not fully configured', $exception->getMessage());
        }

        $this->assertSame('unused', $voucher->fresh()->status);
        $this->assertDatabaseCount('sessions_log', 0);
        $this->assertDatabaseCount('site_agent_commands', 0);
    }

    public function test_paired_agent_can_poll_and_acknowledge_provision_and_expiry(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , , $agent, $voucher] = $this->createSiteSetup();
        $session = app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)
            ->activate($tenant, $voucher, Request::create('/'), '0771234567'));
        $token = 'one-time-site-token';
        $agent->update(['token_hash' => hash('sha256', $token)]);

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/site-agent/commands')
            ->assertOk()
            ->assertJsonPath('commands.0.operation', 'provision_user')
            ->assertJsonPath('commands.0.payload.username', $voucher->code);
        $command = SiteAgentCommand::query()->where('operation', 'provision_user')->firstOrFail();
        $this->assertSame('claimed', $command->fresh()->status);

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson("/api/site-agent/commands/{$command->id}/complete", [
            'success' => true,
        ])->assertOk()->assertJsonPath('status', 'completed');
        $this->assertSame('active', Session::withoutTenancy()->findOrFail($session->id)->status);

        $session->update(['expires_at' => now()->subSecond()]);
        $this->artisan('sessions:expire')->assertSuccessful();
        $expiry = SiteAgentCommand::query()->where('operation', 'expire_user')->firstOrFail();
        $this->assertSame('pending', $expiry->status);

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/site-agent/commands')
            ->assertOk()
            ->assertJsonPath('commands.0.operation', 'expire_user');
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson("/api/site-agent/commands/{$expiry->id}/complete", [
            'success' => true,
        ])->assertOk()->assertJsonPath('status', 'completed');

        $this->assertSame('expired', Session::withoutTenancy()->findOrFail($session->id)->status);
    }

    public function test_manual_session_disconnect_voids_voucher_and_waits_for_router_confirmation(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , , $agent, $voucher] = $this->createSiteSetup();
        $session = app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)
            ->activate($tenant, $voucher, Request::create('/'), '0771234567'));
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole(Role::findOrCreate('operator_admin', 'web'));
        $agent->update(['token_hash' => hash('sha256', 'manual-disconnect-token')]);

        $this->actingAs($operator)
            ->postJson("http://{$tenant->slug}.localhost/operator/sessions/{$session->id}/disconnect", [
                'reason' => 'Repeated policy violations',
            ])
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending');

        $this->assertSame('voided', $voucher->fresh()->status);
        $this->assertSame('disconnecting', $session->fresh()->status);
        $this->assertTrue($session->fresh()->pending_revocation);
        $command = SiteAgentCommand::query()->where('operation', 'expire_user')->sole();
        $this->assertSame('disconnected', $command->payload['session_status']);

        $this->withHeader('Authorization', 'Bearer manual-disconnect-token')
            ->getJson('/api/site-agent/commands')
            ->assertOk();
        $this->withHeader('Authorization', 'Bearer manual-disconnect-token')
            ->postJson("/api/site-agent/commands/{$command->id}/complete", ['success' => true])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->assertSame('disconnected', $session->fresh()->status);
        $this->assertFalse($session->fresh()->pending_revocation);
    }

    public function test_router_confirmed_mac_ban_disconnects_device_without_voiding_voucher(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , $router, $agent, $voucher] = $this->createSiteSetup();
        $session = app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)
            ->activate($tenant, $voucher, Request::create('/'), '0771234567'));
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole(Role::findOrCreate('operator_network', 'web'));
        $agent->update(['token_hash' => hash('sha256', 'mac-ban-token')]);

        $this->actingAs($operator)
            ->postJson("http://{$tenant->slug}.localhost/operator/sessions/{$session->id}/ban-device", [
                'reason' => 'Repeated policy violations',
            ])
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending');

        $this->assertSame('disconnecting', $session->fresh()->status);
        $this->assertTrue($session->fresh()->pending_device_ban);
        $command = SiteAgentCommand::query()->where('operation', 'ban_mac')->sole();
        $this->assertSame($router->id, $command->router_id);

        $this->withHeader('Authorization', 'Bearer mac-ban-token')
            ->getJson('/api/site-agent/commands')
            ->assertOk();
        $this->withHeader('Authorization', 'Bearer mac-ban-token')
            ->postJson("/api/site-agent/commands/{$command->id}/complete", [
                'success' => true,
                'result' => ['mac_address' => 'AA:BB:CC:DD:EE:FF'],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $blocked = BlockedDevice::query()->sole();
        $this->assertSame('AA:BB:CC:DD:EE:FF', $blocked->mac_address);
        $this->assertSame('blocked', $blocked->status);
        $this->assertSame('disconnected', $session->fresh()->status);
        $this->assertFalse($session->fresh()->pending_device_ban);
        $this->assertSame('redeemed', $voucher->fresh()->status);
    }

    public function test_voucher_revocation_also_queues_during_a_pending_device_ban(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , , , $voucher] = $this->createSiteSetup();
        $session = app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)
            ->activate($tenant, $voucher, Request::create('/'), '0771234567'));
        $session->update([
            'status' => 'disconnecting',
            'pending_device_ban' => true,
        ]);

        app(TenantContext::class)->runAs($tenant, fn () => app(AccessRevoker::class)
            ->revoke($voucher, 'operator requested full revocation', 'disconnected'));

        $this->assertTrue($session->fresh()->pending_device_ban);
        $this->assertTrue($session->fresh()->pending_revocation);
        $this->assertSame('disconnected', SiteAgentCommand::query()
            ->where('operation', 'expire_user')
            ->sole()
            ->payload['session_status']);
    }

    public function test_pending_revocation_remains_pending_when_site_agent_is_unavailable(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, $site, , $agent, $voucher] = $this->createSiteSetup();
        $session = app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)
            ->activate($tenant, $voucher, Request::create('/'), '0771234567'));
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole(Role::findOrCreate('operator_admin', 'web'));
        $agent->delete();

        $this->actingAs($operator)
            ->postJson("http://{$tenant->slug}.localhost/operator/sessions/{$session->id}/disconnect", [
                'reason' => 'Repeated policy violations',
            ])
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('site_agent_online', false);

        $this->assertSame('voided', $voucher->fresh()->status);
        $this->assertSame('disconnecting', $session->fresh()->status);
        $this->assertTrue($session->fresh()->pending_revocation);
        $this->assertDatabaseMissing('site_agent_commands', ['operation' => 'expire_user']);

        SiteAgent::create([
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'name' => 'Replacement Agent',
            'token_hash' => hash('sha256', 'replacement-token'),
            'last_seen_at' => now(),
        ]);
        $this->artisan('sessions:retry-revocations')->assertSuccessful();

        $command = SiteAgentCommand::query()->where('operation', 'expire_user')->sole();
        $this->assertSame('pending', $command->status);
        $this->assertSame('disconnected', $command->payload['session_status']);
        $this->assertTrue($session->fresh()->pending_revocation);
    }

    public function test_production_activation_fails_closed_when_router_is_ambiguous(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , , , $voucher] = $this->createSiteSetup();
        $otherSite = Site::create(['tenant_id' => $tenant->id, 'name' => 'South Site']);
        Router::create([
            'tenant_id' => $tenant->id,
            'site_id' => $otherSite->id,
            'name' => 'South Router',
            'status' => 'online',
        ]);

        try {
            app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)
                ->activate($tenant, $voucher, Request::create('/'), '0771234567'));
            $this->fail('Activation unexpectedly chose a router without router context.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('not bound to a router', $exception->getMessage());
        }

        $this->assertSame('unused', $voucher->fresh()->status);
        $this->assertDatabaseCount('sessions_log', 0);
    }

    public function test_production_activation_fails_closed_without_a_paired_site_agent(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , , $agent, $voucher] = $this->createSiteSetup();
        $agent->delete();

        try {
            app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)
                ->activate($tenant, $voucher, Request::create('/'), '0771234567'));
            $this->fail('Activation unexpectedly succeeded without a paired agent.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('paired site agent', $exception->getMessage());
        }

        $this->assertSame('unused', $voucher->fresh()->status);
        $this->assertDatabaseCount('sessions_log', 0);
    }

    public function test_bulk_profile_sync_does_not_claim_router_is_online_without_a_health_check(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , $router, $agent] = $this->createSiteSetup();
        $router->update(['status' => 'offline']);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole(Role::findOrCreate('operator_admin', 'web'));
        $token = 'profile-sync-token';
        $agent->update(['token_hash' => hash('sha256', $token)]);

        $response = $this->actingAs($operator)
            ->post("http://{$tenant->slug}.localhost/operator/settings/router/sync-all");

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Profile sync queued for 1 of 1 router(s); 0 could not be queued.');
        $command = SiteAgentCommand::query()->where('operation', 'sync_profile')->sole();
        $package = Package::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertNull($command->session_id);
        $this->assertSame('pending', $command->status);
        $this->assertSame('package-'.$package->id, $command->payload['profiles'][0]['name']);
        $this->assertSame('10M/10M', $command->payload['profiles'][0]['rate-limit']);
        $this->assertArrayNotHasKey('limit-uptime', $command->payload['profiles'][0]);
        $this->assertArrayNotHasKey('limit-bytes-total', $command->payload['profiles'][0]);
        $this->assertSame('offline', $router->fresh()->status);
        $this->actingAs($operator)
            ->get("http://{$tenant->slug}.localhost/operator/settings/router")
            ->assertOk()
            ->assertSee('Latest profile sync per router')
            ->assertSee('Pending — awaiting site-agent result');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/site-agent/commands')
            ->assertOk()
            ->assertJsonPath('commands.0.operation', 'sync_profile')
            ->assertJsonPath('commands.0.payload.profiles.0.rate-limit', '10M/10M');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/site-agent/commands/{$command->id}/complete", ['success' => true])
            ->assertOk()
            ->assertJsonPath('status', 'completed');
        $this->assertSame('offline', $router->fresh()->status);
        $this->assertNull($router->fresh()->last_seen_at);
    }

    public function test_failed_final_profile_sync_does_not_mark_router_online(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , $router, $agent] = $this->createSiteSetup();
        $router->update(['status' => 'offline']);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole(Role::findOrCreate('operator_admin', 'web'));
        $agent->update(['token_hash' => hash('sha256', 'failed-profile-token')]);

        $this->actingAs($operator)
            ->post("http://{$tenant->slug}.localhost/operator/settings/router/sync-all")
            ->assertRedirect();
        $command = SiteAgentCommand::query()->where('operation', 'sync_profile')->sole();
        $this->withHeader('Authorization', 'Bearer failed-profile-token')
            ->getJson('/api/site-agent/commands')
            ->assertOk();
        $command->forceFill(['attempts' => 5])->save();

        $this->withHeader('Authorization', 'Bearer failed-profile-token')
            ->postJson("/api/site-agent/commands/{$command->id}/complete", [
                'success' => false,
                'message' => 'Router rejected profile settings.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'failed');

        $this->assertSame('offline', $router->fresh()->status);
        $this->assertSame('failed', $command->fresh()->status);
    }

    public function test_bulk_profile_sync_reports_routers_without_a_paired_agent(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , $router] = $this->createSiteSetup();
        $router->update(['status' => 'offline']);
        $unpairedRouter = Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'Unpaired Router',
            'status' => 'offline',
        ]);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole(Role::findOrCreate('operator_admin', 'web'));

        $response = $this->actingAs($operator)
            ->post("http://{$tenant->slug}.localhost/operator/settings/router/sync-all");

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Profile sync queued for 1 of 2 router(s); 1 could not be queued.');
        $response->assertSessionHas('router_sync_results', function (array $results): bool {
            return $results[0]['status'] === 'pending'
                && $results[1]['router'] === 'Unpaired Router'
                && $results[1]['status'] === 'failed';
        });
        $this->assertSame('offline', $router->fresh()->status);
        $this->assertSame('offline', $unpairedRouter->fresh()->status);
    }

    public function test_router_api_test_is_queued_and_agent_result_controls_router_status(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , $router, $agent] = $this->createSiteSetup();
        $router->update(['status' => 'offline']);
        $token = 'router-api-test-token';
        $agent->update(['token_hash' => hash('sha256', $token)]);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole(Role::findOrCreate('operator_admin', 'web'));

        $this->actingAs($operator)
            ->postJson("http://{$tenant->slug}.localhost/operator/routers/{$router->id}/test")
            ->assertStatus(202)
            ->assertJsonPath('queued', true);

        $command = SiteAgentCommand::query()->where('operation', 'test_connection')->sole();
        $this->assertSame('offline', $router->fresh()->status);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/site-agent/commands')
            ->assertOk()
            ->assertJsonPath('commands.0.operation', 'test_connection');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/site-agent/commands/{$command->id}/complete", ['success' => true])
            ->assertOk()
            ->assertJsonPath('status', 'completed');
        $this->assertSame('online', $router->fresh()->status);
        $this->assertNotNull($router->fresh()->last_seen_at);
    }

    public function test_agent_telemetry_reports_router_metrics_and_drives_health_status(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , $router, $agent] = $this->createSiteSetup();
        $router->update(['status' => 'offline']);
        $token = 'router-telemetry-token';
        $agent->update(['token_hash' => hash('sha256', $token)]);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole(Role::findOrCreate('operator_admin', 'web'));

        $this->actingAs($operator)
            ->postJson("http://{$tenant->slug}.localhost/operator/routers/{$router->id}/telemetry")
            ->assertStatus(202)
            ->assertJsonPath('queued', true);

        $command = SiteAgentCommand::query()->where('operation', 'telemetry')->sole();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/site-agent/commands')
            ->assertOk()
            ->assertJsonPath('commands.0.operation', 'telemetry');
        $completionUrl = "/api/site-agent/commands/{$command->id}/complete";
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson($completionUrl, ['success' => true])
            ->assertUnprocessable();
        $this->assertSame('claimed', $command->fresh()->status);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson($completionUrl, [
                'success' => true,
                'telemetry' => [
                    'cpu_usage' => 17,
                    'memory_usage' => 62,
                    'active_users' => 8,
                    'uptime' => '4d12h',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $reported = $router->fresh();
        $this->assertSame('online', $reported->status);
        $this->assertSame(17, $reported->cpu_usage);
        $this->assertSame(62, $reported->memory_usage);
        $this->assertSame(8, $reported->active_users);
        $this->assertSame('4d12h', $reported->uptime);
        $this->assertNotNull($reported->telemetry_at);
        $this->assertNotNull($reported->last_seen_at);
        $this->assertSame(17, app(RouterConnectionService::class)->liveStatus($reported)['cpu_load']);
    }

    public function test_stale_router_health_checks_are_marked_offline(): void
    {
        [$tenant, , $staleRouter] = $this->createSiteSetup();
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole(Role::findOrCreate('operator_admin', 'web'));
        $staleRouter->update(['status' => 'online', 'last_seen_at' => now()->subMinutes(3)]);
        $recentRouter = Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'Recent Router',
            'status' => 'online',
            'last_seen_at' => now()->subMinute(),
        ]);

        $this->artisan('routers:mark-stale')->assertSuccessful();

        $this->assertSame('offline', $staleRouter->fresh()->status);
        $this->assertSame('online', $recentRouter->fresh()->status);
        $notification = $operator->notifications()->get()->first(
            fn ($notification) => $notification->data['title'] === 'Router health alert'
        );
        $this->assertNotNull($notification);
        $this->assertSame('Router health alert', $notification->data['title']);
        $this->assertStringContainsString('health checks marked the router offline', $notification->data['message']);
    }

    public function test_legacy_router_settings_test_is_queued_in_agent_mode_and_blocked_without_direct_opt_in(): void
    {
        config(['site_agent.required' => true]);
        [$tenant, , , $agent] = $this->createSiteSetup();
        $tenant->update(['mikrotik_host' => '198.51.100.10']);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole(Role::findOrCreate('operator_admin', 'web'));

        $this->actingAs($operator)
            ->postJson("http://{$tenant->slug}.localhost/operator/settings/router/test")
            ->assertStatus(202)
            ->assertJsonPath('queued', true);
        $this->assertDatabaseHas('site_agent_commands', ['operation' => 'test_connection']);

        config([
            'site_agent.required' => false,
            'site_agent.allow_direct_router_connection' => false,
        ]);
        $this->actingAs($operator)
            ->postJson("http://{$tenant->slug}.localhost/operator/settings/router/test")
            ->assertStatus(409)
            ->assertJsonPath('success', false);
        $this->assertSame(1, SiteAgentCommand::query()->where('operation', 'test_connection')->count());
    }

    public function test_site_agent_api_rejects_missing_or_invalid_tokens(): void
    {
        [, , , $agent] = $this->createSiteSetup();
        $this->getJson('/api/site-agent/commands')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer invalid')->getJson('/api/site-agent/commands')->assertUnauthorized();

        $agent->update(['revoked_at' => now()]);
        $this->withHeader('Authorization', 'Bearer unused-token')->getJson('/api/site-agent/commands')->assertUnauthorized();
    }

    private function createSiteSetup(): array
    {
        $tenant = Tenant::create(['name' => 'Agent Test', 'slug' => 'agent-test', 'status' => 'active']);
        $site = Site::create(['tenant_id' => $tenant->id, 'name' => 'North Site']);
        $router = Router::create([
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'name' => 'North Router',
            'status' => 'online',
        ]);
        $agent = SiteAgent::create([
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'name' => 'North Site Agent',
            'token_hash' => hash('sha256', 'not-used-token'),
        ]);
        $package = Package::create([
            'tenant_id' => $tenant->id,
            'name' => 'One hour',
            'price' => 1000,
            'currency' => 'UGX',
            'duration_value' => 1,
            'duration_unit' => 'hours',
            'speed_limit_mbps' => 10,
            'device_limit' => 2,
            'data_limit' => '5GB',
            'radius_profile_name' => 'guest-hour',
        ]);
        $batch = VoucherBatch::create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'batch_code' => 'AGENT-TEST',
            'quantity' => 1,
        ]);
        $voucher = Voucher::create([
            'tenant_id' => $tenant->id,
            'voucher_batch_id' => $batch->id,
            'package_id' => $package->id,
            'code' => 'AGENT-001',
            'status' => 'unused',
        ]);

        return [$tenant, $site, $router, $agent, $voucher];
    }
}
