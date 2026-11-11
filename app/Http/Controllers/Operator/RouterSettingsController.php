<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\SiteAgentCommand;
use App\Models\Tenant;
use App\Services\RouterService;
use App\Services\SiteAgentCommandService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class RouterSettingsController extends Controller
{
    public function edit(Request $request, TenantContext $context, ?string $tenant = null)
    {
        $tenantModel = $context->getTenant() ?? ($tenant ? Tenant::where('slug', $tenant)->first() : null);

        if (! $tenantModel) {
            abort(404, 'Tenant not found');
        }

        return view('operator.settings.router', [
            'tenant' => $tenantModel,
            'routers' => Router::query()->where('tenant_id', $tenantModel->id)->orderBy('name')->get(),
            'routerSyncStatuses' => SiteAgentCommand::query()
                ->where('tenant_id', $tenantModel->id)
                ->whereNull('session_id')
                ->where('operation', 'sync_profile')
                ->latest('id')
                ->get()
                ->unique('router_id')
                ->keyBy('router_id'),
        ]);
    }

    public function update(Request $request, TenantContext $context, ?string $tenant = null)
    {
        $tenantModel = $context->getTenant() ?? ($tenant ? Tenant::where('slug', $tenant)->first() : null);

        if (! $tenantModel) {
            abort(404, 'Tenant not found');
        }

        $validated = $request->validate([
            'mikrotik_host' => ['nullable', 'string', 'max:255'],
            'mikrotik_username' => ['nullable', 'string', 'max:100'],
            'mikrotik_password' => ['nullable', 'string', 'max:255'],
            'mikrotik_port' => ['nullable', 'integer', 'between:1,65535'],
        ]);

        $updateData = [
            'mikrotik_host' => $validated['mikrotik_host'] ?? null,
            'mikrotik_username' => $validated['mikrotik_username'] ?? null,
            'mikrotik_port' => $validated['mikrotik_port'] ?? 8728,
        ];

        // Only update password if a new one was provided
        if (! empty($validated['mikrotik_password'])) {
            $updateData['mikrotik_password'] = $validated['mikrotik_password'];
        }

        $tenantModel->update($updateData);

        return redirect()->route('operator.settings.router', ['tenant' => $tenantModel->slug])
            ->with('status', 'MikroTik router connection settings saved successfully.');
    }

    public function testConnection(
        Request $request,
        TenantContext $context,
        SiteAgentCommandService $commandService,
        ?string $tenant = null,
    ) {
        $tenantModel = $context->getTenant() ?? ($tenant ? Tenant::where('slug', $tenant)->first() : null);

        if (! $tenantModel) {
            return response()->json(['success' => false, 'message' => 'Tenant context not found.'], 404);
        }

        if (config('site_agent.required')) {
            $routers = Router::withoutGlobalScopes()
                ->where('tenant_id', $tenantModel->id)
                ->orderBy('name')
                ->get();
            $results = [];
            foreach ($routers as $router) {
                try {
                    $command = $commandService->testConnection($router);
                    $results[] = [
                        'router' => $router->name,
                        'status' => $command->status,
                        'message' => 'Queued for the paired site agent.',
                    ];
                } catch (Throwable $exception) {
                    report($exception);
                    $results[] = [
                        'router' => $router->name,
                        'status' => 'failed',
                        'message' => 'No usable paired site agent is available.',
                    ];
                }
            }

            if ($results === []) {
                return response()->json([
                    'success' => false,
                    'message' => 'Add a router and pair its site agent before testing connectivity.',
                ], 409);
            }

            $queued = collect($results)->contains(fn (array $result): bool => $result['status'] !== 'failed');

            return response()->json([
                'success' => $queued,
                'queued' => $queued,
                'results' => $results,
                'message' => $queued
                    ? 'Router API checks were queued through the paired site agents.'
                    : 'No router API checks could be queued. Pair a site agent with each router first.',
            ], $queued ? 202 : 503);
        }

        if (! config('site_agent.allow_direct_router_connection')) {
            return response()->json([
                'success' => false,
                'message' => 'Legacy cloud-to-router tests are disabled. Use the per-router test through its paired site agent.',
            ], 409);
        }

        if (empty($tenantModel->mikrotik_host)) {
            return response()->json([
                'success' => false,
                'message' => 'Please enter and save your router IP/Host address first.',
            ], 422);
        }

        $routerService = new RouterService($tenantModel);
        $connected = $routerService->testConnection();

        if ($connected) {
            return response()->json([
                'success' => true,
                'message' => 'Connection established successfully! Router responded to API query.',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Unable to connect to router. Check IP address, API service port (8728/8729), and user credentials.',
        ], 500);
    }

    /**
     * Queue package HotSpot profile synchronization for each router assigned to this tenant.
     */
    public function syncAll(Request $request, TenantContext $context, SiteAgentCommandService $commandService, ?string $tenant = null)
    {
        $tenantModel = $context->getTenant() ?? ($tenant ? Tenant::where('slug', $tenant)->first() : null);

        if (! $tenantModel) {
            abort(404, 'Tenant not found');
        }

        $routers = Router::where('tenant_id', $tenantModel->id)->get();
        $results = [];
        foreach ($routers as $router) {
            try {
                $command = $commandService->syncProfiles($router);
                $results[] = [
                    'router' => $router->name,
                    'status' => $command->status,
                    'message' => 'Profile sync command queued for the paired site agent.',
                ];
            } catch (Throwable $exception) {
                Log::warning('Unable to queue router profile sync', [
                    'router_id' => $router->id,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
                $results[] = [
                    'router' => $router->name,
                    'status' => 'failed',
                    'message' => 'No usable paired site agent is available for this router.',
                ];
            }
        }

        $queued = collect($results)->whereIn('status', ['pending', 'claimed'])->count();
        $failed = count($results) - $queued;

        return redirect()->route('operator.settings.router', ['tenant' => $tenantModel->slug])
            ->with('status', "Profile sync queued for {$queued} of ".count($results)." router(s); {$failed} could not be queued.")
            ->with('router_sync_results', $results);
    }

    /**
     * Clear MikroTik router configuration for this tenant.
     */
    public function disconnect(Request $request, TenantContext $context, ?string $tenant = null)
    {
        $tenantModel = $context->getTenant() ?? ($tenant ? Tenant::where('slug', $tenant)->first() : null);

        if (! $tenantModel) {
            abort(404, 'Tenant not found');
        }

        $tenantModel->update([
            'mikrotik_host' => null,
            'mikrotik_username' => null,
            'mikrotik_password' => null,
            'mikrotik_port' => 8728,
        ]);

        return redirect()->route('operator.settings.router', ['tenant' => $tenantModel->slug])
            ->with('status', 'MikroTik router disconnected and configuration cleared for this workspace.');
    }
}
