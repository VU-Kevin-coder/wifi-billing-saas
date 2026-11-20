<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Router;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;

class RouterProvisioningService
{
    // No request, confirmation or opt-in is sufficient until target identity and
    // existing configuration can be verified. Do not bypass this server-side gate.
    private const PROVISIONING_BLOCKED = true;

    public const SAFETY_BLOCK = 'Router provisioning is disabled: the target device identity and existing configuration cannot be verified, and the script may overwrite bridge, DHCP, firewall or RADIUS settings. Do not use previously downloaded scripts.';

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
        if (self::PROVISIONING_BLOCKED) {
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
            ':if ([:len [/radius find comment="Wi-Fi Billing RADIUS"]] = 0) do={ /radius add address="'.$server.'" src-address="'.$source.'" secret="'.$secret.'" service=hotspot authentication-port=1812 accounting-port=1813 timeout=1s comment="Wi-Fi Billing RADIUS" } else={ /radius set [find comment="Wi-Fi Billing RADIUS"] address="'.$server.'" src-address="'.$source.'" secret="'.$secret.'" service=hotspot authentication-port=1812 accounting-port=1813 timeout=1s }',
            '/ip hotspot profile set [find name=prof-billing] use-radius=yes radius-accounting=yes radius-interim-update=1m',
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
            if (! RadiusReadiness::available() || ! $router->hasUsableRadiusConfiguration()) {
                return ['ok' => false, 'message' => RadiusRouterCredentialService::UNAVAILABLE_MESSAGE];
            }
        }
        if (! $router->enable_hotspot && ! $router->enable_pppoe) {
            return ['ok' => false, 'message' => 'Enable at least one supported router service before provisioning.'];
        }

        try {
            $radiusSecret = $router->radius_shared_secret;
            $script = $this->buildScript($router, $router->enable_hotspot ? $radiusSecret : null);
            if ($router->enable_pppoe) {
                $script .= $this->buildPppoeScript($router, $radiusSecret);
            }
            app(RouterConnectionService::class)->executeOnboardingScript($router, $script);
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        } catch (RouterTransportException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }

        return [
            'ok' => true,
            'message' => 'Router service configuration was applied successfully.',
        ];
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
        $wifiSsid = trim((string) $router->wifi_ssid);
        if ($router->has_wireless && $wifiSsid === '') {
            $wifiSsid = trim((string) ($tenant?->name ?: 'Free WiFi'));
        }
        $escapedWifiSsid = $this->escapeRouterOs(mb_strcut($wifiSsid, 0, 32, 'UTF-8'));

        $gatewayIp = (string) ($hotspotConfig['gateway'] ?? config('services.mikrotik.gateway'));
        $network = (string) ($hotspotConfig['network'] ?? PlatformSetting::get('hotspot.default_network', config('services.mikrotik.hotspot_network')));
        $domain = (string) ($hotspotConfig['domain'] ?? config('services.mikrotik.hotspot_domain'));
        $defaultDnsServers = implode(',', (array) PlatformSetting::get(
            'hotspot.default_dns',
            explode(',', (string) config('services.mikrotik.dns_servers')),
        ));
        $dnsServers = (string) ($hotspotConfig['dns'] ?? $defaultDnsServers);
        $managementSubnet = $hotspotConfig['management_subnet'] ?? null;
        $wanInterface = (string) ($hotspotConfig['wan_interface'] ?? PlatformSetting::get('hotspot.wan_interface', 'ether1'));
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

        $ports = $router->ethernet_ports ?? config('services.mikrotik.client_ports');
        if (empty($ports)) {
            $ports = config('services.mikrotik.client_ports');
        }
        foreach ($ports as $port) {
            if (! is_string($port) || ! preg_match('/^(ether|wlan)\d+$/', $port)) {
                throw new \InvalidArgumentException('Ethernet ports must be valid RouterOS etherN or wlanN interface names.');
            }
        }
        $ports = array_values(array_filter($ports, fn (string $port): bool => $port !== $wanInterface));

        $apiUsername = $router->api_username ?: config('services.mikrotik.api_username');

        // Password handling: NEVER use literal ****** or hardcoded dummy passwords
        $apiPassword = $router->api_password;
        if (blank($apiPassword)) {
            $apiPassword = Str::password(max(16, (int) PlatformSetting::get('router.password_length', 24)));
            $router->forceFill(['api_password' => $apiPassword])->save();
        }

