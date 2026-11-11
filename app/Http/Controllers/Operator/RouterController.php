<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Router;
use App\Models\Session;
use App\Models\Site;
use App\Models\SiteAgentCommand;
use App\Services\RouterConnectionService;
use App\Services\RouterProvisioningService;
use App\Services\SiteAgentCommandService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RouterController extends Controller
{
    /**
     * Display a listing of routers with KPIs, alert banner, and filters.
     */
    public function index(Request $request, TenantContext $context)
    {
        $tenant = $context->getTenant();
        $tenantId = $context->getTenantId();

        $query = Router::where('tenant_id', $tenantId);

        // Search Filter
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('management_ip', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%")
                    ->orWhere('hardware_model', 'like', "%{$search}%")
                    ->orWhere('site', 'like', "%{$search}%")
                    ->orWhereHas('assignedSite', fn ($siteQuery) => $siteQuery->where('name', 'like', "%{$search}%"));
            });
        }

        // Site Filter
        if ($siteId = $request->integer('site_id')) {
            $query->where('site_id', $siteId);
        } elseif ($site = $request->input('site')) {
            if ($site !== 'all') {
                $query->where('site', $site);
            }
        }

        // Status Filter
        if ($status = $request->input('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        // Sort by name or custom sort
        $routers = $query->with('assignedSite')->orderBy('name')->paginate(10)->withQueryString();

        // Calculate KPIs strictly across all routers for this tenant
        $allRouters = Router::where('tenant_id', $tenantId)->get();
        $totalRouters = $allRouters->count();
        $onlineRouters = $allRouters->where('status', 'online')->count();
        $offlineRouters = $allRouters->where('status', 'offline')->count();
        $avgCpu = $allRouters->where('status', 'online')->avg('cpu_usage') ?? 0;

        // Find unreachable router for warning banner if any is offline
        $unreachableRouter = $allRouters->firstWhere('status', 'offline');

        // Distinct sites for dropdown filter (strictly for this tenant)
        $sites = Router::where('tenant_id', $tenantId)->whereNotNull('site')->distinct()->pluck('site');
        $siteRecords = Site::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name']);

        return view('operator.routers.index', compact(
            'tenant',
            'routers',
            'totalRouters',
            'onlineRouters',
            'offlineRouters',
            'avgCpu',
            'unreachableRouter',
            'sites',
            'siteRecords',
        ));
    }

    /**
     * Show the 3-step router onboarding wizard.
     */
    public function create(TenantContext $context)
    {
        $tenant = $context->getTenant();
        $tenantId = $context->getTenantId();
        abort_unless($tenant, 404, 'Tenant not found');
        $nextNumber = Router::where('tenant_id', $tenantId)->count() + 1;
        $defaultName = "MikroTik-{$nextNumber}";
        $sites = Site::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name']);

        return view('operator.routers.create', compact('tenant', 'defaultName', 'sites'));
    }

    /**
     * Store a newly created router in storage.
     */
    public function store(Request $request, TenantContext $context)
    {
        $tenantId = $context->getTenantId();
        abort_unless($context->getTenant(), 404, 'Tenant not found');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'site' => ['nullable', 'string', 'max:255'],
            'site_id' => [
                'nullable',
                'integer',
                Rule::exists('sites', 'id')->where('tenant_id', $tenantId),
            ],
            'hardware_model' => ['nullable', 'string', 'max:255'],
            'management_ip' => ['nullable', 'string', 'max:255'],
            'management_subnet' => [
                'nullable',
                'string',
                'max:18',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $parts = explode('/', $value, 2);
                    $prefix = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : (isset($parts[1]) ? -1 : 32);
                    if (filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $prefix < 8 || $prefix > 32) {
                        $fail('The management subnet must be a valid IPv4 address or CIDR no broader than an IPv4 /8.');
                    }
                },
            ],
            'api_username' => ['nullable', 'string', 'max:100'],
            'api_password' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n\x00]/'],
            'api_port' => ['nullable', 'integer', 'between:1,65535'],
            'has_wireless' => ['nullable', 'boolean'],
            'ethernet_ports' => ['nullable', 'array'],
            'enable_hotspot' => ['nullable', 'boolean'],
            'hotspot_network' => [
                'nullable',
                'string',
                'max:50',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $parts = explode('/', $value, 2);
                    $prefix = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : (isset($parts[1]) ? -1 : 32);
                    if (filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $prefix < 8 || $prefix > 30) {
                        $fail('The hotspot network must be a valid IPv4 CIDR with usable host addresses.');
                    }
                },
            ],
            'gateway_ip' => [
                'nullable',
                'ipv4',
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    if (! $value) {
                        return;
                    }

                    $network = (string) $request->input('hotspot_network', '10.10.0.0/16');
                    $parts = explode('/', $network, 2);
                    $prefix = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : -1;
                    if ($prefix < 8 || $prefix > 30 || filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                        return;
                    }

                    $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
                    $networkAddress = (int) sprintf('%u', ip2long($parts[0]));
                    $gatewayAddress = (int) sprintf('%u', ip2long($value));
                    if (($networkAddress & $mask) !== $networkAddress || ($gatewayAddress & $mask) !== $networkAddress) {
                        $fail('The gateway must be an IPv4 host inside the configured hotspot network.');
                    }
                },
            ],
            'dns_servers' => [
                'nullable',
                'string',
                'max:100',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    foreach (explode(',', $value) as $server) {
                        if (filter_var(trim($server), FILTER_VALIDATE_IP) === false) {
                            $fail('DNS servers must be comma-separated IP addresses.');

                            return;
                        }
                    }
                },
            ],
            'hotspot_domain' => ['nullable', 'string', 'max:100', 'regex:/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\.(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?))*$/'],
        ]);

        $site = isset($validated['site_id'])
            ? Site::where('tenant_id', $tenantId)->findOrFail($validated['site_id'])
            : null;

        $apiPassword = ! empty($validated['api_password'])
            ? $validated['api_password']
            : Str::password(16);

        $apiUsername = ! empty($validated['api_username'])
            ? $validated['api_username']
            : 'billing_api';

        $router = Router::create([
            'tenant_id' => $tenantId,
            'site_id' => $site?->id,
            'name' => $validated['name'],
            'site' => $site?->name ?? ($validated['site'] ?? null),
            'hardware_model' => $validated['hardware_model'] ?? 'hEX / RB750Gr3',
            'management_ip' => $validated['management_ip'] ?? null,
            'status' => 'provisioning',
            'cpu_usage' => 0,
            'memory_usage' => 0,
            'active_users' => 0,
            'last_seen_at' => null,
            'api_username' => $apiUsername,
            'api_password' => $apiPassword,
            'api_port' => $validated['api_port'] ?? 8728,
            'has_wireless' => $request->boolean('has_wireless'),
            'enable_hotspot' => $request->boolean('enable_hotspot', true),
            'hotspot_config' => [
                'network' => $validated['hotspot_network'] ?? '10.10.0.0/16',
                'gateway' => $validated['gateway_ip'] ?? '10.10.0.1',
                'dns' => $validated['dns_servers'] ?? '8.8.8.8,8.8.4.4',
                'domain' => $validated['hotspot_domain'] ?? 'hotspot.local',
                'management_subnet' => $validated['management_subnet'] ?? null,
            ],
            'ethernet_ports' => $request->input('ethernet_ports', ['ether2', 'ether3']),
        ]);

        AuditLog::record('router.created', $router, ['name' => $router->name, 'site_id' => $router->site_id]);

        return redirect()->route('operator.routers.show', $router)
            ->with('status', "Router '{$router->name}' added successfully.");
    }

    /**
     * Display the specified router detail page (telemetry, RADIUS, hardware, uptime).
     */
    public function show(Request $request, RouterConnectionService $connectionService, ...$args)
    {
        $context = app(TenantContext::class);
        $tenant = $context->getTenant();
        $router = $this->resolveTenantRouter($args, $context);
        $router->loadMissing('vpnPeer');
        abort_unless($tenant, 404, 'Tenant not found');
        $assignedSite = $router->site_id
            ? Site::withoutTenancy()
                ->where('tenant_id', $router->tenant_id)
                ->find($router->site_id)
            : null;
        $liveData = $connectionService->liveStatus($router);
        $agentLastReportedAt = $assignedSite?->agent?->last_seen_at;
        if (config('site_agent.required')) {
            $healthStatus = $router->status === 'online'
                && $router->last_seen_at?->greaterThanOrEqualTo(now()->subMinutes(2))
                    ? 'agent confirmed'
                    : ($router->status === 'provisioning' ? 'provisioning' : ($router->status === 'offline' ? 'offline' : 'last known'));
        } else {
            $healthStatus = $liveData['ok']
                ? 'online'
                : ($router->status === 'provisioning' ? 'provisioning' : ($router->last_seen_at ? 'last known' : 'offline'));
        }
        $hasApiCredentials = (filled($router->management_ip) || $router->vpnPeer?->enabled)
            && filled($router->api_username)
            && filled($router->api_password);
        $latestConnectionTest = SiteAgentCommand::query()
            ->where('router_id', $router->id)
            ->whereNull('session_id')
            ->where('operation', 'test_connection')
            ->latest('id')
            ->first();

        return view('operator.routers.show', compact(
            'tenant',
            'router',
            'assignedSite',
            'liveData',
            'agentLastReportedAt',
            'healthStatus',
            'hasApiCredentials',
            'latestConnectionTest',
        ));
    }

    /**
     * Remove the specified router.
     */
    public function destroy(Request $request, ...$args)
    {
        $context = app(TenantContext::class);
        $router = $this->resolveTenantRouter($args, $context);
        $hasActiveSessions = Session::where('router_id', $router->id)
            ->where('status', 'active')
            ->exists();

        if ($hasActiveSessions) {
            return back()->withErrors([
                'router' => 'Disconnect or expire active sessions before removing this router.',
            ]);
        }

        $name = $router->name;
        AuditLog::record('router.deleted', $router, ['name' => $name]);
        $router->delete();

        return redirect()->route('operator.routers.index')
            ->with('status', "Router '{$name}' removed successfully.");
    }

    /**
     * Ping / Test connectivity for a specific router.
     */
    public function ping(Request $request, RouterConnectionService $connectionService, ...$args)
    {
        $context = app(TenantContext::class);
        $router = $this->resolveTenantRouter($args, $context);

        $startedAt = microtime(true);
        $result = $connectionService->test($router);
        $latency = (int) round((microtime(true) - $startedAt) * 1000);
        if ($result['ok']) {
            $router->update(['status' => 'online', 'last_seen_at' => now()]);
        }
        $message = $result['ok']
            ? "Router '{$router->name}' responded to the RouterOS API check."
            : "Router '{$router->name}' could not be checked: {$result['message']}";

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => $result['ok'],
                'message' => $message,
                'status' => $result['ok'] ? 'online' : $router->status,
                'latency' => $result['ok'] ? $latency : null,
                'last_seen' => $result['ok'] ? 'Just now' : null,
            ], $result['ok'] ? 200 : 503);
        }

        return back()->with($result['ok'] ? 'status' : 'error', $message);
    }

    public function testConnection(
        Request $request,
        RouterConnectionService $connectionService,
        SiteAgentCommandService $commandService,
        ...$args,
    ) {
        $context = app(TenantContext::class);
        $router = $this->resolveTenantRouter($args, $context);

        if (config('site_agent.required')) {
            try {
                $command = $commandService->testConnection($router);
            } catch (\Throwable $exception) {
                report($exception);

                return response()->json([
                    'ok' => false,
                    'message' => 'A paired site agent is required to test this router, but no usable agent is available.',
                ], 422);
            }

            return response()->json([
                'ok' => true,
                'queued' => true,
                'command_id' => $command->id,
                'message' => 'Router API test queued for the paired site agent. The router status will update when the agent reports back.',
            ], 202);
        }

        $result = $connectionService->test($router);
        if ($result['ok']) {
            $router->update(['status' => 'online', 'last_seen_at' => now()]);
        }

        $statusCode = $result['ok']
            ? 200
            : (str_contains($result['message'] ?? '', 'before testing') ? 422 : 503);

        return response()->json($result, $statusCode);
    }

    public function telemetry(Request $request, SiteAgentCommandService $commandService, ...$args)
    {
        $context = app(TenantContext::class);
        $router = $this->resolveTenantRouter($args, $context);

        if (! config('site_agent.required')) {
            return response()->json([
                'ok' => false,
                'message' => 'Router telemetry is collected through the paired site agent only.',
            ], 409);
        }

        try {
            $command = $commandService->telemetry($router);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'message' => 'A paired site agent is required to collect router telemetry.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'queued' => true,
            'command_id' => $command->id,
            'message' => 'Telemetry requested through the paired site agent. Metrics update when the agent reports back.',
        ], 202);
    }

    /**
     * Download or view the RouterOS provisioning configuration script.
     */
    public function provisionScript(Request $request, RouterProvisioningService $provisioningService, ...$args)
    {
        $context = app(TenantContext::class);
        $router = $this->resolveTenantRouter($args, $context);
        $script = $provisioningService->generateScript($router);

        if ($request->query('download') || $request->is('*/download')) {
            $filename = 'mikrotik-provision-'.$router->id.'.rsc';

            return response($script, 200, [
                'Content-Type' => 'text/plain',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'router_id' => $router->id,
                'script' => $script,
            ]);
        }

        return response($script, 200, ['Content-Type' => 'text/plain']);
    }

    /**
     * Deploy hotspot profile and walled garden configuration directly to router via API.
     */
    public function provision(Request $request, RouterProvisioningService $provisioningService, ...$args)
    {
        $context = app(TenantContext::class);
        $router = $this->resolveTenantRouter($args, $context);

        $result = $provisioningService->deploy($router);

        if ($result['ok']) {
            AuditLog::record('router.updated', $router, [
                'action' => 'provisioned_via_api',
                'profiles_synced' => $result['profiles_synced'] ?? 0,
            ]);
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json($result, $result['ok'] ? 200 : 422);
        }

        return back()->with($result['ok'] ? 'status' : 'error', $result['message']);
    }

    /**
     * Reboot a specific router.
     */
    public function reboot(Request $request, ...$args)
    {
        $this->resolveTenantRouter($args, app(TenantContext::class));

        return $this->unsupportedRouterOperation($request, 'Reboot is unavailable until the site agent supports reboot commands.');
    }

    /**
     * Sync configuration and package HotSpot profiles with a router.
     */
    public function sync(Request $request, ...$args)
    {
        $this->resolveTenantRouter($args, app(TenantContext::class));

        return $this->unsupportedRouterOperation($request, 'Router synchronization is unavailable until the site agent supports sync commands.');
    }

    /**
     * Ping / Refresh health check for all routers of the current tenant.
     */
    public function pingAll(Request $request, TenantContext $context, RouterConnectionService $connectionService)
    {
        $tenantId = $context->getTenantId();
        $routers = Router::where('tenant_id', $tenantId)->get();
        $responded = 0;

        foreach ($routers as $router) {
            $result = $connectionService->test($router);
            if ($result['ok']) {
                $router->update(['status' => 'online', 'last_seen_at' => now()]);
                $responded++;
            }
        }

        $message = "Router API health check completed: {$responded} of {$routers->count()} router(s) responded.";

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $message,
            ]);
        }

        return back()->with('status', $message);
    }

    /**
     * Execute a bulk action on selected routers of the current tenant.
     */
    public function bulkAction(Request $request, TenantContext $context)
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:ping,reboot,sync,delete'],
            'router_ids' => ['required', 'array'],
            'router_ids.*' => ['integer'],
        ]);

        $tenantId = $context->getTenantId();

        // Strictly query ONLY routers belonging to this tenant
        $routers = Router::where('tenant_id', $tenantId)
            ->whereIn('id', $validated['router_ids'])
            ->get();

        if ($routers->isEmpty()) {
            return back()->withErrors(['bulk' => 'No matching routers found for this tenant.']);
        }

        $action = $validated['action'];
        $count = $routers->count();

        switch ($action) {
            case 'ping':
            case 'reboot':
            case 'sync':
                return back()->withErrors([
                    'bulk' => "Bulk {$action} is unavailable until the site agent supports this operation.",
                ]);

            case 'delete':
                $hasActiveSessions = Session::whereIn('router_id', $routers->modelKeys())
                    ->where('status', 'active')
                    ->exists();
                if ($hasActiveSessions) {
                    return back()->withErrors([
                        'bulk' => 'Disconnect or expire active sessions before removing routers.',
                    ]);
                }
                foreach ($routers as $router) {
                    AuditLog::record('router.deleted', $router, ['name' => $router->name, 'bulk' => true]);
                    $router->delete();
                }
                $message = "Successfully removed {$count} router(s) from your workspace.";
                break;
        }

        return back()->with('status', $message);
    }

    /**
     * Resolve and verify that the requested router belongs strictly to the current tenant.
     */
    /**
     * Set the hotspot gateway customers are sent to after paying or redeeming.
     */
    public function updateGateway(Request $request, TenantContext $context, ...$args)
    {
        $router = $this->resolveTenantRouter($args, $context);

        $validated = $request->validate([
            'gateway_ip' => ['required', 'ipv4'],
        ]);

        $config = $router->hotspot_config ?? [];
        $previous = $config['gateway'] ?? null;
        $config['gateway'] = $validated['gateway_ip'];
        $router->update(['hotspot_config' => $config]);

        AuditLog::record('router.gateway_changed', $router, [
            'from' => $previous,
            'to' => $validated['gateway_ip'],
        ]);

        return back()->with('status', 'Hotspot gateway updated.');
    }

    protected function resolveTenantRouter(array $args, TenantContext $context): Router
    {
        $tenantId = $context->getTenantId();

        $router = collect($args)->first(fn ($a) => $a instanceof Router);
        if (! $router) {
            $routerId = collect($args)->last();
            $router = Router::where('tenant_id', $tenantId)->findOrFail($routerId);
        } else {
            // Strictly enforce tenant isolation:
            if ($context->hasTenant() && $router->tenant_id !== $tenantId) {
                abort(404, 'Router not found in this tenant.');
            }
        }

        return $router;
    }

    private function unsupportedRouterOperation(Request $request, string $message)
    {
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => false, 'message' => $message], 501);
        }

        return back()->withErrors(['router' => $message]);
    }
}
