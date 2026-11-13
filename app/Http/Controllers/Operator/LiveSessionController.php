<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BlockedDevice;
use App\Models\Router;
use App\Models\Session;
use App\Models\Site;
use App\Models\SiteAgentCommand;
use App\Services\AccessRevoker;
use App\Services\SiteAgentCommandService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class LiveSessionController extends Controller
{
    public function index(Request $request, TenantContext $context)
    {
        $tenant = $context->getTenant();
        $tenantId = $context->getTenantId();

        $query = Session::with(['customer', 'package', 'router', 'router.assignedSite.agent', 'voucher', 'site.agent'])
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
        $sessionIds = $activeSessions->getCollection()->pluck('id');
        $deviceBanCommands = SiteAgentCommand::query()
            ->whereIn('session_id', $sessionIds)
            ->where('operation', 'ban_mac')
            ->latest('id')
            ->get()
            ->unique('session_id')
            ->keyBy('session_id');
        $blockedDevices = BlockedDevice::query()
            ->with(['router', 'session'])
            ->where('tenant_id', $tenantId)
            ->latest()
            ->limit(100)
            ->get();

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
            'deviceBanCommands',
            'blockedDevices',
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
        $session->loadMissing(['voucher', 'router.assignedSite.agent', 'site.agent']);

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
                'message' => 'Voucher revocation is already waiting for router confirmation.',
                'site_agent_online' => $this->siteAgentOnline($session),
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
        $siteAgentOnline = $this->siteAgentOnline($session);

        return response()->json([
            'success' => true,
            'status' => 'pending',
            'message' => $queued
                ? 'Voucher voided. Router access will be removed when the site agent confirms the command.'
                : 'Voucher voided, but the router command could not be queued. Revocation will be retried; verify the site agent.',
            'site_agent_online' => $siteAgentOnline,
        ], 202);
    }

    public function banDevice(
        Request $request,
        string $tenant,
        Session $session,
        TenantContext $context,
        SiteAgentCommandService $commands,
    ) {
        $tenantId = $context->getTenantId();
        abort_unless($session->tenant_id === $tenantId, 404);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $session->loadMissing(['voucher', 'router.assignedSite.agent', 'site.agent']);
        if (! $session->voucher || ! $session->router || ! in_array($session->status, ['active', 'provisioning'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'A device ban requires an active voucher session assigned to a router.',
            ], 409);
        }

        $pending = BlockedDevice::query()
            ->where('tenant_id', $tenantId)
            ->where('router_id', $session->router_id)
            ->where('session_id', $session->id)
            ->whereIn('status', ['pending', 'blocked', 'unban_pending'])
            ->first();
        if ($pending) {
            return response()->json([
                'success' => false,
                'message' => 'A device ban or unban is already recorded for this session.',
            ], 409);
        }

        $blockedDevice = BlockedDevice::create([
            'tenant_id' => $tenantId,
            'router_id' => $session->router_id,
            'session_id' => $session->id,
            'mac_address' => null,
            'status' => 'pending',
            'reason' => $validated['reason'],
        ]);
        $session->update([
            'status' => 'disconnecting',
            'pending_device_ban' => true,
        ]);
        AuditLog::record('device.mac_block_requested', $session, [
            'blocked_device_id' => $blockedDevice->id,
            'router_id' => $session->router_id,
            'reason' => $validated['reason'],
        ]);

        $queued = false;
        try {
            $commands->banDevice($session->router, $session, $session->voucher, $blockedDevice);
            $queued = true;
        } catch (Throwable $exception) {
            Log::warning('Unable to queue a router-verified MAC block.', [
                'session_id' => $session->id,
                'blocked_device_id' => $blockedDevice->id,
                'exception' => $exception::class,
            ]);
        }

        return response()->json([
            'success' => true,
            'status' => 'pending',
            'message' => $queued
                ? 'Device ban queued. The router must identify and confirm the device before the session is shown as disconnected.'
                : 'Device ban is pending because no usable site agent is available. It will be retried when one is paired.',
            'site_agent_online' => $this->siteAgentOnline($session),
        ], 202);
    }

    public function unbanDevice(
        Request $request,
        string $tenant,
        BlockedDevice $blockedDevice,
        TenantContext $context,
        SiteAgentCommandService $commands,
    ) {
        abort_unless($blockedDevice->tenant_id === $context->getTenantId(), 404);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        if (! in_array($blockedDevice->status, ['blocked', 'unban_pending', 'unban_failed'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This device is not currently blocked.',
            ], 409);
        }

        $blockedDevice->loadMissing('router.assignedSite.agent');
        $queued = false;
        try {
            $commands->unbanDevice($blockedDevice);
            $queued = true;
            $blockedDevice->update(['status' => 'unban_pending', 'last_error' => null]);
        } catch (Throwable $exception) {
            Log::warning('Unable to queue a router MAC unblock.', [
                'blocked_device_id' => $blockedDevice->id,
                'router_id' => $blockedDevice->router_id,
                'exception' => $exception::class,
            ]);
            $blockedDevice->update([
                'status' => 'unban_pending',
                'last_error' => 'Waiting for a paired site agent.',
            ]);
        }

        AuditLog::record('device.mac_unblock_requested', $blockedDevice, [
            'mac_address' => $blockedDevice->mac_address,
            'router_id' => $blockedDevice->router_id,
            'reason' => $validated['reason'],
        ]);

        $agent = $blockedDevice->router?->assignedSite?->agent;
        $agentOnline = $agent && ! $agent->revoked_at
            && $agent->last_seen_at?->greaterThanOrEqualTo(now()->subMinutes(2));

        return response()->json([
            'success' => true,
            'status' => 'pending',
            'message' => $queued
                ? 'Unban queued. The router will allow this MAC again after the site agent confirms.'
                : 'Unban is pending because no usable site agent is available. It will be retried when one is paired.',
            'site_agent_online' => (bool) $agentOnline,
        ], 202);
    }

    private function siteAgentOnline(Session $session): bool
    {
        $agent = $session->site?->agent ?? $session->router?->assignedSite?->agent;

        return (bool) ($agent && ! $agent->revoked_at
            && $agent->last_seen_at?->greaterThanOrEqualTo(now()->subMinutes(2)));
    }
}
