<?php

namespace App\Services;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\VpnPeer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WireGuardService
{
    public function __construct(private WireGuardCommandRunner $commands) {}

    public function assertOnboardingReady(): void
    {
        $this->assertEnabled();
        $interface = $this->interfaceName();
        $this->endpoint();
        $this->serverPublicKey($interface);
    }

    public function createPeer(Router $router, string $name): VpnPeer
    {
        $this->assertEnabled();
        $interface = $this->interfaceName();
        $endpoint = $this->endpoint();
        $serverPublicKey = $this->serverPublicKey($interface);

        if ($router->vpnPeer()->exists()) {
            throw new RuntimeException('This router already has a WireGuard peer. Revoke or rotate it before generating another.');
        }

        $privateKey = $this->commands->run(['wg', 'genkey']);
        $this->assertWireGuardKey($privateKey);
        $publicKey = $this->commands->run(['wg', 'pubkey'], $privateKey.PHP_EOL);
        $this->assertWireGuardKey($publicKey);

        $peer = DB::transaction(function () use ($router, $name, $privateKey, $publicKey): VpnPeer {
            $tenant = Tenant::withoutGlobalScopes()->lockForUpdate()->findOrFail($router->tenant_id);
            $subnet = $tenant->vpn_subnet ?: $this->allocateTenantSubnet();
            if ($tenant->vpn_subnet === null) {
                $tenant->forceFill(['vpn_subnet' => $subnet])->save();
            }

            $tunnelIp = $this->allocateTunnelIp($tenant, $subnet);

            return VpnPeer::create([
                'tenant_id' => $tenant->id,
                'router_id' => $router->id,
                'name' => $name,
                'public_key' => $publicKey,
                'private_key' => $privateKey,
                'tunnel_ip' => $tunnelIp,
                'enabled' => true,
            ]);
        });

        try {
            $this->commands->run([
                'wg', 'set', $interface, 'peer', $peer->public_key,
                'allowed-ips', $peer->tunnel_ip.'/32',
            ]);
        } catch (Throwable $exception) {
            $this->removePeerFromInterface($interface, $peer);
            $peer->delete();

            throw $exception;
        }

        return $peer;
    }

    public function routerConfiguration(VpnPeer $peer): string
    {
        $this->assertEnabled();

        return $this->deliverRouterConfiguration($peer, null);
    }

    public function onboardingConfiguration(
        VpnPeer $peer,
        Router $router,
        string $enrollmentToken,
        string $radiusSecret,
        string $pollingToken,
    ): string {
        $this->assertEnabled();
        if ((int) $peer->router_id !== (int) $router->id
            || blank($router->api_username)
            || blank($router->api_password)
            || ! preg_match('/^[A-Za-z0-9]{64}$/', $enrollmentToken)
            || ! preg_match('/^[a-f0-9]{64}$/i', $radiusSecret)
            || ! preg_match('/^[a-f0-9]{64}$/i', $pollingToken)) {
            throw new RuntimeException('The router API account must be configured before onboarding.');
        }

        return $this->deliverRouterConfiguration($peer, $router, $enrollmentToken, $radiusSecret, $pollingToken);
    }

    public function refreshHandshakes(): void
    {
        $this->assertEnabled();
        $output = $this->commands->run([
            'wg', 'show', $this->interfaceName(), 'latest-handshakes',
        ]);
        $handshakes = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            [$publicKey, $timestamp] = array_pad(preg_split('/\s+/', trim($line), 2) ?: [], 2, null);
            if (is_string($publicKey) && is_numeric($timestamp)) {
                $handshakes[$publicKey] = (int) $timestamp;
            }
        }

