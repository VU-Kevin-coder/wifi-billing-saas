<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Jobs\VerifyRadiusReadinessJob;
use App\Models\AuditLog;
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
use LogicException;
use RuntimeException;

class RouterOnboardingController extends Controller
{
    public function retryRadiusReadiness(TenantContext $context, string $tenant, int $router)
    {
        abort_unless($context->getTenant()?->slug === $tenant, 404);
        $routerModel = Router::query()
            ->where('tenant_id', $context->getTenantId())
            ->with('onboarding')
            ->findOrFail($router);
        abort_unless(
            ($routerModel->pending_radius_activation || $routerModel->onboarding?->status === 'api_verified')
                && ! $routerModel->radius_activation_paused_at,
            404,
        );

        VerifyRadiusReadinessJob::dispatch((int) $routerModel->id);

        return back()->with('status', 'RADIUS readiness check queued. The current access backend remains active.');
    }

    public function services(
        TenantContext $context,
        string $tenant,
        int $router,
        RouterConnectionService $connection,
    ) {
        abort_unless($context->getTenant()?->slug === $tenant, 404);
        $tenant = $context->getTenant();
        $routerModel = Router::query()
            ->where('tenant_id', $context->getTenantId())
            ->with(['onboarding', 'vpnPeer'])
            ->findOrFail($router);
        abort_unless($tenant
            && in_array($routerModel->onboarding?->status, ['radius_ready', 'complete'], true), 404);
        abort_unless($routerModel->enrolled, 404);

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
            )
            ->values()
            ->all();
        $pppoeDefaults = $this->pppoeDefaults();

