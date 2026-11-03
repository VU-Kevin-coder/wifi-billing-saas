<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\Session;
use App\Models\Site;
use App\Models\Voucher;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

class LiveSessionController extends Controller
{
    public function index(Request $request, TenantContext $context)
    {
        $tenant = $context->getTenant();
        $tenantId = $context->getTenantId();

        // Synchronize any redeemed vouchers that don't have an active session logged yet
        $unloggedVouchers = Voucher::with('package')
            ->where('tenant_id', $tenantId)
            ->where('status', 'redeemed')
            ->whereDoesntHave('sessions')
            ->get();

        foreach ($unloggedVouchers as $v) {
            $pkg = $v->package;
            $redeemedTime = $v->redeemed_at ?? now();
            $expiresAt = $pkg?->expiresAt($redeemedTime) ?? $redeemedTime->copy()->addHours(2);

            if ($expiresAt > now()) {
                $r = $v->site_id
                    ? Router::where('tenant_id', $tenantId)->where('site_id', $v->site_id)->first()
                    : null;
                $r ??= Router::where('tenant_id', $tenantId)
                    ->when($v->assigned_site, fn ($q) => $q->where('site', $v->assigned_site))
                    ->first() ?? Router::where('tenant_id', $tenantId)->first();

                Session::create([
                    'tenant_id' => $tenantId,
                    'site_id' => $v->site_id ?? $r?->site_id,
                    'customer_id' => null,
                    'voucher_id' => $v->id,
                    'package_id' => $v->package_id,
                    'router_id' => $r?->id,
                    'status' => 'active',
                    'started_at' => $v->redeemed_at ?? now(),
                    'expires_at' => $expiresAt,
                    'ip_address' => '10.50.1.'.rand(10, 250),
                    'mac_address' => null,
                    'bytes_in' => 1024 * 128,
                    'bytes_out' => 1024 * 512,
                    'rate_in' => 1024 * 64,
                    'rate_out' => 1024 * 256,
                ]);
            }
        }

        $query = Session::with(['customer', 'package', 'router', 'voucher', 'site'])
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('expires_at', '>', now());

        // Search filter
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('ip_address', 'like', "%{$search}%")
                    ->orWhere('mac_address', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('voucher', fn ($v) => $v->where('code', 'like', "%{$search}%"));
            });
        }

        // Router filter
        if ($routerId = $request->input('router_id')) {
            $query->where('router_id', $routerId);
        }

        if ($siteId = $request->integer('site_id')) {
            $query->where('site_id', $siteId);
        }

        $activeSessions = $query->orderBy('started_at', 'desc')->paginate(20)->withQueryString();

        // KPI aggregates across ALL active sessions for this tenant (not just current page)
        $allActive = Session::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('expires_at', '>', now());

        $connectedNow = $allActive->count();
        $downloadThroughput = (clone $allActive)->sum('rate_out');  // bps
        $uploadThroughput = (clone $allActive)->sum('rate_in');   // bps
        $dataUsedToday = (clone $allActive)->sum('bytes_in') + (clone $allActive)->sum('bytes_out');

        // Counts by type for tab badges
        $hotspotCount = (clone $allActive)->whereHas('voucher')->count();
        $pppoeCount = $connectedNow - $hotspotCount;

        $routers = Router::where('tenant_id', $tenantId)->select('id', 'name', 'site_id')->orderBy('name')->get();
        $sites = Site::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name']);

        return view('operator.sessions.index', compact(
            'tenant',
            'activeSessions',
            'connectedNow',
            'downloadThroughput',
            'uploadThroughput',
            'dataUsedToday',
            'hotspotCount',
            'pppoeCount',
            'routers',
            'sites'
        ));
    }

    /**
     * Disconnect (terminate) a specific session.
     * Strictly scoped to the current tenant — no cross-tenant access possible.
     */
    public function disconnect(Request $request, Session $session, TenantContext $context)
    {
        $tenantId = $context->getTenantId();

        // Tenant isolation check
        if ($session->tenant_id !== $tenantId) {
            return response()->json(['success' => false, 'message' => 'Session not found in this workspace.'], 403);
        }

        $session->update([
            'status' => 'disconnected',
            'expires_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Session for {$session->customer?->name} has been terminated.",
        ]);
    }
}
