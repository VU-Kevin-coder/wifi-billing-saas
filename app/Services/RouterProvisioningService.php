<?php

namespace App\Services;

use App\Models\Package;
use App\Models\Router;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;

class RouterProvisioningService
{
    /**
     * Generate the complete RouterOS (.rsc) configuration script for a router.
     */
    public function generateScript(Router $router): string
    {
        $tenant = $router->tenant ?? Tenant::find($router->tenant_id);
        $hotspotConfig = $router->hotspot_config ?? [];

        $routerName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $router->name ?: 'MikroTik-Router');
        $gatewayIp = $hotspotConfig['gateway'] ?? '10.10.0.1';
        $network = $hotspotConfig['network'] ?? '10.10.0.0/16';
        $domain = $hotspotConfig['domain'] ?? 'hotspot.local';
        $dnsServers = $hotspotConfig['dns'] ?? '8.8.8.8,8.8.4.4';
        $ports = $router->ethernet_ports ?? ['ether2', 'ether3'];
        if (empty($ports)) {
            $ports = ['ether2', 'ether3'];
        }

        // Calculate pool range based on gateway
        $ipParts = explode('.', $gatewayIp);
        $poolStart = "{$ipParts[0]}.{$ipParts[1]}.{$ipParts[2]}.10";
        $poolEnd = "{$ipParts[0]}.{$ipParts[1]}.254.254";

        $apiPort = $router->api_port ?: 8728;
        $apiUsername = $router->api_username ?: 'billing_api';

        // Calculate portal URL
        $baseDomain = config('tenancy.base_domain', 'localhost');
        $portalUrl = $tenant
            ? "http://{$tenant->slug}.{$baseDomain}/portal"
            : config('app.url').'/portal';

        $packages = $tenant ? Package::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get() : collect();

        $lines = [];
        $lines[] = '# =================================================================';
        $lines[] = '# Wi-Fi Billing SaaS - Automated MikroTik Provisioning Script';
        $lines[] = "# Router: {$router->name} (ID: {$router->id})";
        $lines[] = '# Tenant: '.($tenant?->name ?? 'Default');
        $lines[] = '# Generated: '.now()->toIso8601String();
        $lines[] = '# =================================================================';
        $lines[] = '';

        // 1. Identity & System Settings
        $lines[] = "/system identity set name=\"{$routerName}\"";
        $lines[] = "/ip dns set allow-remote-requests=yes servers=\"{$dnsServers}\"";
        $lines[] = '';

        // 2. Client Bridge Configuration
        $lines[] = '/interface bridge add name=br-hotspot comment="Wi-Fi Billing Client Bridge" disabled=no';
        foreach ($ports as $port) {
            $cleanPort = preg_replace('/[^a-zA-Z0-9_\-]/', '', $port);
            $lines[] = "/interface bridge port add bridge=br-hotspot interface={$cleanPort} comment=\"Client access port\"";
        }
        if ($router->has_wireless) {
            $lines[] = '/interface bridge port add bridge=br-hotspot interface=wlan1 comment="Wireless access" disabled=no';
        }
        $lines[] = '';

        // 3. IP Pool and Addressing
        $lines[] = "/ip pool add name=pool-hotspot ranges={$poolStart}-{$poolEnd}";
        $lines[] = "/ip address add address={$gatewayIp}/16 interface=br-hotspot comment=\"Hotspot Gateway IP\"";
        $lines[] = '/ip dhcp-server add address-pool=pool-hotspot disabled=no interface=br-hotspot lease-time=1h name=dhcp-hotspot';
        $lines[] = "/ip dhcp-server network add address={$network} dns-server={$gatewayIp},{$dnsServers} gateway={$gatewayIp} comment=\"Hotspot client network\"";
        $lines[] = '';

        // 4. Hotspot Profile and Server
        $lines[] = "/ip hotspot profile add name=prof-billing dns-name=\"{$domain}\" hotspot-address={$gatewayIp} html-directory=hotspot login-by=http-chap,http-pap use-radius=no";
        $lines[] = '/ip hotspot add address-pool=pool-hotspot disabled=no interface=br-hotspot name=hs-billing profile=prof-billing';
        $lines[] = '';

