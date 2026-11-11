<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\VpnPeer;
use App\Services\WireGuardService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class VpnPeerController extends Controller
{
    public function index(WireGuardService $wireGuard)
    {
        $refreshError = null;
        if (config('wireguard.enabled')) {
            try {
                $wireGuard->refreshHandshakes();
            } catch (RuntimeException $exception) {
                $refreshError = $exception->getMessage();
            }
        }

        return view('platform.vpn-peers.index', [
            'peers' => VpnPeer::query()->with(['tenant:id,name,slug', 'router:id,name,status'])->latest()->get(),
            'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name']),
            'routers' => Router::withoutGlobalScopes()->with('tenant:id,name')->orderBy('name')->get(['id', 'tenant_id', 'name']),
            'refreshError' => $refreshError,
        ]);
    }

    public function store(Request $request, WireGuardService $wireGuard)
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', Rule::exists('tenants', 'id')],
            'router_id' => [
                'required',
                'integer',
                Rule::exists('routers', 'id')->where('tenant_id', $request->integer('tenant_id')),
            ],
            'name' => ['required', 'string', 'max:100', 'not_regex:/[\r\n\x00]/'],
        ]);
        $router = Router::withoutGlobalScopes()
            ->where('tenant_id', $validated['tenant_id'])
            ->findOrFail($validated['router_id']);

        try {
            $peer = $wireGuard->createPeer($router, $validated['name']);
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['wireguard' => $exception->getMessage()]);
        }

        return redirect()->route('platform.vpn-peers.index')
            ->with('status', "WireGuard peer created for {$router->name}. Download its one-time router configuration now.");
    }

    public function download(int $peer, WireGuardService $wireGuard)
    {
        $vpnPeer = VpnPeer::query()->findOrFail($peer);
        abort_if($vpnPeer->config_downloaded_at, 410, 'This one-time configuration was already downloaded.');

        try {
            $configuration = $wireGuard->routerConfiguration($vpnPeer);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['wireguard' => $exception->getMessage()]);
        }

        return response($configuration, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="router-'.$vpnPeer->router_id.'-wireguard.rsc"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    public function storeForRouter(Request $request, int $router, WireGuardService $wireGuard)
    {
        $context = app(\App\Services\Tenancy\TenantContext::class);
        $routerModel = Router::withoutGlobalScopes()
            ->where('tenant_id', $context->getTenantId())
            ->findOrFail($router);

        try {
            $peer = $wireGuard->createPeer($routerModel, $routerModel->name);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['wireguard' => $exception->getMessage()]);
        }

        return redirect()->route('operator.routers.show', [
            'tenant' => $routerModel->tenant->slug,
            'router' => $routerModel->id,
        ])->with('status', 'WireGuard peer created. Download the one-time RouterOS configuration below.');
    }

    public function downloadForRouter(int $router, WireGuardService $wireGuard)
    {
        $context = app(\App\Services\Tenancy\TenantContext::class);
        $routerModel = Router::withoutGlobalScopes()
            ->where('tenant_id', $context->getTenantId())
            ->findOrFail($router);
        $vpnPeer = $routerModel->vpnPeer()->firstOrFail();
        abort_if($vpnPeer->config_downloaded_at, 410, 'This one-time configuration was already downloaded.');

        try {
            $configuration = $wireGuard->routerConfiguration($vpnPeer);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['wireguard' => $exception->getMessage()]);
        }

        return response($configuration, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="router-'.$routerModel->id.'-wireguard.rsc"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
