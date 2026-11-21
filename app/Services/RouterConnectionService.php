<?php

namespace App\Services;

use App\Models\Router;
use App\Models\VpnPeer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;

/**
 * MikroTik API operations for a single saved router.
 *
 * Router records are the source of truth: their API password is encrypted by
 * the Router model, unlike the legacy tenant-level connection fields.
 */
class RouterConnectionService
{
    public function __construct(
        private RouterIdentityService $identityService,
    ) {}

    public function test(Router $router): array
    {
        $router->loadMissing('vpnPeer');
        $peer = $router->vpnPeer;
        $host = $peer?->enabled ? $peer->tunnel_ip : null;

        if (blank($host) || blank($router->api_username) || blank($router->api_password)) {
            return [
                'ok' => false,
                'failure_type' => 'missing_configuration',
                'message' => 'Router API test requires an enabled WireGuard peer and saved API credentials.',
                'diagnostic' => blank($host)
                    ? 'No enabled WireGuard tunnel address is configured for this router.'
                    : (blank($router->api_username)
                        ? 'The router API username is empty.'
                        : 'The router API password is empty.'),
            ];
        }

        try {
            $key = $this->configureRouter($router, $host, $peer);
            // This is deliberately read-only: it proves API reachability
            // without changing hotspot, users, profiles or firewall rules.
            $resource = MikroTik::router($key)->system()->getResources();
            $identity = $this->identityService->discover($router);
            if (! $this->identityService->matches($router, $identity)) {
                return [
                    'ok' => false,
                    'failure_type' => 'identity_not_verified',
                    'message' => 'Router identity is not enrolled or does not match. Complete read-only discovery and operator-confirmed enrollment before writes.',
                    'identity' => $identity,
                ];
            }

            return [
                'ok' => filled($resource),
                'message' => filled($resource)
                    ? 'Router API responded successfully.'
                    : 'Router API returned no system resource data.',
                'resource' => $resource,
            ];
        } catch (\Throwable $exception) {
            // Client exceptions may contain credentials or other private data.
            // Only disclose the fixed, non-secret transport validation messages.
            $safeTransportMessages = [
                'Router API port is invalid.',
                'Router API requires verified TLS; plaintext API is blocked.',
                'Router API-SSL requires a valid IPv4 address or DNS hostname for certificate identity verification.',
            ];
            $diagnostic = $exception instanceof RouterTransportException
                && in_array($exception->getMessage(), $safeTransportMessages, true)
                ? $exception->getMessage()
                : 'Connection or authentication failed.';

            Log::warning('MikroTik LAN demo connection failed', [
                'router_id' => $router->getKey(),
                'exception' => $exception::class,
                'diagnostic' => $diagnostic,
            ]);

            return [
                'ok' => false,
                'message' => 'Router API test failed. '.$diagnostic,
                'diagnostic' => $diagnostic,
            ];
        }
    }

    public function testOnboardingWireGuard(Router $router): array
    {
        $router->loadMissing('vpnPeer');
        $peer = $router->vpnPeer;
        if (! $peer || ! $peer->enabled || blank($router->api_username) || blank($router->api_password)) {
            return [
                'ok' => false,
                'message' => 'The router has not completed its WireGuard and API bootstrap.',
            ];
        }

        try {
            $key = $this->configureRouter($router, $peer->tunnel_ip, $peer);
            $resource = MikroTik::router($key)->system()->getResources();

            return [
                'ok' => filled($resource),
                'message' => filled($resource)
                    ? 'Router WireGuard and API connection verified.'
                    : 'Router API returned no system resource data.',
                'resource' => $resource,
            ];
        } catch (\Throwable $exception) {
            Log::notice('Router onboarding API verification failed.', [
                'router_id' => $router->getKey(),
                'exception' => $exception::class,
            ]);

            return [
                'ok' => false,
                'message' => 'Waiting for the router API to become reachable through WireGuard.',
            ];
        }
    }

    public function onboardingInterfaces(Router $router): array
    {
        $router->loadMissing('vpnPeer');
        $peer = $router->vpnPeer;
        if (! $peer || ! $peer->enabled) {
            throw new RouterTransportException('An enabled WireGuard peer is required.');
        }

        $key = $this->configureRouter($router, $peer->tunnel_ip, $peer);

        try {
            return MikroTik::router($key)->interfaces()->getInterfaces();
        } catch (\Throwable $exception) {
            Log::notice('Router onboarding could not read interface inventory.', [
                'router_id' => $router->id,
                'exception' => $exception::class,
            ]);

            throw new RouterTransportException('Unable to read router interfaces through the verified WireGuard connection.');
        }
    }

