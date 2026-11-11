<?php

namespace App\Services;

use App\Models\Package;
use App\Models\Router;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;

class RouterProvisioningService
{
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
        $tenant = $router->tenant ?? Tenant::find($router->tenant_id);
        $hotspotConfig = $router->hotspot_config ?? [];

        $routerName = $router->name ?: 'MikroTik-Router';
        $escapedRouterName = $this->escapeRouterOs($routerName);

        $gatewayIp = (string) ($hotspotConfig['gateway'] ?? '10.10.0.1');
        $network = (string) ($hotspotConfig['network'] ?? '10.10.0.0/16');
        $domain = (string) ($hotspotConfig['domain'] ?? 'hotspot.local');
        $dnsServers = (string) ($hotspotConfig['dns'] ?? '8.8.8.8,8.8.4.4');
        $managementSubnet = $hotspotConfig['management_subnet'] ?? null;
        [$prefix, $poolStart, $poolEnd] = $this->hotspotNetworkDetails($network, $gatewayIp);
        $this->validateDnsServers($dnsServers);
        $this->validateHostname($domain, 'Hotspot domain');
        if ($managementSubnet !== null && $managementSubnet !== '') {
            [, $managementPrefix] = $this->validateIpv4Cidr((string) $managementSubnet, 'Management subnet');
            if ($managementPrefix < 8) {
                throw new \InvalidArgumentException('Management subnet must be at least as specific as an IPv4 /8.');
            }
        } else {
            $managementSubnet = null;
        }

        $ports = $router->ethernet_ports ?? ['ether2', 'ether3'];
        if (empty($ports)) {
            $ports = ['ether2', 'ether3'];
        }
        foreach ($ports as $port) {
            if (! is_string($port) || ! preg_match('/^(ether|wlan)\d+$/', $port)) {
                throw new \InvalidArgumentException('Ethernet ports must be valid RouterOS etherN or wlanN interface names.');
            }
        }

        $apiUsername = $router->api_username ?: 'billing_api';

        // Password handling: NEVER use literal ****** or hardcoded dummy passwords
        $apiPassword = $router->api_password;
        if (blank($apiPassword)) {
            $apiPassword = Str::password(32);
            $router->forceFill(['api_password' => $apiPassword])->save();
        }

        $escapedApiUsername = $this->escapeRouterOs($apiUsername);
        $escapedApiPassword = $this->escapeRouterOs($apiPassword);
        $escapedDomain = $this->escapeRouterOs($domain);
        $escapedDnsServers = $this->escapeRouterOs($dnsServers);

        $baseDomain = config('tenancy.base_domain', 'localhost');
        $this->validateHostname((string) $baseDomain, 'Tenant base domain');
        $escapedBaseDomain = $this->escapeRouterOs((string) $baseDomain);
        $apiPort = (int) ($router->api_port ?: 8728);
        $apiService = $apiPort === 8729 ? 'api-ssl' : 'api';
        $tenantName = preg_replace('/[\r\n\x00-\x1F]/', ' ', $tenant?->name ?? 'Default');
        $gatewayWithPrefix = "{$gatewayIp}/{$prefix}";

        $packages = $tenant ? Package::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get() : collect();

        $lines = [];
        $lines[] = '# =================================================================';
        $lines[] = '# Wi-Fi Billing SaaS - Automated MikroTik Provisioning Script';
        $lines[] = "# Router: {$escapedRouterName} (ID: {$router->id})";
        $lines[] = '# Tenant: '.$tenantName;
        $lines[] = '# Generated: '.now()->toIso8601String();
        $lines[] = '#';
        $lines[] = '# BEFORE RUNNING:';
        $lines[] = '# 1. Take a backup: /system backup save name=pre-wifi-billing';
        $lines[] = '# 2. Intended for a fresh/dedicated router only; review existing bridge, IP, DHCP,';
        $lines[] = '#    hotspot, and firewall configuration before running on a configured router.';
        $lines[] = '# 3. Supply the management subnet allowed to access the RouterOS API.';
        $lines[] = '#';
        $lines[] = '# IF THE IMPORT FAILS PARTWAY: inspect /log print; do not blindly rerun on a live network.';
        $lines[] = '# Restore if needed: /system backup load name=pre-wifi-billing (may reboot/disconnect).';
        $lines[] = '# =================================================================';
        $lines[] = '';

        // Clients use upstream DNS directly, so the router need not be a remote resolver.
        $lines[] = "/system identity set name=\"{$escapedRouterName}\"";
        $lines[] = "/ip dns set allow-remote-requests=no servers=\"{$escapedDnsServers}\"";
        $lines[] = '';

