<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function index(TenantContext $context): View
    {
        $tenant = $context->getTenant();

        abort_unless($tenant, 404, 'Tenant not found');

        $users = $tenant->users()
            ->with('roles.permissions')
            ->orderBy('name')
            ->get();

        $roles = $users->flatMap(fn ($user) => $user->roles)
            ->unique('id')
            ->sortBy('name')
            ->values();

        $roleMemberCounts = $roles->mapWithKeys(fn ($role) => [
            $role->id => $users->filter(fn ($user) => $user->roles->contains('id', $role->id))->count(),
        ]);

        return view('operator.team.index', compact('tenant', 'users', 'roles', 'roleMemberCounts'));
    }
}