        $escapedApiUsername = $this->escapeRouterOs($apiUsername);
        $escapedApiPassword = $this->escapeRouterOs($apiPassword);
        $escapedDomain = $this->escapeRouterOs($domain);
        $escapedDnsServers = $this->escapeRouterOs($dnsServers);

        $baseDomain = config('tenancy.base_domain', 'localhost');
        $this->validateHostname((string) $baseDomain, 'Tenant base domain');
        $escapedBaseDomain = $this->escapeRouterOs((string) $baseDomain);
        $apiPort = (int) ($router->api_port ?: config('services.mikrotik.api_port'));
        $apiService = $apiPort === 8729 ? 'api-ssl' : 'api';
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
        $lines[] = '# 1. Take a backup: /system backup save name=pre-wifi-billing';
        $lines[] = '# 2. Intended for a fresh/dedicated router only; review existing bridge, IP, DHCP,';
        $lines[] = '#    hotspot, and firewall configuration before running on a configured router.';
        $lines[] = '# 3. Supply the management subnet allowed to access the RouterOS API.';
        $lines[] = '# WAN interface '.$this->escapeRouterOs($wanInterface).' is kept outside the client bridge.';
        $lines[] = '#';
        $lines[] = '# IF THE IMPORT FAILS PARTWAY: inspect /log print; do not blindly rerun on a live network.';
        $lines[] = '# Restore if needed: /system backup load name=pre-wifi-billing (may reboot/disconnect).';
        $lines[] = '# =================================================================';
        $lines[] = '';

        // Clients use upstream DNS directly, so the router need not be a remote resolver.
        $routerIdentity = $radiusEnabled ? (string) $router->radius_nas_identifier : $routerName;
        $lines[] = '/system identity set name="'.$this->escapeRouterOs($routerIdentity).'"';
        $lines[] = "/ip dns set allow-remote-requests=no servers=\"{$escapedDnsServers}\"";
        $lines[] = '';

        // 2. Client bridge configuration. Review existing membership before running.
        $lines[] = ':if ([:len [/interface bridge find name=br-hotspot]] = 0) do={ /interface bridge add name=br-hotspot comment="Wi-Fi Billing Client Bridge" disabled=no }';
        foreach ($ports as $port) {
            $lines[] = ":if ([:len [/interface bridge port find interface=\"{$port}\"]] = 0) do={ /interface bridge port add bridge=br-hotspot interface=\"{$port}\" comment=\"Client access port\" } else={ /interface bridge port set [find interface=\"{$port}\"] bridge=br-hotspot comment=\"Client access port\" }";
        }
        if ($router->has_wireless) {
            foreach (['wlan1', 'wlan2'] as $interface) {
                $lines[] = ":do { :if ([:len [/interface wireless find default-name={$interface}]] > 0) do={ :if ([:len [/interface bridge port find interface={$interface}]] = 0) do={ /interface bridge port add bridge=br-hotspot interface={$interface} comment=\"Wireless access\" disabled=no } else={ /interface bridge port set [find interface={$interface}] bridge=br-hotspot comment=\"Wireless access\" disabled=no } } } on-error={ :log warning \"Could not add {$interface} to the hotspot bridge; verify this radio exists.\" }";
            }
            foreach (['wifi1', 'wifi2'] as $interface) {
                $lines[] = ":do { :if ([:len [/interface wifi find default-name={$interface}]] > 0) do={ :if ([:len [/interface bridge port find interface={$interface}]] = 0) do={ /interface bridge port add bridge=br-hotspot interface={$interface} comment=\"Wireless access\" disabled=no } else={ /interface bridge port set [find interface={$interface}] bridge=br-hotspot comment=\"Wireless access\" disabled=no } } } on-error={ :log warning \"Could not add {$interface} to the hotspot bridge; verify the WiFi package and radio.\" }";
            }
            $lines[] = '# --- Wi-Fi SSID (existing security settings are preserved) ---';
            foreach (['wlan1', 'wlan2'] as $interface) {
                $lines[] = ":do { /interface wireless set [find default-name={$interface}] ssid=\"{$escapedWifiSsid}\" disabled=no } on-error={ :log warning \"Could not set SSID on {$interface}; verify this radio exists.\" }";
            }
            foreach (['wifi1', 'wifi2'] as $interface) {
                $lines[] = ":do { /interface wifi set [find default-name={$interface}] configuration.ssid=\"{$escapedWifiSsid}\" disabled=no } on-error={ :log warning \"Could not set SSID on {$interface}; verify the WiFi package and radio.\" }";
            }
        }
        $lines[] = '';

