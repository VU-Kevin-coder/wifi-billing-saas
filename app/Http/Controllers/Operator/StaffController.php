<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

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

        // The account is created with a random, unknown password and an
        // unverified email. The new staff member sets their own password via
        // an emailed reset link (invite), so no shared default ever exists.
        $user = DB::transaction(function () use ($tenant, $validated) {
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make(Str::random(64)),
            ]);

            $user->assignRole($validated['role']);

            return $user;
        });

        AuditLog::record('staff.created', $user, [
            'email' => $user->email,
            'role' => $validated['role'],
        ], auth()->user(), $tenant);

        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            return redirect()->route('operator.team.index')
                ->with('status', 'Staff member added, but the invitation email could not be sent. '
                    .'Ask them to use "Forgot password" on the login page to set a password.');
        }

        return redirect()->route('operator.team.index')
            ->with('status', 'Staff member added. An email was sent so they can set their own password.');
    }
}