        // 2. Client bridge configuration. Review existing membership before running.
        $lines[] = ':if ([:len [/interface bridge find name=br-hotspot]] = 0) do={ /interface bridge add name=br-hotspot comment="Wi-Fi Billing Client Bridge" disabled=no }';
        foreach ($ports as $port) {
            $lines[] = ":if ([:len [/interface bridge port find interface=\"{$port}\"]] = 0) do={ /interface bridge port add bridge=br-hotspot interface=\"{$port}\" comment=\"Client access port\" } else={ /interface bridge port set [find interface=\"{$port}\"] bridge=br-hotspot comment=\"Client access port\" }";
        }
        if ($router->has_wireless) {
            $lines[] = ':if ([:len [/interface bridge port find interface=wlan1]] = 0) do={ /interface bridge port add bridge=br-hotspot interface=wlan1 comment="Wireless access" disabled=no } else={ /interface bridge port set [find interface=wlan1] bridge=br-hotspot comment="Wireless access" disabled=no }';
        }
        $lines[] = '';

        // 3. IP Pool and Addressing (Idempotent + configured network prefix)
        $lines[] = ":if ([:len [/ip pool find name=pool-hotspot]] = 0) do={ /ip pool add name=pool-hotspot ranges={$poolStart}-{$poolEnd} } else={ /ip pool set [find name=pool-hotspot] ranges={$poolStart}-{$poolEnd} }";
        $lines[] = ":if ([:len [/ip address find interface=br-hotspot and address=\"{$gatewayWithPrefix}\"]] = 0) do={ /ip address add address={$gatewayWithPrefix} interface=br-hotspot comment=\"Hotspot Gateway IP\" }";
        $lines[] = ':if ([:len [/ip dhcp-server find name=dhcp-hotspot]] = 0) do={ /ip dhcp-server add address-pool=pool-hotspot disabled=no interface=br-hotspot lease-time=1h name=dhcp-hotspot } else={ /ip dhcp-server set [find name=dhcp-hotspot] address-pool=pool-hotspot interface=br-hotspot }';
        $lines[] = ":if ([:len [/ip dhcp-server network find address=\"{$network}\"]] = 0) do={ /ip dhcp-server network add address=\"{$network}\" dns-server=\"{$escapedDnsServers}\" gateway={$gatewayIp} comment=\"Hotspot client network\" } else={ /ip dhcp-server network set [find address=\"{$network}\"] dns-server=\"{$escapedDnsServers}\" gateway={$gatewayIp} }";
        $lines[] = '';

        // 4. Hotspot Profile and Server (Idempotent)
        $lines[] = ":if ([:len [/ip hotspot profile find name=prof-billing]] = 0) do={ /ip hotspot profile add name=prof-billing dns-name=\"{$escapedDomain}\" hotspot-address={$gatewayIp} html-directory=hotspot login-by=http-chap use-radius=no } else={ /ip hotspot profile set [find name=prof-billing] dns-name=\"{$escapedDomain}\" hotspot-address={$gatewayIp} login-by=http-chap use-radius=no }";
        $lines[] = ':if ([:len [/ip hotspot find name=hs-billing]] = 0) do={ /ip hotspot add address-pool=pool-hotspot disabled=no interface=br-hotspot name=hs-billing profile=prof-billing } else={ /ip hotspot set [find name=hs-billing] address-pool=pool-hotspot interface=br-hotspot profile=prof-billing }';
        $lines[] = '';

        // 5. Walled Garden (Captive portal, Payment gateways - Idempotent)
        $lines[] = '# --- Walled Garden Access ---';
        $walledHosts = [
            "*{$escapedBaseDomain}*" => 'SaaS Platform Portal',
            '*pesapal.com*' => 'Pesapal Payment Gateway',
            '*mtn.co.ug*' => 'MTN MoMo Gateway',
            '*airtel.co.ug*' => 'Airtel Money Gateway',
            '*google.com*' => 'Android Captive Portal Check',
            '*apple.com*' => 'Apple Captive Portal Check',
        ];
        foreach ($walledHosts as $host => $comment) {
            $escapedHost = $this->escapeRouterOs($host);
            $escapedComment = $this->escapeRouterOs($comment);
            $lines[] = ":if ([:len [/ip hotspot walled-garden find dst-host=\"{$escapedHost}\"]] = 0) do={ /ip hotspot walled-garden add dst-host=\"{$escapedHost}\" comment=\"{$escapedComment}\" action=allow }";
        }
        $lines[] = ":if ([:len [/ip hotspot walled-garden ip find dst-address=\"{$gatewayIp}\"]] = 0) do={ /ip hotspot walled-garden ip add dst-address={$gatewayIp} action=accept comment=\"Local Gateway\" }";
        $lines[] = '';

