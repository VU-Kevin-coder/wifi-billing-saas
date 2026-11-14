<?php

namespace App\Http\Controllers\Platform;

use App\Exceptions\RefundException;
use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\Refund;
use App\Services\RefundService;
use Illuminate\Http\Request;

/**
 * Platform admins have no tenant context, so every query here opts out of
 * TenantScope explicitly (and relations are eager-loaded the same way).
 */
class RefundController extends Controller
{
    public function index()
    {
        $with = [
            'tenant',
            'transaction' => fn ($query) => $query->withoutTenancy(),
            'requester' => fn ($query) => $query->withoutTenancy(),
            'decider' => fn ($query) => $query->withoutTenancy(),
        ];

        return view('platform.refunds.index', [
            'pending' => Refund::withoutTenancy()->with($with)->where('status', 'pending')->oldest()->get(),
            'recent' => Refund::withoutTenancy()->with($with)->where('status', '!=', 'pending')->latest('decided_at')->limit(25)->get(),
            'currency' => PlatformSetting::get('currency', 'UGX'),
            'decisionSlaHours' => max(1, (int) PlatformSetting::get('refunds.payout_sla', 48)),
        ]);
    }

    public function approve(Request $request, RefundService $refunds, int $refund)
    {
        $validated = $request->validate([
            'payout_reference' => ['required', 'string', 'min:3', 'max:100'],
        ]);

        $model = Refund::withoutTenancy()->findOrFail($refund);

        try {
            $refunds->approve($model, $request->user(), $validated['payout_reference']);
        } catch (RefundException $exception) {
            return back()->withErrors(['error' => $exception->getMessage()]);
        }

        return back()->with('status', 'Refund approved. The operator wallet was debited and access was revoked.');
    }

    public function reject(Request $request, RefundService $refunds, int $refund)
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $model = Refund::withoutTenancy()->findOrFail($refund);

        try {
            $refunds->reject($model, $request->user(), $validated['note'] ?? null);
        } catch (RefundException $exception) {
            return back()->withErrors(['error' => $exception->getMessage()]);
        }

        return back()->with('status', 'Refund request rejected.');
    }
}
