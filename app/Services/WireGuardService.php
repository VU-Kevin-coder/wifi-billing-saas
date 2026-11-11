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

        return DB::transaction(function () use ($peer): string {
            $lockedPeer = VpnPeer::query()->findOrFail($peer->id);
            if (! $lockedPeer->enabled || blank($lockedPeer->private_key) || $lockedPeer->config_downloaded_at) {
                throw new RuntimeException('This WireGuard configuration is unavailable for download. Generate a new peer if it was already downloaded.');
            }

            $configuration = $this->buildRouterConfiguration($lockedPeer);
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
            '/ip service set api disabled=yes',
            '/ip service set api-ssl disabled=no port=8729 address="'.$serverAddress.'/32"',
            '',
        ]);
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
        return addcslashes(str_replace(["\r", "\n", "\0"], '', $value), '\\"');
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
