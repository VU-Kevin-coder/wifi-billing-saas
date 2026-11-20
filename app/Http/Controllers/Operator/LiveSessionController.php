<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\Session;
use App\Models\Site;
use App\Services\AccessRevoker;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

class LiveSessionController extends Controller
{
    public function index(Request $request, TenantContext $context)
    {
        $tenant = $context->getTenant();
        $tenantId = $context->getTenantId();

        $query = Session::with(['customer', 'package', 'router', 'voucher', 'site'])
            ->where('tenant_id', $tenantId)
            ->where(function ($query): void {
                $query->where(function ($activeQuery): void {
                    $activeQuery->whereIn('status', ['active', 'disconnecting'])
                        ->where('expires_at', '>', now());
                })->orWhere(function ($expiredQuery): void {
                    $expiredQuery->where('pending_revocation', true)
                        ->where('pending_revocation_status', 'expired');
                });
            });

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
            ->where('pending_revocation', false)
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
    public function disconnect(
        Request $request,
        string $tenant,
        Session $session,
        TenantContext $context,
        AccessRevoker $revoker,
    ) {
        $tenantId = $context->getTenantId();

        if ($session->tenant_id !== $tenantId) {
            return response()->json(['success' => false, 'message' => 'Session not found in this workspace.'], 403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $session->loadMissing(['voucher', 'router']);

        if (! $session->voucher || ! $session->router) {
            return response()->json([
                'success' => false,
                'message' => 'This session is not linked to a router voucher and cannot be revoked from this page.',
            ], 409);
        }

        if ($session->pending_revocation) {
            return response()->json([
                'success' => true,
                'status' => 'pending',
                'message' => 'Voucher revocation is already pending RADIUS confirmation.',
            ], 202);
        }

        if (! in_array($session->status, ['active', 'provisioning'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This session is no longer active.',
            ], 409);
        }

        $queued = $revoker->revoke(
            $session->voucher,
            'manual_disconnect: '.$validated['reason'],
            'disconnected',
        );
        $session->refresh();
        $usesRadius = $session->router?->access_backend === 'radius';

        return response()->json([
            'success' => true,
            'status' => 'pending',
            'message' => $usesRadius
                ? ($queued
                    ? 'Voucher voided. RADIUS disconnect is pending accounting confirmation.'
                    : 'Voucher voided, but a RADIUS disconnect could not be confirmed. Revocation remains pending for reconciliation.')
                : 'Voucher voided, but this router is not configured for RADIUS. Revocation remains pending for operator review.',
        ], 202);
    }
}
