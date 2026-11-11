<?php

namespace Tests\Feature;

use App\Services\RouterTransportConfig;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RouterTransportConfigTest extends TestCase
{
    public function test_api_ssl_port_enables_tls_and_production_peer_verification(): void
    {
        config([
            'app.env' => 'production',
            'site_agent.router_ssl_verify_peer' => true,
            'site_agent.router_ca_cert_path' => '/etc/ssl/router-ca.pem',
        ]);

        $options = RouterTransportConfig::forRouter('10.8.1.2', 8729, 17);

        $this->assertSame([
            'host' => '10.8.1.2',
            'port' => 8729,
            'ssl' => true,
            'verify_peer' => true,
            'ca_cert_path' => '/etc/ssl/router-ca.pem',
        ], $options);
    }

    public function test_plain_api_in_production_emits_a_warning(): void
    {
        config(['app.env' => 'production']);
        Log::shouldReceive('warning')
            ->once()
            ->with('MikroTik router connection is using plaintext API.', [
                'router_id' => 17,
                'port' => 8728,
            ]);

        $options = RouterTransportConfig::forRouter('192.0.2.10', 8728, 17);

        $this->assertFalse($options['ssl']);
    }

    public function test_certificate_verification_cannot_be_disabled_in_production(): void
    {
        config([
            'app.env' => 'production',
            'site_agent.router_ssl_verify_peer' => false,
        ]);
        Log::shouldReceive('critical')
            ->once()
            ->with('MikroTik API-SSL certificate verification is disabled in production.', ['router_id' => 17]);

        $options = RouterTransportConfig::forRouter('10.8.1.2', 8729, 17);

        $this->assertTrue($options['verify_peer']);
    }
}
