<?php

namespace Tests\Feature;

use App\Services\SiteAgentRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;
use ZillEAli\MikrotikLaravel\Connections\ConnectionPool;
use ZillEAli\MikrotikLaravel\Connections\RouterosClient;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;
use ZillEAli\MikrotikLaravel\Services\HotspotManager;

class HotspotProfileSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_agent_creates_missing_profile(): void
    {
        config(['site_agent.routers.7' => [
            'host' => '10.0.0.1',
            'username' => 'api',
            'password' => 'secret',
        ]]);
        $profile = $this->profileSettings();
        $hotspot = Mockery::mock(HotspotManager::class);
        $hotspot->shouldReceive('getProfiles')->once()->andReturn([]);
        $hotspot->shouldReceive('createProfile')->once()->with($profile);
        MikroTik::shouldReceive('router')->once()->with('site-agent-router-7')->andReturnSelf();
        MikroTik::shouldReceive('hotspot')->once()->andReturn($hotspot);

        app(SiteAgentRouterService::class)->execute(7, 'sync_profile', ['profiles' => [$profile]]);

        $this->addToAssertionCount(1);
    }

    public function test_site_agent_updates_existing_profile(): void
    {
        config(['site_agent.routers.7' => [
            'host' => '10.0.0.1',
            'username' => 'api',
            'password' => 'secret',
        ]]);
        $profile = $this->profileSettings();
        $hotspot = Mockery::mock(HotspotManager::class);
        $hotspot->shouldReceive('getProfiles')->once()->andReturn([
            ['.id' => '*3', 'name' => $profile['name'], 'rate-limit' => '1M/1M'],
        ]);
        $client = Mockery::mock(RouterosClient::class);
        $client->shouldReceive('query')->once()->with('/ip/hotspot/user/profile/set', [
            '.id' => '*3',
            ...$profile,
        ])->andReturn([]);
        $pool = Mockery::mock(ConnectionPool::class);
        $pool->shouldReceive('get')->once()->with('site-agent-router-7')->andReturn($client);
        MikroTik::shouldReceive('router')->once()->with('site-agent-router-7')->andReturnSelf();
        MikroTik::shouldReceive('hotspot')->once()->andReturn($hotspot);
        MikroTik::shouldReceive('getPool')->once()->andReturn($pool);

        app(SiteAgentRouterService::class)->execute(7, 'sync_profile', ['profiles' => [$profile]]);

        $this->addToAssertionCount(1);
    }

    public function test_user_provisioning_syncs_profile_before_add_without_profile_fields_on_user(): void
    {
        config(['site_agent.routers.7' => [
            'host' => '10.0.0.1',
            'username' => 'api',
            'password' => 'secret',
        ]]);
        $profile = $this->profileSettings();
        $hotspot = Mockery::mock(HotspotManager::class);
        $hotspot->shouldReceive('getProfiles')->once()->ordered()->andReturn([]);
        $hotspot->shouldReceive('createProfile')->once()->ordered()->with($profile);
        $hotspot->shouldReceive('getUser')->once()->ordered()->with('VOUCHER-7')->andReturn(null);
        $hotspot->shouldReceive('createUser')->once()->ordered()->with(Mockery::on(
            fn (array $user): bool => $user['profile'] === $profile['name']
                && ! array_key_exists('rate-limit', $user)
                && ! array_key_exists('shared-users', $user)
        ));
        MikroTik::shouldReceive('router')->once()->with('site-agent-router-7')->andReturnSelf();
        MikroTik::shouldReceive('hotspot')->once()->andReturn($hotspot);

        app(SiteAgentRouterService::class)->execute(7, 'provision_user', [
            'username' => 'VOUCHER-7',
            'profile' => $profile['name'],
            'profile_settings' => $profile,
        ]);

        $this->addToAssertionCount(1);
    }

    private function profileSettings(): array
    {
        return [
            'name' => 'package-7',
            'rate-limit' => '10M/10M',
            'shared-users' => 3,
        ];
    }
}
