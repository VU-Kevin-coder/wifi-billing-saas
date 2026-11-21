<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Router;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;

class RouterProvisioningService
{
    private const UNGUIDED_PROVISIONING_BLOCKED = true;

    public const SAFETY_BLOCK = 'Unreviewed script downloads and legacy provisioning are disabled. Use guided provisioning after live identity and configuration checks pass.';

    /**
     * Escape a string value for safe insertion into RouterOS .rsc scripts.
     * Escapes backslashes, double quotes, and dollar signs (variable expansion).
     */
    public function escapeRouterOs(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], '', $value);

        return str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);
    }

    /**
     * Alias for generateScript.
     */
    public function generateProvisioningScript(Router $router): string
    {
        return $this->generateScript($router);
    }

    /**
     * Generate the complete RouterOS (.rsc) configuration script for a router.
     */
    public function generateScript(Router $router): string
    {
        if (self::UNGUIDED_PROVISIONING_BLOCKED) {
            throw new \LogicException(self::SAFETY_BLOCK);
        }

        return $this->buildScript($router, null);
    }

    /**
     * Generate a one-time RADIUS-only RouterOS script for an explicitly
     * enabled local CHR lab. This does not enable direct API deployment.
     */
    public function generateRadiusLabScript(Router $router, string $sharedSecret): string
    {
        if (! config('router.lab_provisioning_enabled')
            || ! app()->environment('local', 'testing')
            || ! config('wireguard.enabled')
            || ! RadiusReadiness::available()
            || $router->hardware_model !== 'CHR'
            || $router->access_backend !== 'radius'
            || ! $router->hasUsableRadiusConfiguration()
            || ! preg_match('/^[a-f0-9]{64,}$/i', $sharedSecret)
            || ! hash_equals((string) $router->radius_shared_secret, $sharedSecret)) {
            throw new \LogicException('The one-time RADIUS script is available only for a configured local CHR lab router.');
        }

        $router->loadMissing('vpnPeer');
        if (! $router->vpnPeer || ! $router->vpnPeer->enabled) {
            throw new \LogicException('An enabled WireGuard peer is required before generating the CHR RADIUS script.');
        }

        $serverAddress = (string) config('wireguard.server_address');
        if (filter_var($serverAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || filter_var($router->vpnPeer->tunnel_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \InvalidArgumentException('Valid WireGuard IPv4 addresses are required for the RADIUS script.');
        }

        $nasIdentifier = $this->escapeRouterOs((string) $router->radius_nas_identifier);
        $secret = $this->escapeRouterOs($sharedSecret);
        $server = $this->escapeRouterOs($serverAddress);
        $source = $this->escapeRouterOs((string) $router->vpnPeer->tunnel_ip);

        return implode("\r\n", [
            '# One-time RADIUS-only configuration for the local CHR lab.',
            '# The listener is reachable only through the assigned WireGuard tunnel.',
            '# Review the existing HotSpot profile before importing this script.',
            "/system identity set name=\"{$nasIdentifier}\"",
            ':if ([:len [/ip hotspot profile find name=prof-billing]] = 0) do={ :error "Required HotSpot profile prof-billing does not exist." }',
            ':if ([:len [/radius find address="'.$server.'" and src-address="'.$source.'"]] > 0) do={ /radius set [find address="'.$server.'" and src-address="'.$source.'"] comment="WiFiBillingRADIUS" }',
            ':if ([:len [/radius find comment="WiFiBillingRADIUS"]] = 0) do={ /radius add address="'.$server.'" src-address="'.$source.'" secret="'.$secret.'" service=hotspot authentication-port=1812 accounting-port=1813 timeout=1s }',
            ':if ([:len [/radius find address="'.$server.'" and src-address="'.$source.'"]] > 0) do={ /radius set [find address="'.$server.'" and src-address="'.$source.'"] comment="WiFiBillingRADIUS" }',
            ':if ([:len [/radius find comment="WiFiBillingRADIUS"]] > 0) do={',
            '  /radius set [find comment="WiFiBillingRADIUS"] address="'.$server.'" src-address="'.$source.'" secret="'.$secret.'" service=hotspot authentication-port=1812 accounting-port=1813 timeout=1s',
            '}',
            '/ip hotspot profile set [find name=prof-billing] use-radius=yes radius-accounting=yes',
            '/ip hotspot profile set [find name=prof-billing] radius-interim-update=00:01:00',
            '',
        ]);
    }

    public function deployGuided(Router $router, bool $confirmedDedicatedRouter): array
    {
        if (! $confirmedDedicatedRouter) {
            return ['ok' => false, 'message' => 'Confirm that this is a fresh, dedicated router before provisioning.'];
        }

        $router->loadMissing(['vpnPeer', 'onboarding']);
        if (! $router->vpnPeer?->enabled
            || ! in_array($router->onboarding?->status, ['radius_ready', 'complete'], true)) {
            return ['ok' => false, 'message' => 'Verify the router connection through WireGuard before provisioning services.'];
        }
        if ((int) $router->api_port !== 8729) {
            return ['ok' => false, 'message' => 'Guided provisioning requires the verified native API-SSL connection on port 8729.'];
        }

        if ($router->access_backend !== 'radius' && ! $router->pending_radius_activation) {
            return ['ok' => false, 'message' => 'Guided router provisioning requires a pending or active RADIUS configuration.'];
        }
        if ($router->pending_radius_activation && $router->radius_readiness_status !== 'ready') {
            return ['ok' => false, 'message' => 'RADIUS readiness must pass before enabling router services. The existing access backend remains active.'];
        }

        if ($router->enable_pppoe && stripos((string) $router->hardware_model, 'chr') !== false) {
            return ['ok' => false, 'message' => 'PPPoE provisioning is not available for CHR lab routers.'];
        }

        if ($router->access_backend === 'radius' || $router->pending_radius_activation) {
            if (! RadiusReadiness::operational() || ! $router->hasUsableRadiusConfiguration()) {
                return ['ok' => false, 'message' => RadiusRouterCredentialService::UNAVAILABLE_MESSAGE];
            }
        }
        if (! $router->enable_hotspot && ! $router->enable_pppoe) {
            return ['ok' => false, 'message' => 'Enable at least one supported router service before provisioning.'];
        }

        try {
            $preflight = $this->preflightGuidedProvisioning($router);
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return ['ok' => false, 'message' => 'The selected router service configuration is invalid: '.$exception->getMessage()];
        }
        if (! $preflight['ok']) {
            return $preflight;
        }

        $backupName = null;
        try {
            $radiusSecret = $router->radius_shared_secret;
            $script = $this->buildScript($router, $router->enable_hotspot ? $radiusSecret : null);
            if ($router->enable_pppoe) {
                $script .= $this->buildPppoeScript($router, $radiusSecret);
            }
            $connection = app(RouterConnectionService::class);
            $backupName = $connection->createProvisioningBackup($router);
            $connection->executeOnboardingScript($router, $script);
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return [
                'ok' => false,
                'message' => $exception->getMessage().($backupName
                    ? " Pre-change backup: {$backupName}; inspect the router before retrying."
                    : ''),
                'backup_name' => $backupName,
            ];
        } catch (RouterTransportException $exception) {
            return [
                'ok' => false,
                'message' => $exception->getMessage().($backupName
                    ? " Pre-change backup: {$backupName}; inspect the router before retrying."
                    : ''),
                'backup_name' => $backupName,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Router service configuration was applied successfully. Keep the verified backup '.($backupName ?? '').' until service acceptance is complete.',
            'backup_name' => $backupName,
        ];
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    private function preflightGuidedProvisioning(Router $router): array
    {
        $config = $router->hotspot_config ?? [];
        $wan = $config['wan_interface'] ?? null;
        $management = $config['management_interface'] ?? null;
        $clients = $router->enable_hotspot ? array_values((array) $router->ethernet_ports) : [];
        $pppoe = $router->enable_pppoe ? ($router->pppoe_config['interface'] ?? null) : null;
        $roles = array_merge([$wan, $management], $clients, array_filter([$pppoe]));

        if (! is_string($wan) || ! is_string($management)
            || $wan === $management
            || count($roles) !== count(array_unique($roles))) {
            return ['ok' => false, 'message' => 'Choose distinct WAN, management, HotSpot, and PPPoE interfaces before provisioning.'];
        }
        if ($router->enable_hotspot && $clients === []) {
            return ['ok' => false, 'message' => 'Select at least one dedicated HotSpot client interface.'];
        }
        if ($router->enable_hotspot && $router->enable_pppoe
            && $this->networksOverlap(
                (string) ($config['network'] ?? ''),
                (string) ($router->pppoe_config['network'] ?? ''),
            )) {
            return ['ok' => false, 'message' => 'The HotSpot and PPPoE networks overlap. No changes were made.'];
        }

        $state = app(RouterConnectionService::class)->inspectProvisioningTarget($router);
        if (! ($state['ok'] ?? false)) {
            return ['ok' => false, 'message' => $state['message'] ?? 'Router preflight failed. No changes were made.'];
        }

        $version = (string) ($state['resource']['version'] ?? '');
        if (! preg_match('/^7\./', $version)) {
            return ['ok' => false, 'message' => 'Guided provisioning supports verified RouterOS 7.x devices only. No changes were made.'];
        }

        foreach ($state['all_interfaces'] ?? [] as $interface) {
            $type = strtolower((string) ($interface['type'] ?? ''));
            $name = strtolower((string) ($interface['name'] ?? ''));
            $isWireless = in_array($type, ['wlan', 'wifi'], true)
                || preg_match('/^(wlan|wifi)\d+$/', $name) === 1;
            $isDisabled = in_array(strtolower((string) ($interface['disabled'] ?? 'false')), ['yes', 'true'], true);
            if ($isWireless && ! $isDisabled) {
                return [
                    'ok' => false,
                    'message' => 'Active built-in Wi-Fi radios are not supported by this reviewed setup. Disable them and use a separate access point on a selected HotSpot client port.',
                ];
            }
        }

        $ethernet = collect($state['interfaces'] ?? [])->keyBy('name');
        foreach ([$wan, $management, ...$clients, ...array_filter([$pppoe])] as $interface) {
            if (! preg_match('/^ether\d+$/', (string) $interface) || ! $ethernet->has($interface)) {
                return ['ok' => false, 'message' => "Selected interface {$interface} is not a detected Ethernet port. No changes were made."];
            }
        }
        if (($ethernet->get($wan)['running'] ?? 'false') !== 'true'
            || ($ethernet->get($management)['running'] ?? 'false') !== 'true') {
            return ['ok' => false, 'message' => 'The selected WAN and recovery-management ports must both have an active link. No changes were made.'];
        }

        $activeDefaultRoute = collect($state['default_routes'] ?? [])->contains(
            fn (array $route): bool => ($route['dst-address'] ?? null) === '0.0.0.0/0'
                && in_array(strtolower((string) ($route['active'] ?? 'false')), ['yes', 'true'], true)
                && ! in_array(strtolower((string) ($route['disabled'] ?? 'false')), ['yes', 'true'], true)
                && (str_contains((string) ($route['immediate-gw'] ?? ''), '%'.$wan)
                    || ($route['gateway'] ?? null) === $wan),
        );
        if (! $activeDefaultRoute) {
            return ['ok' => false, 'message' => 'The selected WAN interface has no verified active default route. Confirm its Internet routing before provisioning.'];
        }

        $wanLists = collect($state['interface_list_members'] ?? [])
            ->filter(fn (array $member): bool => ($member['interface'] ?? null) === $wan
                && ! in_array(strtolower((string) ($member['disabled'] ?? 'false')), ['yes', 'true'], true))
            ->pluck('list')
            ->all();
        $wanMasquerade = collect($state['nat_rules'] ?? [])->contains(
            fn (array $rule): bool => ($rule['chain'] ?? null) === 'srcnat'
                && ($rule['action'] ?? null) === 'masquerade'
                && ! in_array(strtolower((string) ($rule['disabled'] ?? 'false')), ['yes', 'true'], true)
                && (($rule['out-interface'] ?? null) === $wan
                    || in_array($rule['out-interface-list'] ?? null, $wanLists, true)),
        );
        if (! $wanMasquerade) {
            return ['ok' => false, 'message' => 'No enabled WAN masquerade rule was verified for the selected Internet port. Configure and verify NAT before provisioning.'];
        }

        $bridges = collect($state['bridges'] ?? [])->keyBy('name');
        foreach ($state['bridge_ports'] ?? [] as $bridgePort) {
            $interface = $bridgePort['interface'] ?? null;
            if ($interface === $wan) {
                return ['ok' => false, 'message' => 'The selected WAN port is already bridged. Resolve the WAN bridge before provisioning.'];
            }
            if ($interface === $pppoe) {
                return ['ok' => false, 'message' => 'The PPPoE interface is already a bridge member. Remove that membership or choose a separate port before provisioning.'];
            }
            if (! in_array($interface, $clients, true)) {
                continue;
            }

            $bridge = $bridges->get($bridgePort['bridge'] ?? '');
            if (($bridge['name'] ?? null) === 'br-hotspot'
                && ($bridge['comment'] ?? null) === 'Wi-Fi Billing Client Bridge'
                && ($bridgePort['comment'] ?? null) === 'Client access port') {
                continue;
            }
            if (($bridge['comment'] ?? null) !== 'defconf'
                || ($bridgePort['comment'] ?? null) !== 'defconf') {
                return [
                    'ok' => false,
                    'message' => "Client port {$interface} belongs to a non-default bridge. Review and remove that membership manually before retrying.",
                ];
            }
        }

        $managedNames = [
            'bridge' => ['br-hotspot', 'Wi-Fi Billing Client Bridge'],
            'pool' => ['pool-hotspot', 'Wi-Fi Billing HotSpot Pool'],
            'dhcp' => ['dhcp-hotspot', 'Wi-Fi Billing HotSpot DHCP'],
            'hotspot' => ['hs-billing', 'Wi-Fi Billing HotSpot Server'],
            'ppp_pool' => ['pool-pppoe', 'Wi-Fi Billing PPPoE Pool'],
            'ppp_profile' => ['pppoe-billing', 'Wi-Fi Billing PPPoE Profile'],
        ];
        foreach ([
            'bridges' => $managedNames['bridge'],
            'pools' => $managedNames['pool'],
            'dhcp_servers' => $managedNames['dhcp'],
            'hotspot_servers' => $managedNames['hotspot'],
        ] as $table => [$name, $comment]) {
            if ($table === 'pools' && ! $router->enable_hotspot) {
                continue;
            }
            if ($this->hasUnmanagedNamedResource($state[$table] ?? [], $name, $comment)) {
                return ['ok' => false, 'message' => "Router resource {$name} already exists and is not owned by Wi-Fi Billing. No changes were made."];
            }
        }
        if ($router->enable_pppoe) {
            foreach ([
                ['ppp_profiles', $managedNames['ppp_profile']],
                ['pools', $managedNames['ppp_pool']],
            ] as [$table, [$name, $comment]]) {
                if ($this->hasUnmanagedNamedResource($state[$table] ?? [], $name, $comment)) {
                    return ['ok' => false, 'message' => "Router resource {$name} already exists and is not owned by Wi-Fi Billing. No changes were made."];
                }
            }

            if (isset($router->pppoe_config['vlan_id'])) {
                $vlanName = 'pppoe-vlan-'.$router->id;
                if ($this->hasUnmanagedNamedResource($state['vlan_interfaces'] ?? [], $vlanName, 'Wi-Fi Billing PPPoE VLAN')) {
                    return ['ok' => false, 'message' => "Router VLAN {$vlanName} already exists and is not owned by Wi-Fi Billing. No changes were made."];
                }
                $vlanConflict = collect($state['vlan_interfaces'] ?? [])->first(
                    fn (array $vlan): bool => ($vlan['interface'] ?? null) === $pppoe
                        && (string) ($vlan['vlan-id'] ?? '') === (string) $router->pppoe_config['vlan_id']
                        && ($vlan['name'] ?? null) !== $vlanName,
                );
                if ($vlanConflict) {
                    return ['ok' => false, 'message' => 'The selected PPPoE VLAN ID is already in use on this interface. Choose another VLAN or resolve the existing interface.'];
                }
            }
        }

        $hasManagedHotspotServer = collect($state['hotspot_servers'] ?? [])
            ->contains(fn (array $server): bool => ($server['name'] ?? null) === 'hs-billing'
                && ($server['profile'] ?? null) === 'prof-billing'
                && ($server['comment'] ?? null) === $managedNames['hotspot'][1]);
        $unmanagedBillingProfile = collect($state['hotspot_profiles'] ?? [])
            ->filter(fn (array $profile): bool => ($profile['name'] ?? null) === 'prof-billing')
            ->contains(function (array $profile) use ($hasManagedHotspotServer): bool {
                $hasBootstrapSignature = in_array(strtolower((string) ($profile['use-radius'] ?? '')), ['yes', 'true'], true)
                    && in_array(strtolower((string) ($profile['radius-accounting'] ?? '')), ['yes', 'true'], true)
                    && in_array($profile['radius-interim-update'] ?? null, ['00:01:00', '1m'], true);
                $hasLegacyManagedComment = in_array($profile['comment'] ?? null, [
                    'WiFiBillingManagedProfile',
                    'Wi-Fi Billing managed profile',
                    'Wi-Fi Billing HotSpot Profile',
                ], true);

                return ! $hasBootstrapSignature && ! $hasLegacyManagedComment && ! $hasManagedHotspotServer;
            });
        if ($unmanagedBillingProfile) {
            return ['ok' => false, 'message' => 'The prof-billing HotSpot profile is not marked as app-managed. No changes were made.'];
        }

        $serviceNetworks = [];
        $hotspotGatewayAddress = null;
        if ($router->enable_hotspot) {
            $hotspotNetwork = (string) ($config['network'] ?? '');
            $serviceNetworks[] = $hotspotNetwork;
            [$hotspotPrefix] = $this->hotspotNetworkDetails(
                $hotspotNetwork,
                (string) ($config['gateway'] ?? ''),
            );
            $hotspotGatewayAddress = (string) ($config['gateway'] ?? '').'/'.$hotspotPrefix;
        }
        if ($router->enable_pppoe) {
            $pppoeNetwork = (string) ($router->pppoe_config['network'] ?? '');
            $serviceNetworks[] = $pppoeNetwork;
        }
        foreach ($serviceNetworks as $network) {
            foreach (array_merge($state['addresses'] ?? [], $state['dhcp_networks'] ?? []) as $existingNetwork) {
                $address = $existingNetwork['address'] ?? null;
                if (! is_string($address) || ! str_contains($address, '/')) {
                    continue;
                }
                if ($router->enable_hotspot
                    && $address === $hotspotGatewayAddress
                    && ($existingNetwork['comment'] ?? null) === 'Wi-Fi Billing HotSpot Gateway'
                    && ($existingNetwork['interface'] ?? null) === 'br-hotspot') {
                    continue;
                }
                if ($router->enable_hotspot
                    && $address === $config['network']
                    && ($existingNetwork['comment'] ?? null) === 'Wi-Fi Billing HotSpot Network') {
                    continue;
                }
                try {
                    if ($this->networksOverlap($network, $address)) {
                        return [
                            'ok' => false,
                            'message' => "Service network {$network} overlaps existing router network {$address}. No changes were made.",
                        ];
                    }
                } catch (\InvalidArgumentException) {
                    return ['ok' => false, 'message' => 'The router returned an invalid network during preflight. No changes were made.'];
                }
            }

        }

        $desiredPools = [];
        if ($router->enable_hotspot) {
            [, $start, $end] = $this->hotspotNetworkDetails(
                (string) ($config['network'] ?? ''),
                (string) ($config['gateway'] ?? ''),
            );
            $desiredPools[] = [$start, $end];
        }
        if ($router->enable_pppoe) {
            [, $start, $end] = $this->networkAddressDetails(
                (string) ($router->pppoe_config['network'] ?? ''),
                (string) ($router->pppoe_config['gateway'] ?? ''),
                'PPPoE network',
            );
            $desiredPools[] = [$start, $end];
        }
        foreach ($state['pools'] ?? [] as $pool) {
            if (in_array($pool['name'] ?? null, ['pool-hotspot', 'pool-pppoe'], true)) {
                continue;
            }
            $ranges = $this->parsePoolRanges((string) ($pool['ranges'] ?? ''));
            if ($ranges === null) {
                return ['ok' => false, 'message' => 'An existing IP pool has an unsupported range format. Resolve it before provisioning.'];
            }
            foreach ($ranges as [$rangeStart, $rangeEnd]) {
                foreach ($desiredPools as [$desiredStart, $desiredEnd]) {
                    if ($this->ipv4RangesOverlap($rangeStart, $rangeEnd, $desiredStart, $desiredEnd)) {
                        return [
                            'ok' => false,
                            'message' => 'A service address pool overlaps an existing router IP pool. No changes were made.',
                        ];
                    }
                }
            }
        }

        if ($router->enable_hotspot) {
            foreach ($state['hotspot_servers'] ?? [] as $server) {
                if (($server['comment'] ?? null) !== $managedNames['hotspot'][1]) {
                    return ['ok' => false, 'message' => 'An unmanaged HotSpot server exists. Resolve it before provisioning another HotSpot service.'];
                }
            }
            foreach ($state['dhcp_servers'] ?? [] as $server) {
                if ((($server['interface'] ?? null) === 'br-hotspot'
                        || in_array($server['interface'] ?? null, $clients, true))
                    && ($server['comment'] ?? null) !== $managedNames['dhcp'][1]) {
                    return ['ok' => false, 'message' => 'An unmanaged DHCP server already uses br-hotspot. No changes were made.'];
                }
            }
        }

        if ($router->enable_pppoe) {
            if (($state['ppp_active_count'] ?? 0) > 0 || ($state['ppp_secret_count'] ?? 0) > 0) {
                return ['ok' => false, 'message' => 'Existing PPP sessions or local PPP accounts were found. PPPoE provisioning is refused to protect them.'];
            }
            $expectedPppoeInterface = isset($router->pppoe_config['vlan_id'])
                ? 'pppoe-vlan-'.$router->id
                : $pppoe;
            foreach ($state['pppoe_servers'] ?? [] as $server) {
                if (($server['comment'] ?? null) !== 'Wi-Fi Billing PPPoE Server'
                    || ($server['interface'] ?? null) !== $expectedPppoeInterface) {
                    return ['ok' => false, 'message' => 'An unmanaged PPPoE server was found. PPP RADIUS settings are global, so this router is not safe to provision automatically.'];
                }
            }
        }

        $radiusClients = $state['radius_clients'] ?? [];
        if (count(array_filter(
            $radiusClients,
            fn (array $client): bool => in_array($client['comment'] ?? null, ['WiFiBillingRADIUS', 'Wi-Fi Billing RADIUS'], true),
        )) > 1) {
            return ['ok' => false, 'message' => 'Multiple Wi-Fi Billing RADIUS clients exist. Resolve the duplicate before provisioning.'];
        }
        foreach ($radiusClients as $radiusClient) {
            if (! in_array($radiusClient['comment'] ?? null, ['WiFiBillingRADIUS', 'Wi-Fi Billing RADIUS'], true)) {
                return ['ok' => false, 'message' => 'An unmanaged RADIUS client is configured. Review it before changing router authentication settings.'];
            }
        }

        return ['ok' => true];
    }

    private function hasUnmanagedNamedResource(array $resources, string $name, string $comment): bool
    {
        return collect($resources)->contains(
            fn (array $resource): bool => ($resource['name'] ?? null) === $name
                && ($resource['comment'] ?? null) !== $comment,
        );
    }

    /**
     * @return array<int, array{0: string, 1: string}>|null
     */
    private function parsePoolRanges(string $ranges): ?array
    {
        if (trim($ranges) === '') {
            return null;
        }

        $parsed = [];
        foreach (explode(',', $ranges) as $range) {
            $parts = explode('-', trim($range), 2);
            if (count($parts) === 1) {
                $parts[] = $parts[0];
            }
            foreach ($parts as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    return null;
                }
            }

            if ((int) sprintf('%u', ip2long($parts[0])) > (int) sprintf('%u', ip2long($parts[1]))) {
                return null;
            }
            $parsed[] = [$parts[0], $parts[1]];
        }

        return $parsed;
    }

    private function ipv4RangesOverlap(string $firstStart, string $firstEnd, string $secondStart, string $secondEnd): bool
    {
        $firstStartInt = (int) sprintf('%u', ip2long($firstStart));
        $firstEndInt = (int) sprintf('%u', ip2long($firstEnd));
        $secondStartInt = (int) sprintf('%u', ip2long($secondStart));
        $secondEndInt = (int) sprintf('%u', ip2long($secondEnd));

        return $firstStartInt <= $secondEndInt && $secondStartInt <= $firstEndInt;
    }

    private function buildScript(Router $router, ?string $radiusSecret): string
    {
        $tenant = $router->tenant ?? Tenant::find($router->tenant_id);
        $hotspotConfig = $router->hotspot_config ?? [];

        $routerName = $router->name ?: 'MikroTik-Router';
        if (! $router->enable_hotspot) {
            $identity = $router->access_backend === 'radius'
                ? (string) $router->radius_nas_identifier
                : $routerName;

            return '/system identity set name="'.$this->escapeRouterOs($identity)."\"\r\n";
        }

        $escapedRouterName = $this->escapeRouterOs($routerName);
        $gatewayIp = (string) ($hotspotConfig['gateway'] ?? config('services.mikrotik.gateway'));
        $network = (string) ($hotspotConfig['network'] ?? PlatformSetting::get('hotspot.default_network', config('services.mikrotik.hotspot_network')));
        $domain = (string) ($hotspotConfig['domain'] ?? config('services.mikrotik.hotspot_domain'));
        $defaultDnsServers = implode(',', (array) PlatformSetting::get(
            'hotspot.default_dns',
            explode(',', (string) config('services.mikrotik.dns_servers')),
        ));
        $dnsServers = (string) ($hotspotConfig['dns'] ?? $defaultDnsServers);
        $wanInterface = (string) ($hotspotConfig['wan_interface'] ?? PlatformSetting::get('hotspot.wan_interface', 'ether1'));
        [$prefix, $poolStart, $poolEnd] = $this->hotspotNetworkDetails($network, $gatewayIp);
        $this->validateDnsServers($dnsServers);
        $this->validateHostname($domain, 'Hotspot domain');
        $ports = $router->ethernet_ports ?? config('services.mikrotik.client_ports');
        if (empty($ports)) {
            $ports = config('services.mikrotik.client_ports');
        }
        foreach ($ports as $port) {
            if (! is_string($port) || ! preg_match('/^ether\d+$/', $port)) {
                throw new \InvalidArgumentException('Client access ports must be detected RouterOS Ethernet interfaces.');
            }
        }
        $ports = array_values(array_filter($ports, fn (string $port): bool => $port !== $wanInterface));

        $escapedDomain = $this->escapeRouterOs($domain);
        $escapedDnsServers = $this->escapeRouterOs($dnsServers);

        $baseDomain = config('tenancy.base_domain', 'localhost');
        $this->validateHostname((string) $baseDomain, 'Tenant base domain');
        $escapedBaseDomain = $this->escapeRouterOs((string) $baseDomain);
        $idleTimeout = max(1, (int) PlatformSetting::get('hotspot.idle_timeout', 2));
        $tenantName = preg_replace('/[\r\n\x00-\x1F]/', ' ', $tenant?->name ?? 'Default');
        $gatewayWithPrefix = "{$gatewayIp}/{$prefix}";

        $packages = $tenant ? Package::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get() : collect();

        $lines = [];
        $radiusEnabled = $radiusSecret !== null;
        if ($radiusEnabled) {
            $router->loadMissing('vpnPeer');
            if (! $router->vpnPeer?->enabled || ! $router->hasUsableRadiusConfiguration()) {
                throw new \LogicException('A configured RADIUS credential and enabled WireGuard peer are required.');
            }
        }

        $lines[] = '# =================================================================';
        $lines[] = '# Wi-Fi Billing SaaS - Automated MikroTik Provisioning Script';
        $lines[] = "# Router: {$escapedRouterName} (ID: {$router->id})";
        $lines[] = '# Tenant: '.$tenantName;
        $lines[] = '# Generated: '.now()->toIso8601String();
        $lines[] = '#';
        $lines[] = '# BEFORE RUNNING:';
        $lines[] = '# 1. A verified router-local backup is created automatically before guided provisioning.';
        $lines[] = '# 2. Intended only for a dedicated router after live identity/configuration preflight.';
        $lines[] = '# 3. Selected WAN and recovery-management interfaces are never assigned to the client bridge.';
        $lines[] = '# WAN interface '.$this->escapeRouterOs($wanInterface).' is kept outside the client bridge.';
        $lines[] = '#';
        $lines[] = '# IF THE IMPORT FAILS PARTWAY: inspect /log print; do not blindly rerun on a live network.';
        $lines[] = '# Restore only after operator review; loading a backup may reboot/disconnect the router.';
        $lines[] = '# =================================================================';
        $lines[] = '';

        // Clients use upstream DNS directly, so the router need not be a remote resolver.
        $routerIdentity = $radiusEnabled ? (string) $router->radius_nas_identifier : $routerName;
        $lines[] = '/system identity set name="'.$this->escapeRouterOs($routerIdentity).'"';
        $lines[] = '';

        // 2. Client bridge configuration. Review existing membership before running.
        $lines[] = ':if ([:len [/interface bridge find name=br-hotspot]] = 0) do={ /interface bridge add name=br-hotspot disabled=no }';
        $lines[] = '/interface bridge set [find name=br-hotspot] comment="Wi-Fi Billing Client Bridge"';
        foreach ($ports as $port) {
            $lines[] = ":if ([:len [/interface bridge port find interface=\"{$port}\"]] = 0) do={ /interface bridge port add bridge=br-hotspot interface=\"{$port}\" }";
            $lines[] = ":if ([:len [/interface bridge port find interface=\"{$port}\"]] > 0) do={ /interface bridge port set [find interface=\"{$port}\"] bridge=br-hotspot }";
            $lines[] = ":if ([:len [/interface bridge port find interface=\"{$port}\"]] > 0) do={ /interface bridge port set [find interface=\"{$port}\"] comment=\"Client access port\" }";
        }
        $lines[] = '';

        // 3. IP Pool and Addressing (Idempotent + configured network prefix)
        $lines[] = ":if ([:len [/ip pool find name=pool-hotspot]] = 0) do={ /ip pool add name=pool-hotspot ranges={$poolStart}-{$poolEnd} }";
        $lines[] = ":if ([:len [/ip pool find name=pool-hotspot]] > 0) do={ /ip pool set [find name=pool-hotspot] ranges={$poolStart}-{$poolEnd} }";
        $lines[] = ':if ([:len [/ip pool find name=pool-hotspot]] > 0) do={ /ip pool set [find name=pool-hotspot] comment="Wi-Fi Billing HotSpot Pool" }';
        $lines[] = ":if ([:len [/ip address find interface=br-hotspot and address=\"{$gatewayWithPrefix}\"]] = 0) do={ /ip address add address={$gatewayWithPrefix} interface=br-hotspot }";
        $lines[] = ":if ([:len [/ip address find interface=br-hotspot and address=\"{$gatewayWithPrefix}\"]] > 0) do={ /ip address set [find interface=br-hotspot and address=\"{$gatewayWithPrefix}\"] comment=\"Wi-Fi Billing HotSpot Gateway\" }";
        $lines[] = ':if ([:len [/ip dhcp-server find name=dhcp-hotspot]] = 0) do={ /ip dhcp-server add address-pool=pool-hotspot disabled=no interface=br-hotspot lease-time=1h name=dhcp-hotspot }';
        $lines[] = ':if ([:len [/ip dhcp-server find name=dhcp-hotspot]] > 0) do={ /ip dhcp-server set [find name=dhcp-hotspot] address-pool=pool-hotspot interface=br-hotspot }';
        $lines[] = ':if ([:len [/ip dhcp-server find name=dhcp-hotspot]] > 0) do={ /ip dhcp-server set [find name=dhcp-hotspot] comment="Wi-Fi Billing HotSpot DHCP" }';
        $lines[] = ":if ([:len [/ip dhcp-server network find address=\"{$network}\"]] = 0) do={ /ip dhcp-server network add address=\"{$network}\" dns-server=\"{$escapedDnsServers}\" gateway={$gatewayIp} }";
        $lines[] = ":if ([:len [/ip dhcp-server network find address=\"{$network}\"]] > 0) do={ /ip dhcp-server network set [find address=\"{$network}\"] dns-server=\"{$escapedDnsServers}\" gateway={$gatewayIp} }";
        $lines[] = ":if ([:len [/ip dhcp-server network find address=\"{$network}\"]] > 0) do={ /ip dhcp-server network set [find address=\"{$network}\"] comment=\"Wi-Fi Billing HotSpot Network\" }";
        $lines[] = '';

        // 4. Hotspot Profile and Server (Idempotent)
        $radiusMode = $radiusEnabled ? 'yes' : 'no';
        $radiusAccounting = $radiusEnabled ? 'yes' : 'no';
        $interimUpdate = $radiusEnabled ? '00:01:00' : '00:00:00';
        $lines[] = '/ip hotspot profile remove [find name=prof-billing]';
        $lines[] = '/ip hotspot profile add name=prof-billing';
        $lines[] = "/ip hotspot profile set [find name=prof-billing] dns-name=\"{$escapedDomain}\" hotspot-address={$gatewayIp} html-directory=hotspot login-by=http-chap";
        $lines[] = '/ip hotspot profile set [find name=prof-billing] use-radius='.$radiusMode.' radius-accounting='.$radiusAccounting;
        $lines[] = '/ip hotspot profile set [find name=prof-billing] radius-interim-update='.$interimUpdate;
        $lines[] = ':if ([:len [/ip hotspot find name=hs-billing]] = 0) do={ /ip hotspot add address-pool=pool-hotspot disabled=no interface=br-hotspot name=hs-billing profile=prof-billing }';
        $lines[] = ':if ([:len [/ip hotspot find name=hs-billing]] > 0) do={ /ip hotspot set [find name=hs-billing] address-pool=pool-hotspot interface=br-hotspot profile=prof-billing }';
        $lines[] = ':if ([:len [/ip hotspot find name=hs-billing]] > 0) do={ /ip hotspot set [find name=hs-billing] comment="Wi-Fi Billing HotSpot Server" }';
        $lines[] = '';

        if ($radiusEnabled) {
            $radiusAddress = $this->escapeRouterOs((string) config('wireguard.server_address'));
            $radiusSource = $this->escapeRouterOs((string) $router->vpnPeer->tunnel_ip);
            $escapedRadiusSecret = $this->escapeRouterOs((string) $radiusSecret);
            $lines[] = '# --- Private RADIUS server over WireGuard ---';
            $radiusServices = $router->enable_pppoe ? 'hotspot,ppp' : 'hotspot';
            $lines[] = ':if ([:len [/radius find address="'.$radiusAddress.'" and src-address="'.$radiusSource.'"]] > 0) do={ /radius set [find address="'.$radiusAddress.'" and src-address="'.$radiusSource.'"] comment="WiFiBillingRADIUS" }';
            $lines[] = ':if ([:len [/radius find comment="WiFiBillingRADIUS"]] = 0) do={ /radius add address="'.$radiusAddress.'" src-address="'.$radiusSource.'" secret="'.$escapedRadiusSecret.'" service='.$radiusServices.' authentication-port=1812 accounting-port=1813 timeout=1s }';
            $lines[] = ':if ([:len [/radius find address="'.$radiusAddress.'" and src-address="'.$radiusSource.'"]] > 0) do={ /radius set [find address="'.$radiusAddress.'" and src-address="'.$radiusSource.'"] comment="WiFiBillingRADIUS" }';
            $lines[] = ':if ([:len [/radius find comment="WiFiBillingRADIUS"]] > 0) do={';
            $lines[] = '  /radius set [find comment="WiFiBillingRADIUS"] address="'.$radiusAddress.'" src-address="'.$radiusSource.'" secret="'.$escapedRadiusSecret.'" service='.$radiusServices.' authentication-port=1812 accounting-port=1813 timeout=1s';
            $lines[] = '}';
            $lines[] = '';
        }

        // 5. Walled Garden (Captive portal and payment providers - Idempotent)
        $lines[] = '# --- Walled Garden Access ---';
        $configuredWalledGarden = (array) PlatformSetting::get('hotspot.walled_garden', [
            'obpay.online', 'pawapay.io', 'iotec.io',
        ]);
        $walledHosts = [
            "*{$escapedBaseDomain}*" => 'SaaS Platform Portal',
            '*google.com*' => 'Android Captive Portal Check',
            '*apple.com*' => 'Apple Captive Portal Check',
        ];
        foreach ($configuredWalledGarden as $host) {
            if (is_string($host) && $host !== '') {
                $walledHosts['*'.trim($host).'*'] = 'Configured platform walled garden';
            }
        }
        foreach ($walledHosts as $host => $comment) {
            $escapedHost = $this->escapeRouterOs($host);
            $escapedComment = $this->escapeRouterOs($comment);
            $lines[] = ":if ([:len [/ip hotspot walled-garden find dst-host=\"{$escapedHost}\"]] = 0) do={ /ip hotspot walled-garden add dst-host=\"{$escapedHost}\" action=allow }";
            $lines[] = ":if ([:len [/ip hotspot walled-garden find dst-host=\"{$escapedHost}\"]] > 0) do={ /ip hotspot walled-garden set [find dst-host=\"{$escapedHost}\"] comment=\"{$escapedComment}\" }";
        }
        $lines[] = ":if ([:len [/ip hotspot walled-garden ip find dst-address=\"{$gatewayIp}\"]] = 0) do={ /ip hotspot walled-garden ip add dst-address={$gatewayIp} action=accept }";
        $lines[] = ":if ([:len [/ip hotspot walled-garden ip find dst-address=\"{$gatewayIp}\"]] > 0) do={ /ip hotspot walled-garden ip set [find dst-address=\"{$gatewayIp}\"] comment=\"Local Gateway\" }";
        $lines[] = '';

        // API-SSL and its dedicated account are configured during verified bootstrap.

        // 7. Hotspot User Profiles for Tenant Packages (Idempotent)
        if ($radiusSecret === null && $packages->isNotEmpty()) {
            $lines[] = '# --- Bandwidth and Device Limit Profiles for Packages ---';
            foreach ($packages as $pkg) {
                $profile = $pkg->routerProfileSettings();
                $escapedPkgName = $this->escapeRouterOs($profile['name']);
                $escapedRateLimit = $this->escapeRouterOs($profile['rate-limit']);
                $settings = "rate-limit=\"{$escapedRateLimit}\" shared-users={$profile['shared-users']}";
                $lines[] = '# Voucher uptime and data caps are enforced by the RADIUS policy service.';
                $lines[] = ":if ([:len [/ip hotspot user profile find name=\"{$escapedPkgName}\"]] = 0) do={ /ip hotspot user profile add name=\"{$escapedPkgName}\" {$settings} keepalive-timeout={$idleTimeout}m status-autorefresh=1m }";
                $lines[] = ":if ([:len [/ip hotspot user profile find name=\"{$escapedPkgName}\"]] > 0) do={ /ip hotspot user profile set [find name=\"{$escapedPkgName}\"] {$settings} keepalive-timeout={$idleTimeout}m }";
            }
            $lines[] = '';
        }

        $lines[] = '# End of provisioning script';

        return implode("\r\n", $lines)."\r\n";
    }

    private function buildPppoeScript(Router $router, ?string $radiusSecret): string
    {
        if ($radiusSecret === null || ! $router->hasUsableRadiusConfiguration()) {
            throw new \LogicException('A configured RADIUS credential is required for PPPoE.');
        }

        $config = $router->pppoe_config ?? [];
        $interface = (string) ($config['interface'] ?? '');
        $wanInterface = (string) ($router->hotspot_config['wan_interface'] ?? PlatformSetting::get('hotspot.wan_interface', 'ether1'));
        $managementInterface = (string) ($router->hotspot_config['management_interface'] ?? '');
        $ports = (array) ($router->ethernet_ports ?? []);
        $vlanId = $config['vlan_id'] ?? null;
        if (! preg_match('/^ether\d+$/', $interface)
            || $interface === $wanInterface
            || $interface === $managementInterface
            || ($router->enable_hotspot && in_array($interface, $ports, true))) {
            throw new \InvalidArgumentException('PPPoE requires a separate, detected Ethernet interface outside WAN, management, and HotSpot client roles.');
        }
        if ($vlanId !== null && (! is_numeric($vlanId) || (int) $vlanId < 1 || (int) $vlanId > 4094)) {
            throw new \InvalidArgumentException('PPPoE VLAN ID must be between 1 and 4094.');
        }
        if ($vlanId !== null && filter_var($vlanId, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('PPPoE VLAN ID must be an integer between 1 and 4094.');
        }

        $network = (string) ($config['network'] ?? '');
        $gateway = (string) ($config['gateway'] ?? '');
        $dnsServers = (string) ($config['dns'] ?? '');
        [, $poolStart, $poolEnd] = $this->networkAddressDetails($network, $gateway, 'PPPoE network');
        $this->validateDnsServers($dnsServers);
        $escapedDnsServers = $this->escapeRouterOs($dnsServers);
        $escapedSecret = $this->escapeRouterOs($radiusSecret);
        $radiusServerAddress = (string) config('wireguard.server_address');
        $radiusSourceAddress = (string) $router->vpnPeer?->tunnel_ip;
        if (filter_var($radiusServerAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || filter_var($radiusSourceAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \InvalidArgumentException('Valid WireGuard IPv4 addresses are required for PPPoE RADIUS.');
        }
        $serverAddress = $this->escapeRouterOs($radiusServerAddress);
        $sourceAddress = $this->escapeRouterOs($radiusSourceAddress);
        $escapedInterface = $this->escapeRouterOs($interface);
        $serviceInterface = $escapedInterface;
        $vlanLines = [];
        if ($vlanId !== null) {
            $serviceInterface = 'pppoe-vlan-'.$router->id;
            $escapedServiceInterface = $this->escapeRouterOs($serviceInterface);
            $vlanLines[] = ':if ([:len [/interface vlan find name="'.$escapedServiceInterface.'"]] = 0) do={ /interface vlan add name="'.$escapedServiceInterface.'" interface="'.$escapedInterface.'" vlan-id='.(int) $vlanId.' disabled=no }';
            $vlanLines[] = ':if ([:len [/interface vlan find name="'.$escapedServiceInterface.'"]] > 0) do={ /interface vlan set [find name="'.$escapedServiceInterface.'"] interface="'.$escapedInterface.'" vlan-id='.(int) $vlanId.' disabled=no }';
            $vlanLines[] = ':if ([:len [/interface vlan find name="'.$escapedServiceInterface.'"]] > 0) do={ /interface vlan set [find name="'.$escapedServiceInterface.'"] comment="Wi-Fi Billing PPPoE VLAN" }';
        }
        $escapedServiceInterface = $this->escapeRouterOs($serviceInterface);
        $localAddress = $this->escapeRouterOs($gateway);
        $profileName = 'pppoe-billing';
        $poolName = 'pool-pppoe';
        $lines = [
            '',
            '# --- Separate PPPoE subscriber service ---',
            '# This service uses its own interface/VLAN and RADIUS subscriber accounts; it does not use HotSpot vouchers.',
        ];
        foreach ($vlanLines as $vlanLine) {
            $lines[] = $vlanLine;
        }
        $lines[] = ':if ([:len [/ip pool find name="'.$poolName.'"]] = 0) do={ /ip pool add name="'.$poolName.'" ranges='.$poolStart.'-'.$poolEnd.' }';
        $lines[] = ':if ([:len [/ip pool find name="'.$poolName.'"]] > 0) do={ /ip pool set [find name="'.$poolName.'"] ranges='.$poolStart.'-'.$poolEnd.' }';
        $lines[] = ':if ([:len [/ip pool find name="'.$poolName.'"]] > 0) do={ /ip pool set [find name="'.$poolName.'"] comment="Wi-Fi Billing PPPoE Pool" }';
        $lines[] = ':if ([:len [/ppp profile find name="'.$profileName.'"]] = 0) do={ /ppp profile add name="'.$profileName.'" local-address='.$localAddress.' remote-address="'.$poolName.'" dns-server="'.$escapedDnsServers.'" use-encryption=no only-one=no }';
        $lines[] = ':if ([:len [/ppp profile find name="'.$profileName.'"]] > 0) do={ /ppp profile set [find name="'.$profileName.'"] local-address='.$localAddress.' remote-address="'.$poolName.'" dns-server="'.$escapedDnsServers.'" use-encryption=no only-one=no }';
        $lines[] = ':if ([:len [/ppp profile find name="'.$profileName.'"]] > 0) do={ /ppp profile set [find name="'.$profileName.'"] comment="Wi-Fi Billing PPPoE Profile" }';
        $lines[] = ':if ([:len [/interface pppoe-server server find interface="'.$escapedServiceInterface.'"]] = 0) do={ /interface pppoe-server server add interface="'.$escapedServiceInterface.'" service-name="wifi-billing-pppoe" default-profile="'.$profileName.'" authentication=pap max-mtu=1480 max-mru=1480 disabled=no }';
        $lines[] = ':if ([:len [/interface pppoe-server server find interface="'.$escapedServiceInterface.'"]] > 0) do={ /interface pppoe-server server set [find interface="'.$escapedServiceInterface.'"] service-name="wifi-billing-pppoe" default-profile="'.$profileName.'" authentication=pap max-mtu=1480 max-mru=1480 disabled=no }';
        $lines[] = ':if ([:len [/interface pppoe-server server find interface="'.$escapedServiceInterface.'"]] > 0) do={ /interface pppoe-server server set [find interface="'.$escapedServiceInterface.'"] comment="Wi-Fi Billing PPPoE Server" }';
        $lines[] = '/ppp aaa set use-radius=yes accounting=yes interim-update=1m';
        if (! $router->enable_hotspot) {
            $lines[] = ':if ([:len [/radius find address="'.$serverAddress.'" and src-address="'.$sourceAddress.'"]] > 0) do={ /radius set [find address="'.$serverAddress.'" and src-address="'.$sourceAddress.'"] comment="WiFiBillingRADIUS" }';
            $lines[] = ':if ([:len [/radius find comment="WiFiBillingRADIUS"]] = 0) do={ /radius add address="'.$serverAddress.'" src-address="'.$sourceAddress.'" secret="'.$escapedSecret.'" service=ppp authentication-port=1812 accounting-port=1813 timeout=1s }';
            $lines[] = ':if ([:len [/radius find address="'.$serverAddress.'" and src-address="'.$sourceAddress.'"]] > 0) do={ /radius set [find address="'.$serverAddress.'" and src-address="'.$sourceAddress.'"] comment="WiFiBillingRADIUS" }';
            $lines[] = ':if ([:len [/radius find comment="WiFiBillingRADIUS"]] > 0) do={';
            $lines[] = '  /radius set [find comment="WiFiBillingRADIUS"] address="'.$serverAddress.'" src-address="'.$sourceAddress.'" secret="'.$escapedSecret.'" service=ppp authentication-port=1812 accounting-port=1813 timeout=1s';
            $lines[] = '}';
        }

        return implode("\r\n", $lines)."\r\n";
    }

    private function hotspotNetworkDetails(string $network, string $gatewayIp): array
    {
        return $this->networkAddressDetails($network, $gatewayIp, 'Hotspot network');
    }

    private function networkAddressDetails(string $network, string $gatewayIp, string $label): array
    {
        [$networkAddress, $prefix] = $this->validateIpv4Cidr($network, $label);
        if ($prefix < 8 || $prefix >= 31 || filter_var($gatewayIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \InvalidArgumentException($label.' must have a usable IPv4 host range and gateway.');
        }

        $gateway = (int) sprintf('%u', ip2long($gatewayIp));
        $networkStart = (int) sprintf('%u', ip2long($networkAddress));
        $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        $subnetStart = $networkStart & $mask;
        $broadcast = $subnetStart | (~$mask & 0xFFFFFFFF);
        if ($networkStart !== $subnetStart) {
            throw new \InvalidArgumentException($label.' must use the network address for its CIDR prefix.');
        }
        if (($gateway & $mask) !== $subnetStart) {
            throw new \InvalidArgumentException('Gateway must be inside the configured '.$label.'.');
        }

        $poolStart = max($subnetStart + 10, $gateway + 1);
        $poolEnd = $broadcast - 1;
        if ($poolStart > $poolEnd) {
            throw new \InvalidArgumentException($label.' is too small for the selected gateway and address pool.');
        }

        return [$prefix, long2ip($poolStart), long2ip($poolEnd)];
    }

    public function networksOverlap(string $first, string $second): bool
    {
        [$firstAddress, $firstPrefix] = $this->validateIpv4Cidr($first, 'First network');
        [$secondAddress, $secondPrefix] = $this->validateIpv4Cidr($second, 'Second network');
        $firstMask = (0xFFFFFFFF << (32 - $firstPrefix)) & 0xFFFFFFFF;
        $secondMask = (0xFFFFFFFF << (32 - $secondPrefix)) & 0xFFFFFFFF;
        $firstStart = ((int) sprintf('%u', ip2long($firstAddress))) & $firstMask;
        $secondStart = ((int) sprintf('%u', ip2long($secondAddress))) & $secondMask;
        $firstEnd = $firstStart | (~$firstMask & 0xFFFFFFFF);
        $secondEnd = $secondStart | (~$secondMask & 0xFFFFFFFF);

        return $firstStart <= $secondEnd && $secondStart <= $firstEnd;
    }

    public function isValidHotspotGateway(string $network, string $gatewayIp): bool
    {
        try {
            $this->hotspotNetworkDetails($network, $gatewayIp);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private function validateIpv4Cidr(string $value, string $label): array
    {
        $parts = explode('/', $value, 2);
        $address = $parts[0];
        $prefix = isset($parts[1]) && ctype_digit($parts[1])
            ? (int) $parts[1]
            : (isset($parts[1]) ? -1 : 32);

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $prefix < 0 || $prefix > 32) {
            throw new \InvalidArgumentException("{$label} must be a valid IPv4 address or CIDR.");
        }

        return [$address, $prefix];
    }

    private function validateDnsServers(string $servers): void
    {
        foreach (explode(',', $servers) as $server) {
            if (filter_var(trim($server), FILTER_VALIDATE_IP) === false) {
                throw new \InvalidArgumentException('DNS servers must be comma-separated IP addresses.');
            }
        }
    }

    private function validateHostname(string $hostname, string $label): void
    {
        if (strlen($hostname) > 253
            || ! preg_match('/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\.(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?))*$/', $hostname)) {
            throw new \InvalidArgumentException("{$label} must be a valid hostname.");
        }
    }

    /**
     * Deploy hotspot profiles and walled garden entries directly to a connected MikroTik router.
     */
    public function deploy(Router $router): array
    {
        if (self::UNGUIDED_PROVISIONING_BLOCKED) {
            return ['ok' => false, 'message' => self::SAFETY_BLOCK];
        }

        $tenant = $router->tenant ?? Tenant::find($router->tenant_id);
        $router->loadMissing('vpnPeer');
        $peer = $router->vpnPeer;
        if (! $peer?->enabled || ! $router->enrolled
            || blank($router->api_username) || blank($router->api_password)) {
            return [
                'ok' => false,
                'message' => 'An enrolled router with an enabled WireGuard peer and API credentials is required before provisioning.',
            ];
        }

        $host = $peer->tunnel_ip;
        $key = 'provision-router-'.$router->id;
        try {
            DynamicMikrotikRouterConfig::set($key, [
                'username' => $router->api_username,
                'password' => $router->api_password,
                ...RouterTransportConfig::forVerifiedWireGuardPeer(
                    $host,
                    (int) ($router->api_port ?: config('services.mikrotik.api_port')),
                    $peer,
                    $router->id,
                    true,
                ),
                'timeout' => 8,
            ]);
            $client = MikroTik::router($key);
            $hotspot = $client->hotspot();

            // 1. Verify connection
            $resources = $client->system()->getResources();
            if (empty($resources)) {
                return [
                    'ok' => false,
                    'message' => 'Router did not return system resource metrics.',
                ];
            }

            // 2. Sync Package Profiles
            $packages = $tenant ? Package::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get() : collect();
            $profilesSynced = 0;

            foreach ($packages as $package) {
                app(HotspotProfileSynchronizer::class)->sync(
                    $key,
                    $hotspot,
                    $package->routerProfileSettings(),
                );
                $profilesSynced++;
            }

            $router->update([
                'status' => 'online',
                'last_seen_at' => now(),
            ]);

            return [
                'ok' => true,
                'message' => "Successfully provisioned router! Synchronized {$profilesSynced} bandwidth profile(s).",
                'profiles_synced' => $profilesSynced,
            ];
        } catch (\Throwable $e) {
            Log::warning('Router automated provisioning failed', [
                'router_id' => $router->id,
                'exception' => $e::class,
            ]);

            return [
                'ok' => false,
                'message' => $e instanceof RouterTransportException ? $e->getMessage() : 'Could not connect to router API.',
            ];
        }
    }
}
