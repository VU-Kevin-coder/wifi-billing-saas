<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SsoNonce;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'subdomain' => [
                'required', 'string', 'alpha_dash', 'max:50', 'unique:tenants,slug',
                Rule::notIn(Tenant::reservedSlugs()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($request) {
            // 1. Create the Tenant
            $tenant = Tenant::create([
                'name' => $request->business_name,
                'slug' => Str::slug($request->subdomain),
                'status' => 'active',
            ]);

            // 2. Create the User and link to Tenant
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            // 3. Assign the Operator Admin role (create if not yet seeded)
            \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'operator_admin', 'guard_name' => 'web']);
            $user->assignRole('operator_admin');

            return $user;
        });

        event(new Registered($user));
        Auth::login($user);

        // Redirect to their new subdomain via a signed, single-use SSO transfer.
        $tenant = $user->tenant;
        $token = SsoNonce::issue($user, $tenant);
        $redirectUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'operator.sso.verify',
            now()->addMinutes(2),
            ['tenant' => $tenant->slug, 'user' => $user->id, 'token' => $token]
        );

        return redirect()->to($redirectUrl);
    }
}
