<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class StaffController extends Controller
{
    public function index(TenantContext $context)
    {
        $tenant = $context->getTenant();

        if (! $tenant) {
            abort(403, 'Tenant context not set.');
        }

        // Retrieve only users belonging to the active tenant
        $staff = $tenant->users()->with('roles')->orderBy('name')->get();
        
        return view('operator.staff.index', compact('staff'));
    }

    public function create()
    {
        if (! auth()->user()->hasRole('operator_admin') && ! (auth()->user()->hasRole('platform_admin') && session()->has('impersonate_tenant'))) {
            abort(403, 'Only an Administrator can add staff.');
        }

        $allowedRoles = ['operator_admin', 'operator_finance', 'operator_support', 'operator_network'];
        $selectedRole = request()->query('role');
        $selectedRole = in_array($selectedRole, $allowedRoles, true) ? $selectedRole : '';

        return view('operator.staff.create', compact('selectedRole'));
    }

    public function store(Request $request, TenantContext $context)
    {
        $tenant = $context->getTenant();

        if (! $tenant) {
            abort(403, 'Tenant context not set.');
        }

        // Only operator_admin (or impersonating platform_admin) can create staff
        if (! auth()->user()->hasRole('operator_admin') && ! (auth()->user()->hasRole('platform_admin') && session()->has('impersonate_tenant'))) {
            abort(403, 'Only an Administrator can add staff.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', 'in:operator_admin,operator_finance,operator_support,operator_network'],
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make('password'), // Default password for demo
            'email_verified_at' => now(),
        ]);

        $user->assignRole($validated['role']);

        return redirect()->route('operator.team.index')
            ->with('status', 'Staff member added successfully. Default password is "password".');
    }
}