    /**
     * Read the target's live configuration before any service provisioning.
     * Secret-bearing PPP records are queried with an ID-only property list.
     *
     * @return array{ok: bool, message?: string, resource?: array, all_interfaces?: array, interfaces?: array, bridges?: array, bridge_ports?: array, addresses?: array, dhcp_servers?: array, dhcp_networks?: array, hotspot_servers?: array, hotspot_profiles?: array, pools?: array, pppoe_servers?: array, ppp_profiles?: array, vlan_interfaces?: array, nat_rules?: array, interface_list_members?: array, default_routes?: array, radius_clients?: array, ppp_active_count?: int, ppp_secret_count?: int}
     */
    public function inspectProvisioningTarget(Router $router): array
    {
        $connection = $this->test($router);
        if (! ($connection['ok'] ?? false) || ! is_array($connection['resource'] ?? null)) {
            return [
                'ok' => false,
                'message' => $connection['message'] ?? 'Router identity and API-SSL connection could not be verified.',
            ];
        }

        try {
            $router->loadMissing('vpnPeer');
            $peer = $router->vpnPeer;
            if (! $peer?->enabled) {
                return ['ok' => false, 'message' => 'An enabled WireGuard peer is required for router preflight.'];
            }
            $key = $this->configureRouter($router, $peer->tunnel_ip, $peer);
            $diagnostics = MikroTik::router($key)->diagnostics();
            $tables = [
                'all_interfaces' => ['/interface/print', []],
                'interfaces' => ['/interface/ethernet/print', []],
                'bridges' => ['/interface/bridge/print', []],
                'bridge_ports' => ['/interface/bridge/port/print', []],
                'addresses' => ['/ip/address/print', []],
                'dhcp_servers' => ['/ip/dhcp-server/print', []],
                'dhcp_networks' => ['/ip/dhcp-server/network/print', []],
                'hotspot_servers' => ['/ip/hotspot/print', []],
                'hotspot_profiles' => ['/ip/hotspot/profile/print', []],
                'pools' => ['/ip/pool/print', []],
                'pppoe_servers' => ['/interface/pppoe-server/server/print', []],
                'ppp_profiles' => ['/ppp/profile/print', []],
                'vlan_interfaces' => ['/interface/vlan/print', []],
                'nat_rules' => ['/ip/firewall/nat/print', [
                    '.proplist' => 'chain,action,out-interface,out-interface-list,disabled',
                ]],
                'interface_list_members' => ['/interface/list/member/print', [
                    '.proplist' => 'list,interface,disabled',
                ]],
                'default_routes' => ['/ip/route/print', [
                    '.proplist' => 'dst-address,gateway,immediate-gw,active,disabled',
                ]],
                'radius_clients' => ['/radius/print', [
                    '.proplist' => 'name,address,src-address,service,authentication-port,accounting-port,comment',
                ]],
                'ppp_active_count' => ['/ppp/active/print', []],
                'ppp_secret_count' => ['/ppp/secret/print', ['.proplist' => '.id']],
            ];
            $state = [
                'ok' => true,
                'resource' => $connection['resource'],
            ];
            foreach ($tables as $name => [$command, $params]) {
                $rows = $this->rawRows($diagnostics->getRaw($command, $params));
                $state[$name] = str_ends_with($name, '_count') ? count($rows) : $rows;
            }

            return $state;
        } catch (\Throwable $exception) {
            Log::warning('Router provisioning preflight could not read the target configuration.', [
                'router_id' => $router->id,
                'exception' => $exception::class,
            ]);

            return [
                'ok' => false,
                'message' => 'The router configuration could not be fully inspected. No router changes were made.',
            ];
        }
    }

