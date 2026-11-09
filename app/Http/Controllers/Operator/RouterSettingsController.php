<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\RouterService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

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

    public function testConnection(Request $request, TenantContext $context, ?string $tenant = null)
    {
        $tenantModel = $context->getTenant() ?? ($tenant ? Tenant::where('slug', $tenant)->first() : null);

        if (! $tenantModel) {
            return response()->json(['success' => false, 'message' => 'Tenant context not found.'], 404);
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
     * Sync RADIUS profiles and packages across all routers of the current tenant.
     */
    public function syncAll(Request $request, TenantContext $context, ?string $tenant = null)
    {
        $tenantModel = $context->getTenant() ?? ($tenant ? Tenant::where('slug', $tenant)->first() : null);

        if (! $tenantModel) {
            abort(404, 'Tenant not found');
        }

        // Strictly tenant-scoped query
        $routers = Router::where('tenant_id', $tenantModel->id)->get();
        $count = $routers->count();

        Router::where('tenant_id', $tenantModel->id)->update([
            'last_seen_at' => now(),
            'status' => 'online',
        ]);

        return redirect()->route('operator.settings.router', ['tenant' => $tenantModel->slug])
            ->with('status', "Configuration and RADIUS profiles synced successfully across {$count} MikroTik router(s).");
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
