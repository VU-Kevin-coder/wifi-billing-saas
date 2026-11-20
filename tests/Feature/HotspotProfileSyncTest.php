<?php

namespace Tests\Feature;

use App\Services\HotspotProfileSynchronizer;
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

    public function test_it_creates_a_missing_router_profile(): void
    {
        $profile = $this->profileSettings();
        $hotspot = Mockery::mock(HotspotManager::class);
        $hotspot->shouldReceive('getProfiles')->once()->andReturn([]);
        $hotspot->shouldReceive('createProfile')->once()->with($profile);

        app(HotspotProfileSynchronizer::class)->sync('router-7', $hotspot, $profile);
        $this->addToAssertionCount(1);
    }

    public function test_it_updates_an_existing_router_profile_by_routeros_id(): void
    {
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
        $pool->shouldReceive('get')->once()->with('router-7')->andReturn($client);
        MikroTik::shouldReceive('getPool')->once()->andReturn($pool);

        app(HotspotProfileSynchronizer::class)->sync('router-7', $hotspot, $profile);
        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_existing_profiles_without_routeros_id(): void
    {
        $profile = $this->profileSettings();
        $hotspot = Mockery::mock(HotspotManager::class);
        $hotspot->shouldReceive('getProfiles')->once()->andReturn([
            ['name' => $profile['name']],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('did not include its RouterOS identifier');

        app(HotspotProfileSynchronizer::class)->sync('router-7', $hotspot, $profile);
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
