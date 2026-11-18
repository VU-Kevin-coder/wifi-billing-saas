<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Services\RouterConnectionService;
use App\Services\RouterTransportConfig;
use App\Services\RouterTransportException;
use App\Services\SiteAgentRouterService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use ZillEAli\MikrotikLaravel\Connections\RouterosClient;
use ZillEAli\MikrotikLaravel\Connections\RouterosClientSSL;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;
use ZillEAli\MikrotikLaravel\MikrotikManager;

class RouterTransportConfigTest extends TestCase
{
    public function test_tls_and_peer_verification_are_mandatory_across_environments_and_custom_ports(): void
    {
        foreach (['production', 'staging', 'local', 'testing'] as $environment) {
            app()['env'] = $environment;
            config(['app.env' => $environment, 'site_agent.router_ssl_verify_peer' => false, 'site_agent.router_ca_cert_path' => '/synthetic/ca.pem']);
            foreach ([8729, 12345] as $port) {
                $this->assertSame([
                    'host' => 'router.example.test', 'port' => $port, 'ssl' => true,
                    'verify_peer' => true, 'ca_cert_path' => '/synthetic/ca.pem',
                ], RouterTransportConfig::forRouter('router.example.test', $port, 17));
            }
        }
    }

    public function test_blocked_plaintext_has_a_non_secret_audit_log(): void
    {
        Log::shouldReceive('warning')->once()->with('MikroTik router plaintext API blocked.', ['router_id' => 17, 'port' => 8728]);
        $this->expectException(RouterTransportException::class);
        $this->expectExceptionMessage('plaintext API is blocked');
        RouterTransportConfig::forRouter('router.example.test', 8728, 17);
    }

    public function test_plaintext_requires_explicit_boolean_opt_in_in_actual_and_configured_testing_environment(): void
    {
        Log::shouldReceive('warning')->times(9);
        foreach ([null, false, 1, 'true'] as $optIn) {
            app()['env'] = 'testing';
            config(['app.env' => 'testing', 'site_agent.testing_allow_plaintext_router_api' => $optIn]);
            $this->assertPlaintextBlocked();
        }
        foreach (['production', 'staging', 'local'] as $environment) {
            app()['env'] = $environment;
            config(['app.env' => $environment, 'site_agent.testing_allow_plaintext_router_api' => true]);
            $this->assertPlaintextBlocked();
        }
        // Setting app.env alone must not impersonate Laravel's environment.
        app()['env'] = 'production';
        config(['app.env' => 'testing', 'site_agent.testing_allow_plaintext_router_api' => true]);
        $this->assertPlaintextBlocked();
        app()['env'] = 'testing';
        config(['app.env' => 'production']);
        $this->assertPlaintextBlocked();

        app()['env'] = 'testing';
        config(['app.env' => 'testing', 'site_agent.testing_allow_plaintext_router_api' => true]);
        $this->assertFalse(RouterTransportConfig::forRouter('192.0.2.1', 8728, 17)['ssl']);
        foreach ([8729, 12345] as $port) {
            $options = RouterTransportConfig::forRouter('router.example.test', $port, 17);
            $this->assertTrue($options['ssl']);
            $this->assertTrue($options['verify_peer']);
        }
    }

    public function test_bad_peer_identities_and_ports_are_rejected_in_all_environments(): void
    {
        foreach (['production', 'staging', 'local', 'testing'] as $environment) {
            app()['env'] = $environment;
            config(['app.env' => $environment]);
            foreach (['ssl://router.example.test', 'host:8729', '2001:db8::1', 'router.example.test/evil', ''] as $host) {
                try {
                    RouterTransportConfig::forRouter($host, 12345, 17);
                    $this->fail('Invalid peer accepted');
                } catch (RouterTransportException $e) {
                    $this->assertStringContainsString('certificate identity', $e->getMessage());
                }
            }
            $this->assertSame('192.0.2.1', RouterTransportConfig::forRouter('192.0.2.1', 8729, 17)['host']);
        }
        foreach ([0, -1, 65536] as $port) {
            try {
                RouterTransportConfig::forRouter('router.example.test', $port, 17);
                $this->fail('Invalid port accepted');
            } catch (RouterTransportException $e) {
                $this->assertSame('Router API port is invalid.', $e->getMessage());
            }
        }
    }

    public function test_client_really_uses_verified_tls_for_custom_port_without_connecting(): void
    {
        config(['site_agent.router_ca_cert_path' => '/synthetic/ca.pem']);
        $options = RouterTransportConfig::forRouter('router.example.test', 12345, 17);
        $manager = new class ($options) extends MikrotikManager {
            public function makeClient(): RouterosClient { return $this->getClient(); }
            protected function connectWithRetry(RouterosClient $client, int $attempts, int $delay, string $routerName = 'default', array $cfg = []): void {}
        };
        $client = $manager->makeClient();
        $this->assertInstanceOf(RouterosClientSSL::class, $client);
        $this->assertSame(12345, $client->getConnectionInfo()['port']);
        $this->assertTrue($client->getConnectionInfo()['verify_peer']);
        $property = new \ReflectionProperty(RouterosClientSSL::class, 'sslContext');
        $context = $property->getValue($client);
        $this->assertTrue($context['ssl']['verify_peer_name']);
        $this->assertFalse($context['ssl']['allow_self_signed']);
        $this->assertSame('/synthetic/ca.pem', $context['ssl']['cafile']);
    }

