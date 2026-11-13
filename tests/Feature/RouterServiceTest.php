<?php

namespace Tests\Feature;

use App\Services\HotspotProfileSynchronizer;
use App\Services\RouterService;
use App\Services\SiteAgentRouterService;
use RuntimeException;
use Tests\TestCase;
use ZillEAli\MikrotikLaravel\Connections\RouterosClient;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;
use ZillEAli\MikrotikLaravel\MikrotikManager;
use ZillEAli\MikrotikLaravel\Testing\MikrotikFake;

class RouterServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site_agent.required' => false,
            'site_agent.allow_direct_router_connection' => true,
        ]);
    }

    public function test_it_creates_a_hotspot_user_on_the_router()
    {
        // 1. Set up the fake to expect a call to '/ip/hotspot/user/add'
        $fake = MikrotikFake::fake([
            '/ip/hotspot/user/add' => [], // Return an empty success response
        ]);

        // 2. Call our service
        $service = new RouterService;
        $service->createHotspotUser('KL-TEST-1234', 'Daily-Express');

        // 3. Assert that the fake router was actually queried with the right command
        $fake->assertQueried('/ip/hotspot/user/add');
    }

    public function test_it_fetches_active_sessions()
    {
        // 1. Set up the fake to return some dummy session data
        $fake = MikrotikFake::fake([
            '/ip/hotspot/active/print' => [
                ['name' => 'user1', 'address' => '10.0.0.1'],
                ['name' => 'user2', 'address' => '10.0.0.2'],
            ],
        ]);

        // 2. Call our service
        $service = new RouterService;
        $sessions = $service->getActiveSessions();

        // 3. Assert the data came through
        $this->assertCount(2, $sessions);
    }

    public function test_site_agent_telemetry_collects_router_resources_and_hotspot_users(): void
    {
        config([
            'site_agent.routers.42' => [
                'host' => '192.0.2.42',
                'username' => 'agent',
                'password' => 'local-secret',
                'port' => 8729,
            ],
        ]);
        MikrotikFake::fake([
            '/system/resource/print' => [
                ['uptime' => '2d4h', 'cpu-load' => 12, 'free-memory' => 50, 'total-memory' => 100],
            ],
            '/ip/hotspot/active/print' => [
                ['user' => 'guest-1'],
                ['user' => 'guest-2'],
            ],
        ]);

        $telemetry = (new SiteAgentRouterService(new HotspotProfileSynchronizer))
            ->execute(42, 'telemetry', []);

        $this->assertSame([
            'cpu_usage' => 12,
            'memory_usage' => 50,
            'active_users' => 2,
            'uptime' => '2d4h',
        ], $telemetry);
    }

    public function test_site_agent_bans_the_router_reported_mac_and_kicks_that_active_host(): void
    {
        config([
            'site_agent.routers.42' => [
                'host' => '192.0.2.42',
                'username' => 'agent',
                'password' => 'local-secret',
                'port' => 8729,
            ],
        ]);
        $fake = MikrotikFake::fake([
            '/ip/hotspot/active/print' => [
                ['.id' => '*A1', 'user' => 'voucher-1', 'mac-address' => 'aa:bb:cc:dd:ee:ff'],
            ],
            '/ip/hotspot/ip-binding/print' => [],
            '/ip/hotspot/ip-binding/add' => [],
            '/ip/hotspot/active/remove' => [],
        ]);

        $result = (new SiteAgentRouterService(new HotspotProfileSynchronizer))
            ->execute(42, 'ban_mac', ['username' => 'voucher-1']);

        $this->assertSame(['mac_address' => 'AA:BB:CC:DD:EE:FF'], $result);
        $fake->assertQueried('/ip/hotspot/ip-binding/add');
        $fake->assertQueried('/ip/hotspot/active/remove');
    }

    public function test_site_agent_refuses_to_ban_when_router_reports_multiple_macs_for_a_voucher(): void
    {
        config([
            'site_agent.routers.42' => [
                'host' => '192.0.2.42',
                'username' => 'agent',
                'password' => 'local-secret',
            ],
        ]);
        MikrotikFake::fake([
            '/ip/hotspot/active/print' => [
                ['.id' => '*A1', 'user' => 'voucher-1', 'mac-address' => 'AA:BB:CC:DD:EE:01'],
                ['.id' => '*A2', 'user' => 'voucher-1', 'mac-address' => 'AA:BB:CC:DD:EE:02'],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('multiple devices');

        (new SiteAgentRouterService(new HotspotProfileSynchronizer))
            ->execute(42, 'ban_mac', ['username' => 'voucher-1']);
    }

    public function test_site_agent_unban_removes_existing_blocked_ip_binding(): void
    {
        config([
            'site_agent.routers.42' => [
                'host' => '192.0.2.42',
                'username' => 'agent',
                'password' => 'local-secret',
            ],
        ]);
        $client = new SiteAgentTestRouterosClient([], [
            '/ip/hotspot/ip-binding/print' => [
                '!re',
                '=.id=*B1',
                '=type=blocked',
                '=mac-address=AA:BB:CC:DD:EE:FF',
                '',
                '!done',
            ],
        ]);
        $manager = new SiteAgentTestMikrotikManager($client);
        app()->instance(MikrotikManager::class, $manager);
        app()->instance('mikrotik', $manager);
        MikroTik::clearResolvedInstance();

        $this->assertNull((new SiteAgentRouterService(new HotspotProfileSynchronizer))
            ->execute(42, 'unban_mac', ['mac_address' => 'AA:BB:CC:DD:EE:FF']));
        $this->assertContains('/ip/hotspot/ip-binding/remove', $client->recordedQueries());
    }
}

class SiteAgentTestRouterosClient extends RouterosClient
{
    private array $recorded = [];

    public function __construct(
        private readonly array $queryResponses,
        private readonly array $rawResponses,
    ) {
        parent::__construct(host: '127.0.0.1');
    }

    public function query(string $command, array $params = [], array $queries = []): array
    {
        $this->recorded[] = $command;

        return $this->queryResponses[$command] ?? [];
    }

    public function sendRaw(string $command, array $params = []): array
    {
        $this->recorded[] = $command;

        return $this->rawResponses[$command] ?? [];
    }

    public function recordedQueries(): array
    {
        return $this->recorded;
    }
}

class SiteAgentTestMikrotikManager extends MikrotikManager
{
    public function __construct(private readonly SiteAgentTestRouterosClient $client)
    {
        parent::__construct(config('mikrotik', []));
    }

    protected function getClient(): RouterosClient
    {
        return $this->client;
    }
}
