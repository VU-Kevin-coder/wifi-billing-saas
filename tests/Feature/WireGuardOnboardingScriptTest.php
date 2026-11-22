<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\VpnPeer;
use App\Services\WireGuardCommandRunner;
use App\Services\WireGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class WireGuardOnboardingScriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_script_sets_tls_radius_and_enrollment_without_enabling_plaintext_api(): void
    {
        config([
            'app.url' => 'https://billing.example.test',
            'wireguard.enabled' => true,
            'wireguard.interface' => 'wg0',
            'wireguard.server_address' => '10.8.0.1',
            'wireguard.endpoint' => '198.51.100.10',
            'wireguard.listen_port' => 51820,
            'wireguard.keepalive' => 25,
            'wireguard.router_interface' => 'wifi-billing',
        ]);
        $serverPublicKey = base64_encode(random_bytes(32));
        $commandRunner = Mockery::mock(WireGuardCommandRunner::class);
        $commandRunner->shouldReceive('run')
            ->once()
            ->with(['wg', 'show', 'wg0', 'public-key'])
            ->andReturn($serverPublicKey);
        $this->app->instance(WireGuardCommandRunner::class, $commandRunner);

        $tenant = Tenant::create([
            'name' => 'WireGuard Onboarding Test',
            'slug' => 'wireguard-onboarding-'.Str::lower(Str::random(6)),
            'status' => 'active',
        ]);
        $router = Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'Router Script Test',
            'api_username' => 'billing_api',
            'api_password' => 'private-test-password',
            'api_port' => 8729,
            'enable_hotspot' => true,
        ]);
        $peer = VpnPeer::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'name' => 'Script test peer',
            'public_key' => base64_encode(random_bytes(32)),
            'private_key' => base64_encode(random_bytes(32)),
            'tunnel_ip' => '10.8.1.20',
            'enabled' => true,
        ]);
        $router->forceFill(['radius_nas_identifier' => 'router-'.$router->id])->saveOrFail();

        $script = app(WireGuardService::class)->onboardingConfiguration(
            $peer,
            $router->fresh(),
            str_repeat('T', 64),
            str_repeat('a', 64),
            str_repeat('b', 64),
        );

        $this->assertStringStartsWith('/file remove [find name="wifi-billing-bootstrap.rsc"]'.PHP_EOL, $script);
        $this->assertStringContainsString('api-ssl certificate="wifi-billing-api-server" port=8729', $script);
        $this->assertStringContainsString('name="wifi-billing-api-template"', $script);
        $this->assertStringContainsString('name="wifi-billing-api-server"', $script);
        $this->assertStringNotContainsString('sign [find name="wifi-billing-api"] name="wifi-billing-api"', $script);
        $this->assertStringNotContainsString('[/system license get system-id]', $script);
        $templateRemovalPosition = strpos($script, ':do { /certificate remove [find name="wifi-billing-api-template"] } on-error={}');
        $serverRemovalPosition = strpos($script, ':do { /certificate remove [find name="wifi-billing-api-server"] } on-error={}');
        $certificateAddPosition = strpos($script, '/certificate add name="wifi-billing-api-template"');
        $certificateSignPosition = strpos($script, '/certificate sign [find name="wifi-billing-api-template"] name="wifi-billing-api-server"');
        $this->assertNotFalse($templateRemovalPosition);
        $this->assertNotFalse($serverRemovalPosition);
        $this->assertNotFalse($certificateAddPosition);
        $this->assertNotFalse($certificateSignPosition);
        $this->assertLessThan($certificateAddPosition, $templateRemovalPosition);
        $this->assertLessThan($certificateAddPosition, $serverRemovalPosition);
        $this->assertLessThan($certificateSignPosition, $certificateAddPosition);
        $this->assertStringContainsString('/ip cloud set update-time=yes', $script);
        $this->assertStringContainsString(
            '/system ntp client servers remove [find where address="time.cloudflare.com"]',
            $script,
        );
        $this->assertStringContainsString('/system ntp client set enabled=yes mode=unicast', $script);
        $this->assertStringContainsString(
            ':do { /system ntp client servers add address="time.cloudflare.com" disabled=no } on-error={}',
            $script,
        );
        $this->assertStringNotContainsString('[/system ntp client get status]', $script);
        $this->assertStringNotContainsString('NTP synchronization failed', $script);
        $this->assertStringNotContainsString(':delay 10s', $script);
        $this->assertStringNotContainsString(':while ', $script);
        $this->assertStringNotContainsString('servers=time.cloudflare.com', $script);
        $ntpSetupPosition = strpos($script, '/system ntp client set enabled=yes mode=unicast');
        $enrollmentPosition = strpos($script, '/tool fetch url="https://billing.example.test/api/provision/enroll"');
        $this->assertNotFalse($ntpSetupPosition);
        $this->assertNotFalse($enrollmentPosition);
        $this->assertLessThan($enrollmentPosition, $ntpSetupPosition);
        $this->assertStringContainsString('subject-alt-name="IP:10.8.1.20"', $script);
        $this->assertStringNotContainsString('; /certificate sign', $script);
        $this->assertStringNotContainsString('} else={', $script);
        $this->assertStringContainsString('/ip service set api disabled=yes', $script);
        $this->assertStringContainsString('service=hotspot authentication-port=1812 accounting-port=1813', $script);
        $this->assertStringContainsString('use-radius=yes radius-accounting=yes', $script);
        $this->assertStringContainsString('https://billing.example.test/api/provision/enroll', $script);
        $this->assertStringContainsString(
            'http-header-field="Content-Type: application/json,Accept: application/json"',
            $script,
        );
        $this->assertStringContainsString('check-certificate=yes output=user', $script);
        $this->assertStringContainsString('check-certificate=yes', $script);
        $this->assertStringContainsString('Authorization: Bearer ', $script);
        $this->assertStringContainsString('set authHeader ($authHeader . "'.str_repeat('b', 64).',Content-Type: application/x-www-form-urlencoded")', $script);
        $this->assertStringContainsString('http-header-field=$authHeader', $script);
        $this->assertStringContainsString('source={'.PHP_EOL.':local totalMemory', $script);
        $this->assertStringNotContainsString('source=":local totalMemory', $script);
        $this->assertStringContainsString('/ip hotspot profile remove [find name="prof-billing"]', $script);
        $this->assertStringContainsString(
            '/ip hotspot profile add name="prof-billing"',
            $script,
        );
        $this->assertStringNotContainsString(
            '/ip hotspot profile add name="prof-billing" comment=',
            $script,
        );
        $profileAccounting = '/ip hotspot profile set [find name="prof-billing"] use-radius=yes radius-accounting=yes';
        $profileInterim = '/ip hotspot profile set [find name="prof-billing"] radius-interim-update=00:01:00';
        $this->assertStringContainsString($profileAccounting, $script);
        $this->assertStringContainsString($profileInterim, $script);
        $this->assertLessThanOrEqual(120, strlen($profileAccounting));
        $this->assertLessThanOrEqual(120, strlen($profileInterim));
        $this->assertStringContainsString('comment="WiFiBillingAPI"', $script);
        $this->assertStringContainsString('comment="WiFiBillingRADIUS"', $script);
        $this->assertStringContainsString('comment="WiFiBillingOnboarding"', $script);
        $this->assertStringNotContainsString('radius-interim-update=1m', $script);
        $this->assertStringNotContainsString('comment="Wi-Fi Billing API"', $script);
        $this->assertStringNotContainsString('comment="Wi-Fi Billing RADIUS"', $script);
        $this->assertStringNotContainsString('} else={ /radius set', $script);
        $this->assertStringContainsString(':if ([:len [/radius find comment="WiFiBillingRADIUS"]] = 0) do={ /radius add', $script);
        $this->assertStringContainsString(
            ':if ([:len [/radius find comment="WiFiBillingRADIUS"]] > 0) do={'.PHP_EOL
                .'  /radius set [find comment="WiFiBillingRADIUS"]',
            $script,
        );
        $this->assertStringNotContainsString('Authorization: Bearer '.str_repeat('b', 64), $script);
        foreach (explode(PHP_EOL, $script) as $line) {
            $this->assertSame(0, preg_match('/\badd\b.*\bcomment\s*=/', $line), $line);
            $this->assertLessThanOrEqual(300, strlen($line), 'RouterOS physical line exceeds 300 characters: '.$line);
        }
        $this->assertStringContainsString('interval=1m on-event="/system script run wifi-billing-poll"', $script);
        $this->assertStringContainsString('/ip hotspot active remove $activeId', $script);
        $this->assertStringContainsString('/ppp active remove $activeId', $script);
        $this->assertStringContainsString('targetService = "pppoe"', $script);
        $this->assertStringNotContainsString(':parse', strtolower($script));
        $this->assertDoesNotMatchRegularExpression('/(?:^|[\s:])eval(?:\s|\[|\{)/im', $script);
        $this->assertStringContainsString('/file get [find name="wifi-billing-api.crt"] contents', $script);
        // Read the PEM and collect enrollment identity from a script execution context.
        $this->assertStringContainsString(
            '/certificate export-certificate "wifi-billing-api-server" type=pem file-name="wifi-billing-api"',
            $script,
        );
        $enrollmentScriptStart = strpos(
            $script,
            '/system script add name="wifi-billing-enroll" policy=read,write,test source={',
        );
        $enrollmentScriptEnd = strpos($script, PHP_EOL.'}', $enrollmentScriptStart);
        $this->assertNotFalse($enrollmentScriptStart);
        $this->assertNotFalse($enrollmentScriptEnd);
        $enrollmentScript = substr(
            $script,
            $enrollmentScriptStart,
            $enrollmentScriptEnd - $enrollmentScriptStart + strlen(PHP_EOL.'}'),
        );
        $this->assertStringContainsString(
            ':local apiCertificate [/file get [find name="wifi-billing-api.crt"] contents]',
            $enrollmentScript,
        );
        $this->assertStringContainsString(
            ':local routerSerial [/system routerboard get serial-number]',
            $enrollmentScript,
        );
        $this->assertStringContainsString(
            ':if ([:len $routerSerial] = 0) do={ :set routerSerial "UNKNOWN_SERIAL" }',
            $enrollmentScript,
        );
        $this->assertStringContainsString(
            '/tool fetch url="https://billing.example.test/api/provision/enroll"',
            $enrollmentScript,
        );
        $this->assertStringNotContainsString(':local apiCertificate', substr($script, 0, $enrollmentScriptStart));
        $this->assertStringNotContainsString(':local routerSerial', substr($script, 0, $enrollmentScriptStart));

        $runPosition = strpos($script, '/system script run wifi-billing-enroll');
        $removeScriptPosition = strpos($script, '/system script remove [find name="wifi-billing-enroll"]', $runPosition);
        $removeCertificatePosition = strpos($script, '/file remove [find name="wifi-billing-api.crt"]', $removeScriptPosition);
        $this->assertNotFalse($runPosition);
        $this->assertNotFalse($removeScriptPosition);
        $this->assertNotFalse($removeCertificatePosition);
        $this->assertLessThan($runPosition, $enrollmentScriptEnd);
        $this->assertLessThan($removeScriptPosition, $runPosition);
        $this->assertLessThan($removeCertificatePosition, $removeScriptPosition);
        $this->assertStringNotContainsString(':for certAttempt', $script);
        $this->assertStringContainsString('~"END CERTIFICATE"', $enrollmentScript);
        $this->assertStringContainsString(
            ':error "wifi-billing: API certificate export failed or is empty; enrollment aborted"',
            $enrollmentScript,
        );
        $this->assertStringNotContainsString('router serial number is empty', $script);
        $this->assertLessThan(
            strpos($script, '/tool fetch url="https://billing.example.test/api/provision/enroll"'),
            strpos($script, ':error "wifi-billing: API certificate export failed'),
        );
        $this->assertStringNotContainsString('cert_export_wifi-billing-api.crt', $script);
        $this->assertStringNotContainsString('check-certificate=no', $script);
        $this->assertNull($peer->fresh()->private_key);
        $this->assertNotNull($peer->fresh()->config_downloaded_at);
    }
}