        VpnPeer::query()->where('enabled', true)->each(function (VpnPeer $peer) use ($handshakes): void {
            $timestamp = $handshakes[$peer->public_key] ?? 0;
            $handshake = $timestamp > 0 ? Carbon::createFromTimestamp($timestamp) : null;
            if ($peer->last_handshake_at?->timestamp !== $handshake?->timestamp) {
                $peer->forceFill(['last_handshake_at' => $handshake])->save();
            }
        });
    }

    public function syncPeers(): int
    {
        $this->assertEnabled();
        $interface = $this->interfaceName();
        $peers = VpnPeer::withoutGlobalScopes()
            ->where('enabled', true)
            ->get(['public_key', 'tunnel_ip']);

        foreach ($peers as $peer) {
            $this->commands->run([
                'wg', 'set', $interface, 'peer', $peer->public_key,
                'allowed-ips', $peer->tunnel_ip.'/32',
            ]);
        }

        return $peers->count();
    }

    private function allocateTenantSubnet(): string
    {
        for ($thirdOctet = 1; $thirdOctet <= 254; $thirdOctet++) {
            $subnet = "10.8.{$thirdOctet}.0/24";
            if (! Tenant::withoutGlobalScopes()->where('vpn_subnet', $subnet)->exists()) {
                return $subnet;
            }
        }

        throw new RuntimeException('No tenant WireGuard subnet is available in 10.8.1.0/24–10.8.254.0/24.');
    }

    private function allocateTunnelIp(Tenant $tenant, string $subnet): string
    {
        if (! preg_match('/^10\.8\.(\d{1,3})\.0\/24$/', $subnet, $matches)) {
            throw new RuntimeException('Tenant has an invalid WireGuard subnet assignment.');
        }

        for ($host = 2; $host <= 254; $host++) {
            $ip = "10.8.{$matches[1]}.{$host}";
            if (! $tenant->vpnPeers()->where('tunnel_ip', $ip)->exists()) {
                return $ip;
            }
        }

        throw new RuntimeException('No WireGuard tunnel address remains in the tenant subnet.');
    }

    private function serverPublicKey(string $interface): string
    {
        $key = $this->commands->run(['wg', 'show', $interface, 'public-key']);
        $this->assertWireGuardKey($key);

        return $key;
    }

    private function deliverRouterConfiguration(
        VpnPeer $peer,
        ?Router $router,
        ?string $enrollmentToken = null,
        ?string $radiusSecret = null,
        ?string $pollingToken = null,
    ): string {
        return DB::transaction(function () use ($peer, $router, $enrollmentToken, $radiusSecret, $pollingToken): string {
            $lockedPeer = VpnPeer::query()->findOrFail($peer->id);
            if (! $lockedPeer->enabled || blank($lockedPeer->private_key) || $lockedPeer->config_downloaded_at) {
                throw new RuntimeException('This WireGuard configuration is unavailable for download. Generate a new peer if it was already downloaded.');
            }

            $configuration = $this->buildRouterConfiguration($lockedPeer);
            if ($router && $enrollmentToken !== null && $radiusSecret !== null && $pollingToken !== null) {
                $configuration .= $this->buildApiConfiguration(
                    $router,
                    $lockedPeer,
                    $enrollmentToken,
                    $radiusSecret,
                    $pollingToken,
                );
            }

            $updated = VpnPeer::query()
                ->whereKey($lockedPeer->id)
                ->whereNull('config_downloaded_at')
                ->whereNotNull('private_key')
                ->update([
                    'config_downloaded_at' => now(),
                    'private_key' => null,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('This one-time WireGuard configuration was already downloaded.');
            }

            return $configuration;
        });
    }

    private function buildRouterConfiguration(VpnPeer $peer): string
    {
        $interface = $this->interfaceName();
        $serverPublicKey = $this->serverPublicKey($interface);
        $endpoint = $this->endpoint();
        $routerInterface = (string) config('wireguard.router_interface');
        $tunnelIp = $peer->tunnel_ip;
        $privateKey = $this->routerOsQuote($peer->private_key);
        $serverAddress = $this->serverAddress();
        $keepalive = (int) config('wireguard.keepalive', 25);
        $routerInterfaceQuoted = $this->routerOsQuote($routerInterface);
        $publicKey = $this->routerOsQuote($serverPublicKey);
        $endpointHost = $this->routerOsQuote($endpoint['host']);
        $endpointPort = $endpoint['port'];

        return implode(PHP_EOL, [
            '# One-time RouterOS WireGuard setup for router ID '.$peer->router_id,
            '# Apply only to the assigned router after reviewing its existing VPN configuration.',
            ':if ([:len [/interface wireguard find name="'.$routerInterface.'"]] = 0) do={ /interface wireguard add name="'.$routerInterfaceQuoted.'" listen-port=13231 private-key="'.$privateKey.'" }',
            ':if ([:len [/ip address find address="'.$tunnelIp.'/32" interface="'.$routerInterface.'"]] = 0) do={ /ip address add address="'.$tunnelIp.'/32" interface="'.$routerInterfaceQuoted.'" }',
            ':if ([:len [/interface wireguard peers find interface="'.$routerInterface.'" public-key="'.$serverPublicKey.'"]] = 0) do={ /interface wireguard peers add interface="'.$routerInterfaceQuoted.'" public-key="'.$publicKey.'" endpoint-address="'.$endpointHost.'" endpoint-port='.$endpointPort.' allowed-address="'.$serverAddress.'/32" persistent-keepalive='.$keepalive.'s }',
            ':if ([:len [/ip route find dst-address="'.$serverAddress.'/32" gateway="'.$routerInterface.'"]] = 0) do={ /ip route add dst-address="'.$serverAddress.'/32" gateway="'.$routerInterfaceQuoted.'" }',
            '/ip cloud set update-time=yes',
            ':delay 10s',
            '/ip service set api disabled=yes',
            '/ip service set api-ssl disabled=yes port=8729 address="'.$serverAddress.'/32"',
            '',
        ]);
    }

    private function buildApiConfiguration(
        Router $router,
        VpnPeer $peer,
        string $enrollmentToken,
        string $radiusSecret,
        string $pollingToken,
    ): string {
        $serverAddress = $this->routerOsQuote($this->serverAddress());
        $tunnelIp = $this->routerOsQuote($peer->tunnel_ip);
        $username = $this->routerOsQuote((string) $router->api_username);
        $password = $this->routerOsQuote((string) $router->api_password);
        $token = $this->routerOsQuote($enrollmentToken);
        $secret = $this->routerOsQuote($radiusSecret);
        $nasIdentifier = $this->routerOsQuote((string) $router->radius_nas_identifier);
        $pollingToken = $this->routerOsQuote($pollingToken);
        $radiusServices = $router->enable_hotspot && $router->enable_pppoe
            ? 'hotspot,ppp'
            : ($router->enable_pppoe ? 'ppp' : 'hotspot');
        $enrollmentUrl = $this->routerOsQuote(
            rtrim((string) config('app.url'), '/').route('api.provision.enroll', [], false),
        );
        $pollingUrl = $this->routerOsQuote(
            rtrim((string) config('app.url'), '/').route('api.router-commands.poll', [], false),
        );
        $acknowledgementUrl = $this->routerOsQuote(
            rtrim((string) config('app.url'), '/').route('api.router-commands.ack', [], false),
        );
        $pollingSource = $this->quoteRouterOsScript($this->pollingScript(
            $pollingUrl,
            $acknowledgementUrl,
            $pollingToken,
        ));

        return implode(PHP_EOL, [
            '# Router API is reachable only from the server through the encrypted WireGuard tunnel.',
            ':if ([:len [/user group find name="wifi-billing-api"]] = 0) do={ /user group add name="wifi-billing-api" policy=api,read,write comment="Wi-Fi Billing onboarding" }',
            ':if ([:len [/user find name="'.$username.'"]] = 0) do={ /user add name="'.$username.'" group="wifi-billing-api" password="'.$password.'" comment="Wi-Fi Billing API" } else={ /user set [find name="'.$username.'"] group="wifi-billing-api" password="'.$password.'" }',
            ':if ([:len [/certificate find name="wifi-billing-api"]] = 0) do={ /certificate add name="wifi-billing-api" common-name="'.$tunnelIp.'" subject-alt-name="IP:'.$tunnelIp.'" key-usage=key-cert-sign,crl-sign,digital-signature,key-encipherment,tls-server; /certificate sign [find name="wifi-billing-api"] name="wifi-billing-api" }',
            '/ip service set api disabled=yes',
            '/ip service set api-ssl certificate="wifi-billing-api" port=8729 address="'.$serverAddress.'/32" disabled=no',
            ':if ([:len [/radius find comment="Wi-Fi Billing RADIUS"]] = 0) do={ /radius add address="'.$serverAddress.'" src-address="'.$tunnelIp.'" secret="'.$secret.'" service='.$radiusServices.' authentication-port=1812 accounting-port=1813 timeout=1s comment="Wi-Fi Billing RADIUS" } else={ /radius set [find comment="Wi-Fi Billing RADIUS"] address="'.$serverAddress.'" src-address="'.$tunnelIp.'" secret="'.$secret.'" service='.$radiusServices.' authentication-port=1812 accounting-port=1813 timeout=1s }',
            ':if ([:len [/ip hotspot profile find name="prof-billing"]] = 0) do={ /ip hotspot profile add name="prof-billing" use-radius=yes radius-accounting=yes radius-interim-update=1m comment="Wi-Fi Billing managed profile" }',
            '/certificate export-certificate "wifi-billing-api" type=pem file-name="wifi-billing-api"',
            ':local apiCertificate [/file get [find name="wifi-billing-api.crt"] contents]',
            ':local routerSerial [/system routerboard get serial-number]',
            ':if ([:len $routerSerial] = 0) do={ :set routerSerial [/system license get system-id] }',
            ':local routerModel [/system resource get board-name]',
            ':local enrollmentData {"token"="'.$token.'";"serial"=$routerSerial;"model"=$routerModel;"api_ca_certificate"=$apiCertificate}',
            ':local enrollmentBody [:serialize value=$enrollmentData to=json]',
            '/tool fetch url="'.$enrollmentUrl.'" http-method=post http-data=$enrollmentBody http-header-field="Content-Type: application/json" check-certificate=yes output=none',
            '/file remove [find name="wifi-billing-api.crt"]',
            ':if ([:len [/system script find name="wifi-billing-poll"]] = 0) do={ /system script add name="wifi-billing-poll" policy=read,write,test source="'.$pollingSource.'" } else={ /system script set [find name="wifi-billing-poll"] policy=read,write,test source="'.$pollingSource.'" }',
            ':if ([:len [/system scheduler find name="wifi-billing-poll"]] = 0) do={ /system scheduler add name="wifi-billing-poll" interval=1m on-event="/system script run wifi-billing-poll" policy=read,write,test } else={ /system scheduler set [find name="wifi-billing-poll"] interval=1m on-event="/system script run wifi-billing-poll" policy=read,write,test }',
            '',
        ]);
    }

    private function pollingScript(string $pollingUrl, string $acknowledgementUrl, string $pollingToken): string
    {
        return implode(PHP_EOL, [
            ':local totalMemory [/system resource get total-memory]',
            ':local freeMemory [/system resource get free-memory]',
            ':local memoryUsage 0',
            ':if ($totalMemory > 0) do={ :set memoryUsage (100 - (($freeMemory * 100) / $totalMemory)) }',
            ':local cpuUsage [/system resource get cpu-load]',
            ':local activeUsers ([:len [/ip hotspot active find]] + [:len [/ppp active find]])',
            ':local pollBody ("cpu_usage=" . $cpuUsage . "&memory_usage=" . $memoryUsage . "&active_users=" . $activeUsers)',
            ':local pollResponse [/tool fetch url="'.$pollingUrl.'" http-method=post http-data=$pollBody http-header-field="Authorization: Bearer '.$pollingToken.',Content-Type: application/x-www-form-urlencoded" check-certificate=yes output=user as-value]',
            ':local pollData ($pollResponse->"data")',
            ':if ([:len $pollData] > 4) do={',
            '  :local firstSeparator [:find $pollData "|"]',
            '  :if ([:typeof $firstSeparator] = "num" && $firstSeparator > 0) do={',
            '    :local commandId [:pick $pollData 0 $firstSeparator]',
            '    :local firstRemainder [:pick $pollData ($firstSeparator + 1) [:len $pollData]]',
            '    :local secondSeparator [:find $firstRemainder "|"]',
            '    :if ([:typeof $secondSeparator] = "num" && $secondSeparator > 0) do={',
            '      :local leaseToken [:pick $firstRemainder 0 $secondSeparator]',
            '      :local secondRemainder [:pick $firstRemainder ($secondSeparator + 1) [:len $firstRemainder]]',
            '      :local thirdSeparator [:find $secondRemainder "|"]',
            '      :if ([:typeof $thirdSeparator] = "num" && $thirdSeparator > 0) do={',
            '        :local operation [:pick $secondRemainder 0 $thirdSeparator]',
            '        :local serviceAndUsername [:pick $secondRemainder ($thirdSeparator + 1) [:len $secondRemainder]]',
            '        :local fourthSeparator [:find $serviceAndUsername "|"]',
            '        :if ([:typeof $fourthSeparator] = "num" && $fourthSeparator > 0) do={',
            '          :local targetService [:pick $serviceAndUsername 0 $fourthSeparator]',
            '          :local username [:pick $serviceAndUsername ($fourthSeparator + 1) [:len $serviceAndUsername]]',
            '          :if ($operation = "kick" && ($targetService = "hotspot" || $targetService = "pppoe") && [:len $username] > 0 && [:len $username] <= 64 && $username~"^[A-Za-z0-9._-]+$") do={',
            '            :local activeIds [/ip hotspot active find where user=$username]',
            '            :if ($targetService = "pppoe") do={ :set activeIds [/ppp active find where name=$username] }',
            '            :if ($targetService = "hotspot") do={ :foreach activeId in=$activeIds do={ /ip hotspot active remove $activeId } }',
            '            :if ($targetService = "pppoe") do={ :foreach activeId in=$activeIds do={ /ppp active remove $activeId } }',
            '            :local resultCode "kicked"',
            '            :if ([:len $activeIds] = 0) do={ :set resultCode "already_absent" }',
            '            :local ackBody ("command_id=" . $commandId . "&lease_token=" . $leaseToken . "&result_code=" . $resultCode)',
            '            /tool fetch url="'.$acknowledgementUrl.'" http-method=post http-data=$ackBody http-header-field="Authorization: Bearer '.$pollingToken.',Content-Type: application/x-www-form-urlencoded" check-certificate=yes output=none',
            '          }',
            '        }',
            '      }',
            '    }',
            '  }',
            '}',
        ]);
    }

    private function quoteRouterOsScript(string $script): string
    {
        return str_replace(
            ['\\', '"', '$', "\r", "\n"],
            ['\\\\', '\\"', '\\$', '\\r', '\\n'],
            $script,
        );
    }

    private function assertEnabled(): void
    {
        if (! config('wireguard.enabled')) {
            throw new RuntimeException('WireGuard peer management is disabled. Configure and enable the host WireGuard interface first.');
        }
    }

    private function interfaceName(): string
    {
        $interface = (string) config('wireguard.interface', 'wg0');
        if (! preg_match('/^[a-zA-Z0-9_.-]{1,15}$/', $interface)) {
            throw new RuntimeException('Configured WireGuard interface name is invalid.');
        }

        return $interface;
    }

    private function serverAddress(): string
    {
        $address = (string) config('wireguard.server_address', '10.8.0.1');
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Configured WireGuard server address must be IPv4.');
        }

        return $address;
    }

    /**
     * @return array{host: string, port: int}
     */
    private function endpoint(): array
    {
        $endpoint = trim((string) config('wireguard.endpoint', ''));
        $port = (int) config('wireguard.listen_port', 51820);
        if ($endpoint === '' || $port < 1 || $port > 65535) {
            throw new RuntimeException('Set a public WireGuard endpoint and valid UDP listen port before generating peers.');
        }

        if (filter_var($endpoint, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            && ! preg_match('/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\.(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?))*$/', $endpoint)) {
            throw new RuntimeException('WireGuard endpoint must be a public IPv4 address or valid hostname.');
        }

        return ['host' => $endpoint, 'port' => $port];
    }

    private function assertWireGuardKey(string $key): void
    {
        $decoded = base64_decode($key, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new RuntimeException('WireGuard returned an invalid key; no peer was saved.');
        }
    }

    private function routerOsQuote(string $value): string
    {
        return addcslashes(str_replace(["\r", "\n", "\0"], '', $value), '\\"$');
    }

    private function removePeerFromInterface(string $interface, VpnPeer $peer): void
    {
        try {
            $this->commands->run(['wg', 'set', $interface, 'peer', $peer->public_key, 'remove']);
        } catch (Throwable $cleanupException) {
            Log::warning('WireGuard peer cleanup failed after provisioning error.', [
                'vpn_peer_id' => $peer->id,
                'exception' => $cleanupException::class,
            ]);
        }
    }
}
