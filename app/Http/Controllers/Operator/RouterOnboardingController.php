<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\Router;
use App\Services\RadiusReadiness;
use App\Services\RadiusRouterCredentialService;
use App\Services\RadiusUnavailableException;
use App\Services\RouterConnectionService;
use App\Services\RouterOnboardingService;
use App\Services\RouterProvisioningService;
use App\Services\RouterTransportException;
use App\Services\Tenancy\TenantContext;
use App\Services\WireGuardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;

class RouterOnboardingController extends Controller
{
    public function services(
        TenantContext $context,
        int $router,
        RouterConnectionService $connection,
    ) {
        $tenant = $context->getTenant();
        $routerModel = Router::query()
            ->where('tenant_id', $context->getTenantId())
            ->with(['onboarding', 'vpnPeer'])
            ->findOrFail($router);
        abort_unless($tenant && $routerModel->onboarding?->status === 'connected', 404);

        try {
            $interfaces = $connection->onboardingInterfaces($routerModel);
        } catch (RouterTransportException $exception) {
            return redirect()->route('operator.routers.onboarding', [
                'tenant' => $tenant->slug,
                'router' => $routerModel->id,
            ])->with('error', $exception->getMessage());
        }

        $wanInterface = (string) PlatformSetting::get('hotspot.wan_interface', 'ether1');
        $availablePorts = collect($interfaces)
            ->map(fn ($interface): ?string => is_array($interface) ? ($interface['name'] ?? null) : null)
            ->filter(fn ($name): bool => is_string($name)
                && preg_match('/^ether\d+$/', $name) === 1
                && $name !== $wanInterface)
            ->values();
        $pppoeDefaults = $this->pppoeDefaults();

        return response()
            ->view('operator.routers.onboarding-services', [
                'tenant' => $tenant,
                'router' => $routerModel,
                'interfaces' => $interfaces,
                'availablePorts' => $availablePorts,
                'wanInterface' => $wanInterface,
                'pppoeNetwork' => $pppoeDefaults['network'],
                'pppoeGateway' => $pppoeDefaults['gateway'],
                'pppoeDnsServers' => $pppoeDefaults['dns'],
            ])
            ->withHeaders($this->noStoreHeaders());
    }

