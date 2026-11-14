<?php

namespace App\Http\Controllers\Operator;

use App\Exceptions\RefundException;
use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\Refund;
use App\Models\Transaction;
use App\Services\RefundService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    public function index(TenantContext $context, RefundService $refunds)
    {
        $tenant = $context->getTenant();
        abort_unless($tenant, 404, 'Tenant not found');
        $canRequestRefund = (bool) PlatformSetting::get('refunds.operator_can_request', true);

        $requests = Refund::with('requester')->latest()->paginate(15);

        $sales = $canRequestRefund ? Transaction::where('type', 'voucher_sale')
            ->where('status', 'completed')
            ->latest()
            ->limit(25)
            ->get()
            ->each(fn (Transaction $sale) => $sale->setAttribute('refundable', $refunds->refundable($sale)))
            ->filter(fn (Transaction $sale) => $sale->refundable > 0)
            ->values() : collect();

        return view('operator.refunds.index', [
            'tenant' => $tenant,
            'requests' => $requests,
            'sales' => $sales,
            'currency' => PlatformSetting::get('currency', 'UGX'),
            'canRequestRefund' => $canRequestRefund,
            'requireRefundNote' => (bool) PlatformSetting::get('refunds.require_note', true),
        ]);
    }

    public function store(Request $request, RefundService $refunds)
    {
        abort_unless(PlatformSetting::get('refunds.operator_can_request', true), 403, 'Refund requests are disabled by the platform.');
        $requireNote = (bool) PlatformSetting::get('refunds.require_note', true);
        $validated = $request->validate([
            'transaction_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => array_values(array_filter([
                $requireNote ? 'required' : 'nullable',
                'string',
                $requireNote ? 'min:10' : null,
                'max:500',
            ])),
            'revoke_access' => ['sometimes', 'boolean'],
        ]);

        // Tenant-scoped lookup: another operator's sale simply does not exist here.
        $sale = Transaction::where('type', 'voucher_sale')->find($validated['transaction_id']);
        abort_unless($sale, 404);

        try {
            $refunds->request(
                $sale,
                (float) $validated['amount'],
                trim((string) ($validated['reason'] ?? '')) ?: 'No reason provided',
                $request->user(),
                $request->boolean('revoke_access', true),
            );
        } catch (RefundException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Refund request sent to the platform for approval.');
    }

    public function cancel(Request $request, RefundService $refunds, string $tenant, int $refund)
    {
        $model = Refund::findOrFail($refund);

        try {
            $refunds->cancel($model, $request->user());
        } catch (RefundException $exception) {
            return back()->withErrors(['error' => $exception->getMessage()]);
        }

        return back()->with('status', 'Refund request cancelled.');
    }
}