    public function hasOnboardingRadiusConfiguration(Router $router): bool
    {
        $router->loadMissing('vpnPeer');
        $peer = $router->vpnPeer;
        if (! $peer?->enabled || ! $router->enrolled) {
            return false;
        }

        try {
            $key = $this->configureRouter($router, $peer->tunnel_ip, $peer);
            $radiusRows = $this->rawRows(MikroTik::router($key)->diagnostics()->getRaw('/radius/print'));
            $radius = collect($radiusRows)->first(
                fn (array $row): bool => in_array($row['comment'] ?? null, ['WiFiBillingRADIUS', 'Wi-Fi Billing RADIUS'], true),
            );
            $profileRows = $this->rawRows(MikroTik::router($key)->diagnostics()->getRaw('/ip/hotspot/profile/print'));
            $profile = collect($profileRows)->first(
                fn (array $row): bool => ($row['name'] ?? null) === 'prof-billing',
            );
            $serverAddress = (string) config('wireguard.server_address');
            $expectedServices = $router->enable_hotspot && $router->enable_pppoe
                ? 'hotspot,ppp'
                : ($router->enable_pppoe ? 'ppp' : 'hotspot');

            return is_array($radius)
                && ($radius['address'] ?? null) === $serverAddress
                && ($radius['src-address'] ?? null) === $peer->tunnel_ip
                && ($radius['service'] ?? null) === $expectedServices
                && ($radius['authentication-port'] ?? null) === '1812'
                && ($radius['accounting-port'] ?? null) === '1813'
                && is_array($profile)
                && in_array(strtolower((string) ($profile['use-radius'] ?? '')), ['yes', 'true'], true)
                && in_array(strtolower((string) ($profile['radius-accounting'] ?? '')), ['yes', 'true'], true);
        } catch (\Throwable $exception) {
            Log::warning('Router RADIUS configuration could not be verified.', [
                'router_id' => $router->id,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    public function executeOnboardingScript(Router $router, string $source): void
    {
        $router->loadMissing(['vpnPeer', 'onboarding']);
        $peer = $router->vpnPeer;
        if (! $peer
            || ! $peer->enabled
            || ! in_array($router->onboarding?->status, ['api_verified', 'radius_ready', 'complete'], true)) {
            throw new RouterTransportException('Only a router with verified WireGuard and API-SSL onboarding may be configured.');
        }

        $this->runTemporaryScript($router, $peer, $source, 'Temporary Wi-Fi Billing onboarding');
    }

    public function createProvisioningBackup(Router $router): string
    {
        $router->loadMissing(['vpnPeer', 'onboarding']);
        $peer = $router->vpnPeer;
        if (! $peer
            || ! $peer->enabled
            || ! in_array($router->onboarding?->status, ['api_verified', 'radius_ready', 'complete'], true)) {
            throw new RouterTransportException('A verified WireGuard and API-SSL connection is required before backing up the router.');
        }

        $backupName = 'wifi-billing-pre-'.$router->id.'-'.now()->format('YmdHis');
        $this->runTemporaryScript(
            $router,
            $peer,
            '/system backup save name="'.$backupName.'"',
            'Temporary Wi-Fi Billing backup',
        );

        $key = $this->configureRouter($router, $peer->tunnel_ip, $peer);
        $files = $this->rawRows(MikroTik::router($key)->diagnostics()->getRaw(
            '/file/print',
            ['.proplist' => 'name,type'],
        ));
        $expectedFile = $backupName.'.backup';
        if (! collect($files)->contains(fn (array $file): bool => ($file['name'] ?? null) === $expectedFile)) {
            throw new RouterTransportException('Router backup could not be verified. No provisioning changes were made.');
        }

        return $expectedFile;
    }

    private function runTemporaryScript(
        Router $router,
        VpnPeer $peer,
        string $source,
        string $comment,
    ): void {
        $key = $this->configureRouter($router, $peer->tunnel_ip, $peer);
        $scripts = MikroTik::router($key)->scripts();
        $name = 'wifi-billing-onboard-'.$router->id.'-'.strtolower(Str::random(8));
        $added = false;
        $runError = null;
        $cleanupError = null;

        try {
            $scripts->addScript($name, $source, 'read,write,api', $comment);
            $added = true;
            $scripts->runScript($name);
        } catch (\Throwable $exception) {
            $runError = $exception;
        } finally {
            if ($added) {
                try {
                    $scripts->removeScript($name);
                } catch (\Throwable $exception) {
                    $cleanupError = $exception;
                }
            }
        }

        if ($cleanupError) {
            Log::critical('Temporary router onboarding script could not be removed.', [
                'router_id' => $router->id,
                'exception' => $cleanupError::class,
            ]);
            throw new RouterTransportException('Router provisioning ran but its temporary script could not be removed. Inspect the router before retrying.');
        }

        if ($runError) {
            Log::warning('Router onboarding script execution failed.', [
                'router_id' => $router->id,
                'exception' => $runError::class,
            ]);
            throw new RouterTransportException('Router provisioning failed. Check RouterOS logs before retrying.');
        }
    }

    public function liveStatus(Router $router): array
    {
        $status = [
            'ok' => false,
            'message' => 'Live router metrics are unavailable.',
            'cpu_load' => 0,
            'memory_usage' => 0,
            'uptime' => 'N/A',
            'active_users' => 0,
        ];

        $connection = $this->test($router);
        if (! $connection['ok'] || ! is_array($connection['resource'] ?? null)) {
            $status['message'] = $connection['message'];

            return $status;
        }

        $resource = $connection['resource'];
        $totalMemory = is_numeric($resource['total-memory'] ?? null)
            ? (float) $resource['total-memory']
            : 0;
        $freeMemory = is_numeric($resource['free-memory'] ?? null)
            ? (float) $resource['free-memory']
            : 0;
        $memoryUsage = $totalMemory > 0
            ? round((1 - ($freeMemory / $totalMemory)) * 100)
            : 0;

        $status = [
            'ok' => true,
            'message' => $connection['message'],
            'cpu_load' => is_numeric($resource['cpu-load'] ?? null)
                ? (int) $resource['cpu-load']
                : 0,
            'memory_usage' => (int) min(100, max(0, $memoryUsage)),
            'uptime' => filled($resource['uptime'] ?? null) ? $resource['uptime'] : 'N/A',
            'active_users' => null,
        ];

        try {
            $router->loadMissing('vpnPeer');
            $peer = $router->vpnPeer;
            if (! $peer?->enabled) {
                return $status;
            }
            $key = $this->configureRouter($router, $peer->tunnel_ip, $peer);
            $status['active_users'] = count(MikroTik::router($key)->hotspot()->getActiveHosts());
        } catch (\Throwable $exception) {
            Log::warning('Unable to fetch active hotspot users from MikroTik router', [
                'router_id' => $router->getKey(),
                'exception' => $exception::class,
            ]);
        }

        return $status;
    }

    public function disconnectUser(Router $router, string $username, string $service = 'hotspot'): bool
    {
        if (! in_array($service, ['hotspot', 'pppoe'], true)
            || ! preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $username)) {
            throw new RouterTransportException('The requested RouterOS session target is invalid.');
        }

        $router->loadMissing('vpnPeer');
        $peer = $router->vpnPeer;
        $host = $this->hostForRouter($router);

        if (blank($host) || ! $peer?->enabled || blank($router->api_username) || blank($router->api_password)) {
            throw new RuntimeException("Router {$router->getKey()} requires an enabled WireGuard peer and API credentials.");
        }

        $key = $this->configureRouter($router, $host, $peer);

        try {
            if ($service === 'hotspot') {
                $hotspot = MikroTik::router($key)->hotspot();
                $matchingSessions = collect($hotspot->getActiveHosts())
                    ->filter(fn (array $host): bool => ($host['user'] ?? null) === $username)
                    ->count();
                for ($index = 0; $index < $matchingSessions; $index++) {
                    $hotspot->kickHost($username);
                }

                return $matchingSessions > 0;
            }

            $pppoe = MikroTik::router($key)->pppoe();
            $matchingSessions = collect($pppoe->getActiveSessions())
                ->filter(fn (array $session): bool => ($session['name'] ?? null) === $username)
                ->count();
            for ($index = 0; $index < $matchingSessions; $index++) {
                $pppoe->kickSession($username);
            }

            return $matchingSessions > 0;
        } catch (\Throwable $exception) {
            // Client exceptions may contain credentials; do not retain them as a cause.
            throw new RouterTransportException('Unable to disconnect the router session.');
        }
    }

    private function configureRouter(Router $router, ?string $host = null, ?VpnPeer $wireGuardPeer = null): string
    {
        $host ??= $this->hostForRouter($router);
        if (blank($host)) {
            throw new RuntimeException("Router {$router->getKey()} has no permitted connection address.");
        }

        $key = 'lan-demo-router-'.$router->getKey();
        DynamicMikrotikRouterConfig::set($key, [
            'username' => $router->api_username,
            'password' => $router->api_password,
            ...RouterTransportConfig::forVerifiedWireGuardPeer(
                $host,
                (int) ($router->api_port ?: 8729),
                $wireGuardPeer ?? throw new RouterTransportException('Router API access requires an enabled WireGuard peer.'),
                $router->getKey(),
                true,
            ),
            'timeout' => 5,
        ]);

        return $key;
    }

    /**
     * @param  array<int, string>  $response
     * @return array<int, array<string, string>>
     */
    private function rawRows(array $response): array
    {
        $rows = [];
        $row = [];

        foreach ($response as $word) {
            if (str_starts_with($word, '!trap') || str_starts_with($word, '!fatal')) {
                throw new RuntimeException('Router rejected a read-only configuration query.');
            }
            if ($word === '!re') {
                if ($row !== []) {
                    $rows[] = $row;
                    $row = [];
                }

                continue;
            }
            if ($word === '' || $word === '!done') {
                if ($row !== []) {
                    $rows[] = $row;
                    $row = [];
                }

                continue;
            }
            if (str_starts_with($word, '=')) {
                [$key, $value] = array_pad(explode('=', substr($word, 1), 2), 2, '');
                $row[$key] = $value;
            }
        }

        return $rows;
    }

    private function hostForRouter(Router $router): ?string
    {
        $router->loadMissing('vpnPeer');

        return $router->vpnPeer?->enabled
            ? $router->vpnPeer->tunnel_ip
            : null;
    }
}