        return response()
            ->view('operator.routers.onboarding-services', [
                'tenant' => $tenant,
                'router' => $routerModel,
                'interfaces' => $interfaces,
                'availablePorts' => $availablePorts,
                'wanInterface' => $wanInterface,
                'managementInterface' => $routerModel->hotspot_config['management_interface'] ?? '',
                'pppoeNetwork' => $pppoeDefaults['network'],
                'pppoeGateway' => $pppoeDefaults['gateway'],
                'pppoeDnsServers' => $pppoeDefaults['dns'],
            ])
            ->withHeaders($this->noStoreHeaders());
    }

    public function configure(
        Request $request,
        TenantContext $context,
        string $tenant,
        int $router,
        RouterConnectionService $connection,
        RouterProvisioningService $provisioning,
        RouterOnboardingService $onboardingService,
    ) {
        abort_unless($context->getTenant()?->slug === $tenant, 404);
        $routerModel = Router::query()
            ->where('tenant_id', $context->getTenantId())
            ->with(['onboarding', 'vpnPeer'])
            ->findOrFail($router);
        abort_unless(in_array($routerModel->onboarding?->status, ['radius_ready', 'complete'], true), 404);
        abort_unless($routerModel->enrolled, 404);
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
            )
            ->values()
            ->all();
        $validated = $request->validate([
            'wan_interface' => ['required', 'string', Rule::in($availablePorts)],
            'management_interface' => ['required', 'string', Rule::in($availablePorts)],
            'enable_hotspot' => ['required', 'boolean'],
            'enable_pppoe' => ['required', 'boolean'],
            'hotspot_network' => ['required_if:enable_hotspot,1', 'nullable', 'string', 'max:50'],
            'gateway_ip' => ['required_if:enable_hotspot,1', 'nullable', 'ipv4'],
            'dns_servers' => ['required_if:enable_hotspot,1', 'nullable', 'string', 'max:100'],
            'hotspot_domain' => ['required_if:enable_hotspot,1', 'nullable', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\.(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?))*$/'],
            'ethernet_ports' => ['required_if:enable_hotspot,1', 'array', 'min:1'],
            'ethernet_ports.*' => ['string', Rule::in($availablePorts)],
            'pppoe_interface' => ['required_if:enable_pppoe,1', 'nullable', 'string', Rule::in($availablePorts)],
            'pppoe_vlan_id' => ['nullable', 'integer', 'between:1,4094'],
            'confirm_dedicated_router' => ['accepted'],
        ]);
        $wanInterface = $validated['wan_interface'];
        $managementInterface = $validated['management_interface'];
        $selectedInterfaces = array_merge(
            [$wanInterface, $managementInterface],
            $validated['enable_hotspot'] ? ($validated['ethernet_ports'] ?? []) : [],
            $validated['enable_pppoe'] ? [$validated['pppoe_interface']] : [],
        );
        if (count($selectedInterfaces) !== count(array_unique($selectedInterfaces))) {
            return back()->withInput()->withErrors([
                'management_interface' => 'WAN, management, HotSpot, and PPPoE roles must use separate interfaces.',
            ]);
        }

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

        $hotspotConfig = array_merge($routerModel->hotspot_config ?? [], [
            'wan_interface' => $wanInterface,
            'management_interface' => $managementInterface,
        ]);
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
                'wan_interface' => $wanInterface,
                'management_interface' => $managementInterface,
            ];
        }

        if ($routerModel->access_backend !== 'radius' && ! $routerModel->pending_radius_activation) {
            return back()->withInput()->withErrors([
                'provisioning' => 'Guided onboarding requires a pending or active RADIUS configuration.',
            ]);
        }
        if ($routerModel->pending_radius_activation && $routerModel->radius_readiness_status !== 'ready') {
            return back()->withInput()->withErrors([
                'provisioning' => $routerModel->radius_readiness_status === 'blocked'
                    ? 'RADIUS readiness is blocked. The current access backend remains active; resolve the RADIUS health check before enabling router services.'
                    : 'RADIUS readiness is still being checked. The current access backend remains active.',
            ]);
        }

        if (! $routerModel->hasUsableRadiusConfiguration()) {
            if (! RadiusReadiness::available()) {
                return back()->withInput()->withErrors([
                    'provisioning' => RadiusRouterCredentialService::UNAVAILABLE_MESSAGE,
                ]);
            }

            try {
                app(RadiusRouterCredentialService::class)->preparePendingActivation(
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
        ]);

        $result = $provisioning->deployGuided($routerModel, (bool) $validated['confirm_dedicated_router']);
        if (! $result['ok']) {
            AuditLog::record('router.provisioning_blocked', $routerModel, [
                'message' => $result['message'],
                'backup_name' => $result['backup_name'] ?? null,
            ]);

            return back()->withInput()->withErrors(['provisioning' => $result['message']]);
        }

        $routerModel->saveOrFail();
        AuditLog::record('router.provisioned', $routerModel, [
            'wan_interface' => $wanInterface,
            'management_interface' => $managementInterface,
            'hotspot_interfaces' => array_values($validated['ethernet_ports'] ?? []),
            'pppoe_interface' => $pppoeConfig['interface'] ?? null,
            'backup_name' => $result['backup_name'] ?? null,
        ]);
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

    public function show(TenantContext $context, string $tenant, int $router)
    {
        abort_unless($context->getTenant()?->slug === $tenant, 404);
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
                && $onboarding->status === 'awaiting_bootstrap'
                ? $onboarding->bootstrap_command
                : null,
        ])->withHeaders($this->noStoreHeaders());
    }

    public function reissue(
        Request $request,
        TenantContext $context,
        string $tenant,
        int $router,
        RouterOnboardingService $onboardingService,
    ) {
        $request->validate([
            'confirm_router_reset' => ['required', 'accepted'],
        ]);

        abort_unless($context->getTenant()?->slug === $tenant, 404);
        $routerModel = Router::query()
            ->where('tenant_id', $context->getTenantId())
            ->with(['onboarding', 'vpnPeer'])
            ->findOrFail($router);

        try {
            $onboardingService->reissue($routerModel);
        } catch (LogicException|RuntimeException $exception) {
            return back()->withErrors(['onboarding' => $exception->getMessage()]);
        }

        AuditLog::record('router.onboarding.reissued', $routerModel, [
            'previous_status' => $routerModel->onboarding?->status,
        ]);

        return redirect()->route('operator.routers.onboarding', [
            'tenant' => $tenant,
            'router' => $routerModel->id,
        ])->with('status', 'A fresh onboarding command is ready. Use only this new command on the reset router.');
    }

    public function status(
        TenantContext $context,
        string $tenant,
        int $router,
        WireGuardService $wireGuard,
        RouterConnectionService $connection,
        RouterOnboardingService $onboardingService,
    ): JsonResponse {
        abort_unless($context->getTenant()?->slug === $tenant, 404);
        $routerModel = Router::query()
            ->where('tenant_id', $context->getTenantId())
            ->with(['onboarding', 'vpnPeer'])
            ->findOrFail($router);
        $onboarding = $routerModel->onboarding;
        abort_unless($onboarding, 404);

        if ($onboarding->status === 'complete') {
            return response()->json(['status' => 'complete', 'message' => 'Router onboarding is complete.']);
        }
        if ($onboarding->status === 'radius_ready') {
            return response()->json([
                'status' => 'radius_ready',
                'message' => 'WireGuard, API-SSL, and RADIUS readiness are verified.',
                'next_url' => route('operator.routers.onboarding.services', [
                    'tenant' => $routerModel->tenant->slug,
                    'router' => $routerModel->id,
                ]),
            ]);
        }
        if ($onboarding->status === 'api_verified') {
            return response()->json([
                'status' => 'api_verified',
                'message' => 'Router API-SSL is verified. Waiting for RADIUS readiness checks.',
            ]);
        }
        if ($onboarding->status === 'awaiting_bootstrap' && $onboarding->expires_at->isPast()) {
            return response()->json(['status' => 'expired', 'message' => 'The setup command expired. Restart onboarding to issue a fresh command.']);
        }
        if (! $routerModel->enrolled) {
            return response()->json([
                'status' => 'waiting',
                'message' => 'Waiting for the router to complete its verified identity enrollment callback.',
            ]);
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
                'status' => $onboarding->status,
                'message' => 'Run the three RouterOS commands in order. Waiting for the router tunnel.',
            ]);
        }

        $onboardingService->markTunnelVerified($onboarding);
        $result = $connection->testOnboardingWireGuard($routerModel);
        if (! $result['ok']) {
            return response()->json([
                'status' => 'tunnel_verified',
                'message' => $result['message'],
            ]);
        }

        $apiVerified = $onboardingService->markApiVerified($onboarding);
        $routerModel->forceFill([
            'status' => 'online',
            'last_seen_at' => now(),
        ])->saveOrFail();

        if ($apiVerified) {
            VerifyRadiusReadinessJob::dispatch((int) $routerModel->id);
        }

        $onboarding->refresh();
        if ($onboarding->status === 'radius_ready') {
            return response()->json([
                'status' => 'radius_ready',
                'message' => 'WireGuard, API-SSL, and RADIUS readiness are verified.',
                'next_url' => route('operator.routers.onboarding.services', [
                    'tenant' => $routerModel->tenant->slug,
                    'router' => $routerModel->id,
                ]),
            ]);
        }

        return response()->json([
            'status' => 'api_verified',
            'message' => 'Router API-SSL is verified. Waiting for RADIUS readiness checks.',
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
