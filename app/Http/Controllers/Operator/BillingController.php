<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\PlatformPlan;
use App\Models\PlatformSetting;
use App\Services\Subscriptions\SubscriptionBilling;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function index(TenantContext $context, SubscriptionBilling $billing): View
    {
        $tenant = $context->getTenant();

        abort_unless($tenant, 404, 'Tenant not found');

        // Eager-load the plan relationship so the view can render live plan details
        $tenant->loadMissing('plan');

        // All available plans for the "Explore plans" overlay
        $plans = PlatformPlan::orderBy('price')->get();
        $currency = strtoupper((string) PlatformSetting::get('currency', 'UGX'));

        $invoices = DB::table('subscription_invoices as i')
            ->leftJoin('subscription_attempts as a', 'a.subscription_invoice_id', '=', 'i.id')
            ->where('i.tenant_id', $tenant->id)->orderByDesc('i.id')
            ->select('i.*', 'a.id as attempt_id', 'a.reference', 'a.status as attempt_status')->paginate(20);
        $history = DB::table('subscription_history')->where('tenant_id', $tenant->id)->orderByDesc('id')->paginate(20, ['*'], 'history_page');
        $hasUnresolvedInvoice = DB::table('subscription_invoices')->where('tenant_id', $tenant->id)->whereIn('status', ['open', 'review'])->exists();
        $checkoutAvailable = $billing->available();
        $requestKey = (string) Str::uuid();
        $quote = $checkoutAvailable ? $billing->quote($tenant, $requestKey) : null;

        return view('operator.billing.index', compact('tenant', 'plans', 'currency', 'invoices', 'history', 'hasUnresolvedInvoice', 'checkoutAvailable', 'requestKey', 'quote'));
    }

    public function start(Request $request, TenantContext $context, SubscriptionBilling $billing)
    {
        $tenant = $context->getTenant();
        abort_unless($tenant && $request->user()->tenant_id === $tenant->id, 403);
        abort_unless($billing->available(), 503, 'Subscription checkout is not available.');
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'quote' => ['required', 'string', 'max:4096'],
            'confirm_quote' => ['accepted'],
            'phone' => ['required', 'string', 'regex:/^\\+256[0-9]{9}$/'],
        ]);
        $billing->start($tenant, $data['idempotency_key'], $data['phone'], $data['quote']);

        return redirect()->route('operator.billing.index')->with('status', 'Request recorded. Payment is not confirmed until verified; do not submit another payment.');
    }

    public function reconcile(Request $request, TenantContext $context, SubscriptionBilling $billing)
    {
        $tenant = $context->getTenant();
        abort_unless($tenant && $request->user()->tenant_id === $tenant->id, 403);
        $billing->reconcile($tenant, (int) $request->route('attempt'));

        return redirect()->route('operator.billing.index')->with('status', 'Status checked. Pending or unknown payments require reconciliation.');
    }
}
