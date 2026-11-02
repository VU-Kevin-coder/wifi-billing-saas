<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\PlatformPlan;
use App\Services\Tenancy\TenantContext;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function index(TenantContext $context): View
    {
        $tenant = $context->getTenant();

        abort_unless($tenant, 404, 'Tenant not found');

        // Eager-load the plan relationship so the view can render live plan details
        $tenant->loadMissing('plan');

        // All available plans for the "Explore plans" overlay
        $plans = PlatformPlan::orderBy('price')->get();

        return view('operator.billing.index', compact('tenant', 'plans'));
    }
}