        // 5. Walled Garden (Captive portal, Payment gateways)
        $lines[] = '# --- Walled Garden Access ---';
        $lines[] = "/ip hotspot walled-garden add dst-host=*{$baseDomain}* comment=\"SaaS Platform Portal\" action=allow";
        $lines[] = '/ip hotspot walled-garden add dst-host=*pesapal.com* comment="Pesapal Payment Gateway" action=allow';
        $lines[] = '/ip hotspot walled-garden add dst-host=*mtn.co.ug* comment="MTN MoMo Gateway" action=allow';
        $lines[] = '/ip hotspot walled-garden add dst-host=*airtel.co.ug* comment="Airtel Money Gateway" action=allow';
        $lines[] = '/ip hotspot walled-garden add dst-host=*google.com* comment="Android Captive Portal Check" action=allow';
        $lines[] = '/ip hotspot walled-garden add dst-host=*apple.com* comment="Apple Captive Portal Check" action=allow';
        $lines[] = "/ip hotspot walled-garden ip add dst-address={$gatewayIp} action=accept comment=\"Local Gateway\"";
        $lines[] = '';

        // 6. RouterOS API Service Configuration
        $lines[] = '# --- RouterOS API Management Service ---';
        $lines[] = "/ip service set api port={$apiPort} disabled=no";
        $lines[] = '/user group add name=billing_group policy=api,read,write,test,password comment="Wi-Fi Billing SaaS Group"';
        $lines[] = "/user add name={$apiUsername} group=billing_group comment=\"Wi-Fi Billing SaaS API User\" password=ChangeMeNow123!";
        $lines[] = '';

        // 7. Hotspot User Profiles for Tenant Packages
        if ($packages->isNotEmpty()) {
            $lines[] = '# --- Bandwidth & Time Limit Profiles for Packages ---';
            foreach ($packages as $pkg) {
                $pkgName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $pkg->name);
                $speed = $pkg->speed_limit_mbps ? "{$pkg->speed_limit_mbps}M/{$pkg->speed_limit_mbps}M" : '5M/5M';
                $shared = $pkg->device_limit ?: 1;
                $lines[] = "/ip hotspot user profile add name=\"{$pkgName}\" rate-limit=\"{$speed}\" shared-users={$shared} keepalive-timeout=2m status-autorefresh=1m";
            }
            $lines[] = '';
        }

        $lines[] = '# =================================================================';
        $lines[] = '# End of Provisioning Script';
        $lines[] = '# =================================================================';

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * Deploy hotspot profiles and walled garden entries directly to a connected MikroTik router.
     */
    public function deploy(Router $router): array
    {
        $tenant = $router->tenant ?? Tenant::find($router->tenant_id);

        if (blank($router->management_ip) || blank($router->api_username) || blank($router->api_password)) {
            return [
                'ok' => false,
                'message' => 'Router credentials (host, username, password) must be configured before deploying via API.',
            ];
        }

        $key = 'provision-router-'.$router->id;
        config([
            "mikrotik.routers.{$key}" => [
                'host' => $router->management_ip,
                'username' => $router->api_username,
                'password' => $router->api_password,
                'port' => (int) ($router->api_port ?: 8728),
                'timeout' => 8,
            ],
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
                $pkgName = $package->name;
                $speed = $package->speed_limit_mbps ? "{$package->speed_limit_mbps}M/{$package->speed_limit_mbps}M" : '5M/5M';
                $shared = max(1, (int) ($package->device_limit ?: 1));

                try {
                    $hotspot->createProfile([
                        'name' => $pkgName,
                        'rate-limit' => $speed,
                        'shared-users' => $shared,
                    ]);
                    $profilesSynced++;
                } catch (\Throwable $e) {
                    // Profile might already exist
                    $profilesSynced++;
                }
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