        // 3. IP Pool and Addressing (Idempotent + configured network prefix)
        $lines[] = ":if ([:len [/ip pool find name=pool-hotspot]] = 0) do={ /ip pool add name=pool-hotspot ranges={$poolStart}-{$poolEnd} } else={ /ip pool set [find name=pool-hotspot] ranges={$poolStart}-{$poolEnd} }";
        $lines[] = ":if ([:len [/ip address find interface=br-hotspot and address=\"{$gatewayWithPrefix}\"]] = 0) do={ /ip address add address={$gatewayWithPrefix} interface=br-hotspot comment=\"Hotspot Gateway IP\" }";
        $lines[] = ':if ([:len [/ip dhcp-server find name=dhcp-hotspot]] = 0) do={ /ip dhcp-server add address-pool=pool-hotspot disabled=no interface=br-hotspot lease-time=1h name=dhcp-hotspot } else={ /ip dhcp-server set [find name=dhcp-hotspot] address-pool=pool-hotspot interface=br-hotspot }';
        $lines[] = ":if ([:len [/ip dhcp-server network find address=\"{$network}\"]] = 0) do={ /ip dhcp-server network add address=\"{$network}\" dns-server=\"{$escapedDnsServers}\" gateway={$gatewayIp} comment=\"Hotspot client network\" } else={ /ip dhcp-server network set [find address=\"{$network}\"] dns-server=\"{$escapedDnsServers}\" gateway={$gatewayIp} }";
        $lines[] = '';

        // 4. Hotspot Profile and Server (Idempotent)
        $radiusMode = $radiusEnabled ? 'yes' : 'no';
        $radiusAccounting = $radiusEnabled ? 'yes' : 'no';
        $interimUpdate = $radiusEnabled ? '1m' : '0s';
        $lines[] = ":if ([:len [/ip hotspot profile find name=prof-billing]] = 0) do={ /ip hotspot profile add name=prof-billing dns-name=\"{$escapedDomain}\" hotspot-address={$gatewayIp} html-directory=hotspot login-by=http-chap use-radius={$radiusMode} radius-accounting={$radiusAccounting} radius-interim-update={$interimUpdate} } else={ /ip hotspot profile set [find name=prof-billing] dns-name=\"{$escapedDomain}\" hotspot-address={$gatewayIp} login-by=http-chap use-radius={$radiusMode} radius-accounting={$radiusAccounting} radius-interim-update={$interimUpdate} }";
        $lines[] = ':if ([:len [/ip hotspot find name=hs-billing]] = 0) do={ /ip hotspot add address-pool=pool-hotspot disabled=no interface=br-hotspot name=hs-billing profile=prof-billing } else={ /ip hotspot set [find name=hs-billing] address-pool=pool-hotspot interface=br-hotspot profile=prof-billing }';
        $lines[] = '';

        if ($radiusEnabled) {
            $radiusAddress = $this->escapeRouterOs((string) config('wireguard.server_address'));
            $radiusSource = $this->escapeRouterOs((string) $router->vpnPeer->tunnel_ip);
            $escapedRadiusSecret = $this->escapeRouterOs((string) $radiusSecret);
            $lines[] = '# --- Private RADIUS server over WireGuard ---';
            $radiusServices = $router->enable_pppoe ? 'hotspot,ppp' : 'hotspot';
            $lines[] = ':if ([:len [/radius find comment="Wi-Fi Billing RADIUS"]] = 0) do={ /radius add address="'.$radiusAddress.'" src-address="'.$radiusSource.'" secret="'.$escapedRadiusSecret.'" service='.$radiusServices.' authentication-port=1812 accounting-port=1813 timeout=1s comment="Wi-Fi Billing RADIUS" } else={ /radius set [find comment="Wi-Fi Billing RADIUS"] address="'.$radiusAddress.'" src-address="'.$radiusSource.'" secret="'.$escapedRadiusSecret.'" service='.$radiusServices.' authentication-port=1812 accounting-port=1813 timeout=1s }';
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
            $lines[] = ":if ([:len [/ip hotspot walled-garden find dst-host=\"{$escapedHost}\"]] = 0) do={ /ip hotspot walled-garden add dst-host=\"{$escapedHost}\" comment=\"{$escapedComment}\" action=allow }";
        }
        $lines[] = ":if ([:len [/ip hotspot walled-garden ip find dst-address=\"{$gatewayIp}\"]] = 0) do={ /ip hotspot walled-garden ip add dst-address={$gatewayIp} action=accept comment=\"Local Gateway\" }";
        $lines[] = '';