    public function configure(
        Request $request,
        TenantContext $context,
        int $router,
        RouterConnectionService $connection,
        RouterProvisioningService $provisioning,
        RouterOnboardingService $onboardingService,
    ) {
        $routerModel = Router::query()
            ->where('tenant_id', $context->getTenantId())
            ->with(['onboarding', 'vpnPeer'])
            ->findOrFail($router);
        abort_unless($routerModel->onboarding?->status === 'connected', 404);
        $wanInterface = (string) PlatformSetting::get('hotspot.wan_interface', 'ether1');
        try {
            $interfaces = $connection->onboardingInterfaces($routerModel);
        } catch (RouterTransportException $exception) {
            return back()->withInput()->withErrors(['provisioning' => $exception->getMessage()]);
        }
        $availablePorts = collect($interfaces)
            ->map(fn ($interface): ?string => is_array($interface) ? ($interface['name'] ?? null) : null)
            ->filter(fn ($name): bool => is_string($name)
                && preg_match('/^ether\d+$/', $name) === 1
                && $name !== $wanInterface)
            ->values()
            ->all();
        $validated = $request->validate([
            'enable_hotspot' => ['required', 'boolean'],
            'enable_pppoe' => ['required', 'boolean'],
            'hotspot_network' => ['required_if:enable_hotspot,1', 'nullable', 'string', 'max:50'],
            'gateway_ip' => ['required_if:enable_hotspot,1', 'nullable', 'ipv4'],
            'dns_servers' => ['required_if:enable_hotspot,1', 'nullable', 'string', 'max:100'],
            'hotspot_domain' => ['required_if:enable_hotspot,1', 'nullable', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\.(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?))*$/'],
            'ethernet_ports' => ['required_if:enable_hotspot,1', 'array', 'min:1'],
            'ethernet_ports.*' => ['string', Rule::in($availablePorts), Rule::notIn([$wanInterface])],
            'pppoe_interface' => ['required_if:enable_pppoe,1', 'nullable', 'string', Rule::in($availablePorts)],
            'pppoe_vlan_id' => ['nullable', 'integer', 'between:1,4094'],
            'confirm_dedicated_router' => ['accepted'],
        ]);

        if (! $validated['enable_hotspot'] && ! $validated['enable_pppoe']) {
            return back()->withInput()->withErrors([
                'enable_hotspot' => 'Enable HotSpot, PPPoE, or both to complete service setup.',
            ]);
        }

        if ($validated['enable_pppoe'] && stripos((string) $routerModel->hardware_model, 'chr') !== false) {
            return back()->withInput()->withErrors([
                'enable_pppoe' => 'PPPoE provisioning is not available for CHR lab routers.',
            ]);
        }

        $dnsServers = array_map('trim', explode(',', (string) ($validated['dns_servers'] ?? '')));
        foreach (array_filter($dnsServers) as $dnsServer) {
            if (filter_var($dnsServer, FILTER_VALIDATE_IP) === false) {
                return back()->withInput()->withErrors(['dns_servers' => 'DNS servers must be comma-separated IP addresses.']);
            }
        }

        $pppoeConfig = [];
        if ($validated['enable_pppoe']) {
            $pppoeDefaults = $this->pppoeDefaults();
            $pppoeNetwork = $pppoeDefaults['network'];
            $pppoeGateway = $pppoeDefaults['gateway'];
            $pppoeDns = $pppoeDefaults['dns'];
            if (! $provisioning->isValidHotspotGateway($pppoeNetwork, $pppoeGateway)) {
                return back()->withInput()->withErrors(['enable_pppoe' => 'Platform PPPoE network settings are invalid.']);
            }
            foreach (explode(',', $pppoeDns) as $dnsServer) {
                if (filter_var(trim($dnsServer), FILTER_VALIDATE_IP) === false) {
                    return back()->withInput()->withErrors(['enable_pppoe' => 'Platform PPPoE DNS settings are invalid.']);
                }
            }
            if ($validated['enable_hotspot']
                && $provisioning->networksOverlap((string) $validated['hotspot_network'], $pppoeNetwork)) {
                return back()->withInput()->withErrors(['enable_pppoe' => 'The platform PPPoE network must not overlap the HotSpot network.']);
            }

            if ($validated['enable_hotspot']
                && in_array($validated['pppoe_interface'], $validated['ethernet_ports'] ?? [], true)) {
                return back()->withInput()->withErrors(['pppoe_interface' => 'PPPoE must use a separate interface from the HotSpot client bridge.']);
            }

            $pppoeConfig = [
                'interface' => $validated['pppoe_interface'],
                'vlan_id' => isset($validated['pppoe_vlan_id']) ? (int) $validated['pppoe_vlan_id'] : null,
                'network' => $pppoeNetwork,
                'gateway' => $pppoeGateway,
                'dns' => $pppoeDns,
            ];
        }

        $hotspotConfig = $routerModel->hotspot_config ?? [];
        if ($validated['enable_hotspot']) {
            $network = (string) $validated['hotspot_network'];
            $gateway = (string) $validated['gateway_ip'];
            if (! $provisioning->isValidHotspotGateway($network, $gateway)) {
                return back()->withInput()->withErrors(['gateway_ip' => 'The gateway must be inside a valid hotspot network.']);
            }
            $hotspotConfig = [
                'network' => $network,
                'gateway' => $gateway,
                'dns' => implode(',', $dnsServers),
                'domain' => (string) $validated['hotspot_domain'],
                'management_subnet' => (string) config('wireguard.server_address').'/32',
                'wan_interface' => $wanInterface,
            ];
        }

        if ($routerModel->access_backend !== 'radius') {
            return back()->withInput()->withErrors([
                'provisioning' => 'Guided onboarding requires the RADIUS router backend; site-agent routers must be configured through their paired agent.',
            ]);
        }

        if (! $routerModel->hasUsableRadiusConfiguration()) {
            if (! RadiusReadiness::available()) {
                return back()->withInput()->withErrors([
                    'provisioning' => RadiusRouterCredentialService::UNAVAILABLE_MESSAGE,
                ]);
            }

            try {
                app(RadiusRouterCredentialService::class)->optIn(
                    $routerModel,
                    'router-'.$routerModel->id.'-tenant-'.$routerModel->tenant_id,
                );
            } catch (RadiusUnavailableException $exception) {
                return back()->withInput()->withErrors(['provisioning' => $exception->getMessage()]);
            } catch (\LogicException $exception) {
                return back()->withInput()->withErrors(['provisioning' => $exception->getMessage()]);
            }
        }

        $routerModel->forceFill([
            'enable_hotspot' => (bool) $validated['enable_hotspot'],
            'enable_pppoe' => (bool) $validated['enable_pppoe'],
            'hotspot_config' => $hotspotConfig,
            'pppoe_config' => $pppoeConfig,
            'ethernet_ports' => array_values($validated['ethernet_ports'] ?? []),
        ])->saveOrFail();

        $result = $provisioning->deployGuided($routerModel->fresh(), (bool) $validated['confirm_dedicated_router']);
        if (! $result['ok']) {
            return back()->withInput()->withErrors(['provisioning' => $result['message']]);
        }

        $onboardingService->markCompleted($routerModel->onboarding);
        $routerModel->forceFill([
            'status' => 'online',
            'last_seen_at' => now(),
        ])->saveOrFail();

        return redirect()->route('operator.routers.show', [
            'tenant' => $context->getTenant()?->slug,
            'router' => $routerModel->id,
        ])->with('status', $result['message']);
    }

