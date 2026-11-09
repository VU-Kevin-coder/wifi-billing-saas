<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Router;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Takes a voucher out of circulation and removes the customer's connection.
 * Used when an operator voids a voucher and when a sale is refunded in full.
 */
class AccessRevoker
{
    public function __construct(
        private readonly SiteAgentCommandService $commands,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  string  $reason  recorded in the audit log, e.g. "manual" or "refund"
     */
    public function revoke(Voucher $voucher, string $reason): void
    {
        $voucher->update(['status' => 'voided']);
        AuditLog::record('voucher.voided', $voucher, ['reason' => $reason]);

        $session = Session::withoutTenancy()
            ->where('tenant_id', $voucher->tenant_id)
            ->where('voucher_id', $voucher->id)
            ->whereIn('status', ['active', 'provisioning'])
            ->latest('id')
            ->first();

        if (! $session) {
            return;
        }

        $queued = false;

        try {
            $router = $session->router_id
                ? Router::withoutTenancy()->where('tenant_id', $voucher->tenant_id)->find($session->router_id)
                : null;
            $tenant = Tenant::find($voucher->tenant_id);

            if ($router && $tenant) {
                $this->context->runAs($tenant, fn () => $this->commands->expire($router, $session, $voucher));
                $queued = true; // the site agent marks the session expired once the router user is removed
            }
        } catch (Throwable $exception) {
            Log::error('Unable to queue the removal of a revoked voucher from the router.', [
                'voucher_id' => $voucher->id,
                'exception' => $exception::class,
            ]);
        }

        if (! $queued) {
            $session->update(['status' => 'expired']); // nothing on a router to remove (or none reachable)
        }
    }
}
