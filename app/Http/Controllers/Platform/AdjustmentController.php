<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PendingAdjustment;
use App\Models\Tenant;
use App\Services\Policy\AdjustmentService;
use Illuminate\Http\Request;

class AdjustmentController extends Controller
{
    public function index()
    {
        $pending = PendingAdjustment::withoutTenancy()
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->with(['tenant', 'requester'])
            ->orderBy('created_at')
            ->get();

        $recent = PendingAdjustment::withoutTenancy()
            ->whereIn('status', ['approved', 'rejected', 'expired'])
            ->with(['tenant', 'requester', 'approver'])
            ->orderBy('decided_at', 'desc')
            ->limit(20)
            ->get();

        return view('platform.revenue.adjustments', compact('pending', 'recent'));
    }

    public function store(Request $request, Tenant $tenant, AdjustmentService $service)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric',
            'reason' => 'required|string|min:10|max:255',
        ]);

        if ((float) $validated['amount'] === 0.0) {
            return back()->withErrors(['amount' => 'The adjustment amount cannot be zero.']);
        }

        try {
            $result = $service->request(
                $tenant,
                (float) $validated['amount'],
                $validated['reason'],
                $request->user()
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        if ($result['status'] === 'blocked') {
            return back()->withErrors(['amount' => $result['message']]);
        }

        return back()->with('status', $result['message']);
    }

    public function approve(Request $request, int $adjustment, AdjustmentService $service)
    {
        $model = PendingAdjustment::withoutTenancy()->findOrFail($adjustment);

        $validated = $request->validate(['note' => 'nullable|string|max:255']);

        $result = $service->approve($model, $request->user(), $validated['note'] ?? null);

        return back()->with($result['ok'] ? 'status' : 'error', $result['message']);
    }

    public function reject(Request $request, int $adjustment, AdjustmentService $service)
    {
        $model = PendingAdjustment::withoutTenancy()->findOrFail($adjustment);

        $validated = $request->validate(['note' => 'required|string|min:5|max:255']);

        $result = $service->reject($model, $request->user(), $validated['note']);

        return back()->with($result['ok'] ? 'status' : 'error', $result['message']);
    }
}

