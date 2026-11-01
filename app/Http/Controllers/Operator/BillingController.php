<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function index(TenantContext $context): View
    {
        $tenant = $context->getTenant();

        abort_unless($tenant, 404, 'Tenant not found');

        return view('operator.billing.index', compact('tenant'));
    }
}
