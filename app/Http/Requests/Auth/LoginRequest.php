<?php

namespace App\Http\Requests\Auth;

use App\Services\Policy\Policy;
use App\Models\PlatformSetting;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            $this->hit();

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        if (Auth::user()?->deactivated_at) {
            Auth::logout();
            $this->hit();

            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Contact your administrator.',
            ]);
        }

        if (PlatformSetting::get('app.maintenance', false)
            && ! Auth::user()?->hasRole('platform_admin')) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => PlatformSetting::get('app.maintenance_msg', 'The platform is temporarily unavailable for maintenance.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $maxAttempts = (int) Policy::get('security.login_throttle_attempts', 6);
        $maxIpAttempts = (int) Policy::get('security.login_throttle_ip_attempts', 5);
        $limitedKeys = [];

        if (RateLimiter::tooManyAttempts($this->throttleKey(), $maxAttempts)) {
            $limitedKeys[] = $this->throttleKey();
        }

        if (RateLimiter::tooManyAttempts($this->ipThrottleKey(), $maxIpAttempts)) {
            $limitedKeys[] = $this->ipThrottleKey();
        }

        if ($limitedKeys === []) {
            return;
        }

        event(new Lockout($this));

        $seconds = max(array_map(
            static fn (string $key): int => RateLimiter::availableIn($key),
            $limitedKeys,
        ));

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    private function ipThrottleKey(): string
    {
        return 'login:ip:'.$this->ip();
    }

    public function hit(): void
    {
        $decaySeconds = (int) Policy::get('security.login_throttle_decay', 1) * 60;
        RateLimiter::hit($this->throttleKey(), $decaySeconds);
        RateLimiter::hit($this->ipThrottleKey(), $decaySeconds);
    }
}