        // 6. Restrict API access to the explicitly configured management subnet.
        $lines[] = '# --- RouterOS API Management Service ---';
        $unusedApiService = $apiService === 'api' ? 'api-ssl' : 'api';
        $lines[] = "/ip service set {$unusedApiService} disabled=yes";
        if ($apiService === 'api-ssl') {
            $lines[] = '# API-SSL must have a server certificate configured before connection testing.';
        }
        if ($managementSubnet) {
            $escapedSubnet = $this->escapeRouterOs((string) $managementSubnet);
            $lines[] = "/ip service set {$apiService} port={$apiPort} address=\"{$escapedSubnet}\" disabled=no";
        } else {
            $lines[] = "# {$apiService} remains disabled because no management subnet was supplied.";
            $lines[] = "/ip service set {$apiService} disabled=yes";
        }
        $lines[] = ':if ([:len [/user group find name=billing_group]] = 0) do={ /user group add name=billing_group policy=api,read,write comment="Wi-Fi Billing SaaS Group" } else={ /user group set [find name=billing_group] policy=api,read,write }';
        $lines[] = ":if ([:len [/user find name=\"{$escapedApiUsername}\"]] = 0) do={ /user add name=\"{$escapedApiUsername}\" group=billing_group password=\"{$escapedApiPassword}\" comment=\"Wi-Fi Billing SaaS API User\" } else={ /user set [find name=\"{$escapedApiUsername}\"] group=billing_group password=\"{$escapedApiPassword}\" }";
        $lines[] = '';

        // 7. Hotspot User Profiles for Tenant Packages (Idempotent)
        if ($packages->isNotEmpty()) {
            $lines[] = '# --- Bandwidth and Device Limit Profiles for Packages ---';
            foreach ($packages as $pkg) {
                $profile = $pkg->routerProfileSettings();
                $escapedPkgName = $this->escapeRouterOs($profile['name']);
                $escapedRateLimit = $this->escapeRouterOs($profile['rate-limit']);
                $settings = "rate-limit=\"{$escapedRateLimit}\" shared-users={$profile['shared-users']}";
                $lines[] = '# Voucher uptime and data caps are applied to each HotSpot user by the site agent.';
                $lines[] = ":if ([:len [/ip hotspot user profile find name=\"{$escapedPkgName}\"]] = 0) do={ /ip hotspot user profile add name=\"{$escapedPkgName}\" {$settings} keepalive-timeout=2m status-autorefresh=1m } else={ /ip hotspot user profile set [find name=\"{$escapedPkgName}\"] {$settings} }";
            }
            $lines[] = '';
        }

        $lines[] = '# End of provisioning script';

        return implode("\r\n", $lines)."\r\n";
    }

    private function hotspotNetworkDetails(string $network, string $gatewayIp): array
    {
        [$networkAddress, $prefix] = $this->validateIpv4Cidr($network, 'Hotspot network');
        if ($prefix < 8 || $prefix >= 31 || filter_var($gatewayIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \InvalidArgumentException('Hotspot network must have a usable IPv4 host range and gateway.');
        }

        $gateway = (int) sprintf('%u', ip2long($gatewayIp));
        $networkStart = (int) sprintf('%u', ip2long($networkAddress));
        $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        $subnetStart = $networkStart & $mask;
        $broadcast = $subnetStart | (~$mask & 0xFFFFFFFF);
        if ($networkStart !== $subnetStart) {
            throw new \InvalidArgumentException('Hotspot network must use the network address for its CIDR prefix.');
        }
        if (($gateway & $mask) !== $subnetStart) {
            throw new \InvalidArgumentException('Hotspot gateway must be inside the configured hotspot network.');
        }

        $poolStart = max($subnetStart + 10, $gateway + 1);
        $poolEnd = $broadcast - 1;
        if ($poolStart > $poolEnd) {
            throw new \InvalidArgumentException('Hotspot network is too small for the selected gateway and DHCP pool.');
        }

        return [$prefix, long2ip($poolStart), long2ip($poolEnd)];
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
        $tenant = $router->tenant ?? Tenant::find($router->tenant_id);
        if (config('site_agent.required')) {
            return [
                'ok' => false,
                'message' => 'Direct cloud-to-router deployment is disabled while site-agent mode is required.',
            ];
        }

        $router->loadMissing('vpnPeer');
        $host = $router->vpnPeer?->enabled
            ? $router->vpnPeer->tunnel_ip
            : (config('site_agent.allow_direct_router_connection') ? $router->management_ip : null);

        if (blank($host) || blank($router->api_username) || blank($router->api_password)) {
            return [
                'ok' => false,
                'message' => 'A WireGuard peer or explicitly enabled direct connection, API username and API password are required before deploying via API.',
            ];
        }

        $key = 'provision-router-'.$router->id;
        DynamicMikrotikRouterConfig::set($key, [
            'username' => $router->api_username,
            'password' => $router->api_password,
            ...RouterTransportConfig::forRouter($host, (int) ($router->api_port ?: 8728), $router->id),
            'timeout' => 8,
        ]);

        try {
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
                'message' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => 'Could not connect to router API: '.$e->getMessage(),
            ];
        }
    }
}