    public function show(TenantContext $context, int $router)
    {
        $tenant = $context->getTenant();
        $routerModel = Router::query()
            ->where('tenant_id', $context->getTenantId())
            ->with(['onboarding', 'vpnPeer'])
            ->findOrFail($router);
        $onboarding = $routerModel->onboarding;
        abort_unless($tenant && $onboarding, 404);

        return response()->view('operator.routers.onboarding', [
            'tenant' => $tenant,
            'router' => $routerModel,
            'onboarding' => $onboarding,
            'bootstrapCommand' => $onboarding->expires_at->isFuture()
                ? $onboarding->bootstrap_command
                : null,
        ])->withHeaders($this->noStoreHeaders());
    }

    public function status(
        TenantContext $context,
        int $router,
        WireGuardService $wireGuard,
        RouterConnectionService $connection,
        RouterOnboardingService $onboardingService,
    ): JsonResponse {
        $routerModel = Router::query()
            ->where('tenant_id', $context->getTenantId())
            ->with(['onboarding', 'vpnPeer'])
            ->findOrFail($router);
        $onboarding = $routerModel->onboarding;
        abort_unless($onboarding, 404);

        if ($onboarding->status === 'connected') {
            return response()->json(['status' => 'connected', 'message' => 'Router connection verified.']);
        }
        if ($onboarding->expires_at->isPast()) {
            return response()->json(['status' => 'expired', 'message' => 'The setup command expired. Restart onboarding to issue a fresh command.']);
        }

        try {
            $wireGuard->refreshHandshakes();
        } catch (RuntimeException $exception) {
            Log::warning('Router onboarding could not refresh WireGuard handshake state.', [
                'router_id' => $routerModel->id,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'status' => 'waiting',
                'message' => 'The WireGuard hub is unavailable. Check the hub interface and try again.',
            ]);
        }

        $routerModel->load('vpnPeer');
        if (! $routerModel->vpnPeer?->last_handshake_at
            || $routerModel->vpnPeer->last_handshake_at->lt(now()->subMinutes(2))) {
            return response()->json([
                'status' => 'waiting',
                'message' => 'Paste the provisioning command into the MikroTik terminal. Waiting for the router tunnel.',
            ]);
        }

        $result = $connection->testOnboardingWireGuard($routerModel);
        if (! $result['ok']) {
            return response()->json([
                'status' => 'connecting',
                'message' => $result['message'],
            ]);
        }

        $onboardingService->markConnected($onboarding);
        $routerModel->forceFill([
            'status' => 'online',
            'last_seen_at' => now(),
        ])->saveOrFail();

        return response()->json([
            'status' => 'connected',
            'message' => 'Router connection verified. Continue to service setup.',
            'next_url' => route('operator.routers.onboarding.services', [
                'tenant' => $routerModel->tenant->slug,
                'router' => $routerModel->id,
            ]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function noStoreHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, no-cache, max-age=0, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }

    /**
     * @return array{network: string, gateway: string, dns: string}
     */
    private function pppoeDefaults(): array
    {
        $dnsServers = PlatformSetting::get(
            'pppoe.default_dns',
            config('services.mikrotik.pppoe_dns_servers'),
        );

        return [
            'network' => (string) PlatformSetting::get(
                'pppoe.default_network',
                config('services.mikrotik.pppoe_network'),
            ),
            'gateway' => (string) PlatformSetting::get(
                'pppoe.default_gateway',
                config('services.mikrotik.pppoe_gateway'),
            ),
            'dns' => is_array($dnsServers)
                ? implode(',', array_map('strval', $dnsServers))
                : (string) $dnsServers,
        ];
    }
}