        // 6. Restrict API access to the explicitly configured management subnet.
        $lines[] = '# --- RouterOS API Management Service ---';
        if (PlatformSetting::get('router.disable_insecure', true)) {
            $lines[] = '/ip service set [find name=telnet] disabled=yes';
            $lines[] = '/ip service set [find name=ftp] disabled=yes';
        }
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
        if ($radiusSecret === null && $packages->isNotEmpty()) {
            $lines[] = '# --- Bandwidth and Device Limit Profiles for Packages ---';
            foreach ($packages as $pkg) {
                $profile = $pkg->routerProfileSettings();
                $escapedPkgName = $this->escapeRouterOs($profile['name']);
                $escapedRateLimit = $this->escapeRouterOs($profile['rate-limit']);
                $settings = "rate-limit=\"{$escapedRateLimit}\" shared-users={$profile['shared-users']}";
                $lines[] = '# Voucher uptime and data caps are enforced by the RADIUS policy service.';
                $lines[] = ":if ([:len [/ip hotspot user profile find name=\"{$escapedPkgName}\"]] = 0) do={ /ip hotspot user profile add name=\"{$escapedPkgName}\" {$settings} keepalive-timeout={$idleTimeout}m status-autorefresh=1m } else={ /ip hotspot user profile set [find name=\"{$escapedPkgName}\"] {$settings} keepalive-timeout={$idleTimeout}m }";
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
        $ports = (array) ($router->ethernet_ports ?? []);
        $vlanId = $config['vlan_id'] ?? null;
        if (! preg_match('/^ether\d+$/', $interface)
            || $interface === $wanInterface
            || ($router->enable_hotspot && in_array($interface, $ports, true))) {
            throw new \InvalidArgumentException('PPPoE requires a separate, detected Ethernet interface outside the WAN and HotSpot client bridge.');
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
            $vlanLines[] = ':if ([:len [/interface vlan find name="'.$escapedServiceInterface.'"]] = 0) do={ /interface vlan add name="'.$escapedServiceInterface.'" interface="'.$escapedInterface.'" vlan-id='.(int) $vlanId.' disabled=no } else={ /interface vlan set [find name="'.$escapedServiceInterface.'"] interface="'.$escapedInterface.'" vlan-id='.(int) $vlanId.' disabled=no }';
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
        $lines[] = ':if ([:len [/ip pool find name="'.$poolName.'"]] = 0) do={ /ip pool add name="'.$poolName.'" ranges='.$poolStart.'-'.$poolEnd.' } else={ /ip pool set [find name="'.$poolName.'"] ranges='.$poolStart.'-'.$poolEnd.' }';
        $lines[] = ':if ([:len [/ppp profile find name="'.$profileName.'"]] = 0) do={ /ppp profile add name="'.$profileName.'" local-address='.$localAddress.' remote-address="'.$poolName.'" dns-server="'.$escapedDnsServers.'" use-encryption=no only-one=no } else={ /ppp profile set [find name="'.$profileName.'"] local-address='.$localAddress.' remote-address="'.$poolName.'" dns-server="'.$escapedDnsServers.'" use-encryption=no only-one=no }';
        $lines[] = ':if ([:len [/interface pppoe-server server find interface="'.$escapedServiceInterface.'"]] = 0) do={ /interface pppoe-server server add interface="'.$escapedServiceInterface.'" service-name="wifi-billing-pppoe" default-profile="'.$profileName.'" authentication=pap max-mtu=1480 max-mru=1480 disabled=no } else={ /interface pppoe-server server set [find interface="'.$escapedServiceInterface.'"] service-name="wifi-billing-pppoe" default-profile="'.$profileName.'" authentication=pap max-mtu=1480 max-mru=1480 disabled=no }';
        $lines[] = '/ppp aaa set use-radius=yes accounting=yes interim-update=1m';
        if (! $router->enable_hotspot) {
            $lines[] = ':if ([:len [/radius find comment="Wi-Fi Billing RADIUS"]] = 0) do={ /radius add address="'.$serverAddress.'" src-address="'.$sourceAddress.'" secret="'.$escapedSecret.'" service=ppp authentication-port=1812 accounting-port=1813 timeout=1s comment="Wi-Fi Billing RADIUS" } else={ /radius set [find comment="Wi-Fi Billing RADIUS"] address="'.$serverAddress.'" src-address="'.$sourceAddress.'" secret="'.$escapedSecret.'" service=ppp authentication-port=1812 accounting-port=1813 timeout=1s }';
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
        if (self::PROVISIONING_BLOCKED) {
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