    public function test_plaintext_connection_never_reaches_client_without_opt_in(): void
    {
        config(['site_agent.required' => false, 'site_agent.allow_direct_router_connection' => true]);
        MikroTik::shouldReceive('router')->never();
        $result = app(RouterConnectionService::class)->test($this->router(8728));
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('plaintext API is blocked', $result['message']);
        $this->assertStringNotContainsString('synthetic-secret', json_encode($result));
    }

    public function test_client_errors_do_not_leak_secrets_to_diagnostics_or_logs(): void
    {
        config(['site_agent.required' => false, 'site_agent.allow_direct_router_connection' => true]);
        MikroTik::shouldReceive('router')->once()->andThrow(new RouterTransportException('synthetic-secret synthetic-user private-data'));
        Log::shouldReceive('warning')->once()->with('MikroTik LAN demo connection failed', [
            'router_id' => 17, 'exception' => RouterTransportException::class,
            'diagnostic' => 'Connection or authentication failed.',
        ]);
        $result = app(RouterConnectionService::class)->test($this->router(12345));
        $this->assertFalse($result['ok']);
        $this->assertSame('Connection or authentication failed.', $result['diagnostic']);
        foreach (['synthetic-secret', 'synthetic-user', 'private-data'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($result));
        }
    }

    public function test_required_site_agent_still_blocks_direct_connections(): void
    {
        config(['site_agent.required' => true, 'site_agent.allow_direct_router_connection' => true, 'site_agent.testing_allow_plaintext_router_api' => true]);
        MikroTik::shouldReceive('router')->never();
        $service = app(RouterConnectionService::class);
        $this->assertStringContainsString('site agent', $service->test($this->router(8728))['message']);
        $this->expectExceptionMessage('Direct router connections are disabled');
        $service->disconnectUser($this->router(8728), 'synthetic-voucher');
    }

    public function test_direct_address_remains_blocked_when_not_permitted(): void
    {
        config(['site_agent.required' => false, 'site_agent.allow_direct_router_connection' => false]);
        MikroTik::shouldReceive('router')->never();
        $result = app(RouterConnectionService::class)->test($this->router(8729));
        $this->assertFalse($result['ok']);
        $this->assertSame('missing_configuration', $result['failure_type']);
    }

    public function test_site_agent_router_map_cannot_bypass_transport_policy(): void
    {
        config(['site_agent.routers.17' => ['host' => 'router.example.test', 'port' => 8728, 'username' => 'synthetic-user', 'password' => 'synthetic-secret']]);
        MikroTik::shouldReceive('router')->never();
        $this->expectException(RouterTransportException::class);
        $this->expectExceptionMessage('plaintext API is blocked');
        app(SiteAgentRouterService::class)->execute(17, 'test_connection', []);
    }

    public function test_tenantless_legacy_operations_fail_closed_in_every_environment(): void
    {
        config(['site_agent.required' => false, 'site_agent.allow_direct_router_connection' => true]);
        MikroTik::shouldReceive('hotspot')->never();
        MikroTik::shouldReceive('router')->never();
        foreach (['production', 'staging', 'local', 'testing'] as $environment) {
            app()['env'] = $environment;
            config(['app.env' => $environment]);
            try {
                (new \App\Services\RouterService())->getActiveSessions();
                $this->fail('Tenantless router access was allowed.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('A tenant router with verified API-SSL is required.', $exception->getMessage());
            }
        }
    }

    public function test_disconnect_client_exception_is_replaced_without_a_cause(): void
    {
        config(['site_agent.required' => false, 'site_agent.allow_direct_router_connection' => true]);
        MikroTik::shouldReceive('router')->once()->andThrow(new \RuntimeException('synthetic-secret synthetic-user private-data'));
        try {
            app(RouterConnectionService::class)->disconnectUser($this->router(8729), 'synthetic-voucher');
            $this->fail('Disconnect must fail.');
        } catch (RouterTransportException $exception) {
            $this->assertSame('Unable to disconnect the router session.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('synthetic-secret', (string) $exception);
        }
    }

    private function assertPlaintextBlocked(): void
    {
        try {
            RouterTransportConfig::forRouter('router.example.test', 8728, 17);
            $this->fail('Plaintext accepted without a valid opt-in');
        } catch (RouterTransportException $e) {
            $this->assertStringContainsString('plaintext API is blocked', $e->getMessage());
        }
    }

    private function router(int $port): Router
    {
        $router = new Router(['management_ip' => '192.0.2.1', 'api_port' => $port, 'api_username' => 'synthetic-user', 'api_password' => 'synthetic-secret']);
        $router->id = 17;
        $router->setRelation('vpnPeer', null);
        return $router;
    }
}
