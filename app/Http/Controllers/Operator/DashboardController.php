<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $context)
    {
        $tenant = $context->getTenant();
        $userCount = $tenant->users()->count();

        return view('operator.dashboard', [
            'tenant' => $tenant,
            'userCount' => $userCount,
        ]);
    }
}
