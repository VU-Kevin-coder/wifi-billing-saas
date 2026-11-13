<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SsoNonce;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\RegistrationEmailCode;
use App\Notifications\WelcomeToHamnet;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Throwable;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        abort_unless(PlatformSetting::get('tenant.registration_open', true), 403, 'Workspace registration is currently closed.');

        return view('auth.register');
    }

    public function sendEmailCode(Request $request): RedirectResponse
    {
        abort_unless(PlatformSetting::get('tenant.registration_open', true), 403, 'Workspace registration is currently closed.');

        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);

        $code = (string) random_int(100000, 999999);

        try {
            Notification::route('mail', $validated['email'])->notify(new RegistrationEmailCode($code));
        } catch (Throwable $exception) {
            Log::warning('Registration verification email could not be sent.', [
                'exception' => $exception::class,
            ]);

            return back()
                ->withErrors(['email' => 'We could not send the verification code. Check your email settings and try again.'])
                ->withInput($request->except(['password', 'password_confirmation', '_token']));
        }

        $key = $this->emailCodeCacheKey($validated['email']);
        Cache::put($key, Hash::make($code), now()->addMinutes(10));
        Cache::put($key.':attempts', 0, now()->addMinutes(10));

        return back()
            ->with('email_code_sent', 'A 6-digit verification code was sent to your email. It expires in 10 minutes.')
            ->withInput($request->except(['password', 'password_confirmation', '_token']));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(PlatformSetting::get('tenant.registration_open', true), 403, 'Workspace registration is currently closed.');

        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'subdomain' => [
                'required', 'string', 'alpha_dash', 'max:50', 'unique:tenants,slug',
                Rule::notIn(Tenant::reservedSlugs()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:'.User::class],
            'email_code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $key = $this->emailCodeCacheKey($validated['email']);
        $codeHash = Cache::get($key);
        $attemptKey = $key.':attempts';
        Cache::add($attemptKey, 0, now()->addMinutes(10));

        if ((int) Cache::get($attemptKey) >= 5) {
            throw ValidationException::withMessages([
                'email_code' => 'Too many incorrect codes. Request a new verification code.',
            ]);
        }

        if (! is_string($codeHash) || ! Hash::check($validated['email_code'], $codeHash)) {
            Cache::increment($attemptKey);
            throw ValidationException::withMessages([
                'email_code' => 'The verification code is invalid or expired. Request a new code and try again.',
            ]);
        }

        $user = DB::transaction(function () use ($validated) {
            // 1. Create the Tenant
            $tenant = Tenant::create([
                'name' => $validated['business_name'],
                'slug' => Str::slug($validated['subdomain']),
                'status' => PlatformSetting::get('tenant.default_status', 'active'),
            ]);

            // 2. Create the User and link to Tenant
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            // 3. Assign the Operator Admin role (create if not yet seeded)
            Role::firstOrCreate(['name' => 'operator_admin', 'guard_name' => 'web']);
            $user->assignRole('operator_admin');

            return $user;
        });

        Cache::forget($key);
        Cache::forget($attemptKey);
        event(new Registered($user));
        Auth::login($user);

        // Redirect to their new subdomain via a signed, single-use SSO transfer.
        $tenant = $user->tenant;
        $welcomeEmailSent = true;
        try {
            $user->notify(new WelcomeToHamnet($tenant));
        } catch (Throwable $exception) {
            $welcomeEmailSent = false;
            Log::error('Welcome email could not be sent after registration.', [
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
            ]);
        }

        $token = SsoNonce::issue($user, $tenant);
        $redirectUrl = URL::temporarySignedRoute(
            'operator.sso.verify',
            now()->addMinutes(2),
            ['tenant' => $tenant->slug, 'user' => $user->id, 'token' => $token]
        );

        $response = redirect()->to($redirectUrl);

        return $welcomeEmailSent
            ? $response
            : $response->with('email_delivery_warning', 'Your account was created, but the welcome email could not be sent. Please contact support if needed.');
    }

    private function emailCodeCacheKey(string $email): string
    {
        return 'registration-email-code:'.hash('sha256', $email);
    }
}
