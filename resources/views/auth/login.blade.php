<x-layouts.auth
    title="Your Wi-Fi business, always in your control."
    subtitle="Sign in to manage your routers, customers, and revenue from one secure workspace."
>
    <div class="head">
        <h1>Welcome back</h1>
        <p>Log in to manage your network.</p>
    </div>

    <x-auth-session-status class="auth-status" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="auth-form">
        @csrf

        <div class="field">
            <label for="email">Email address</label>
            <div class="input">
                <i class="bi bi-envelope lead"></i>
                <input id="email" type="email" name="email" value="{{ old('email') }}"
                    @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                    class="@error('email') is-invalid @enderror" required autofocus autocomplete="username"
                    placeholder="operator@domain.com">
            </div>
            @error('email')
                <p id="email-error" class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="field">
            <div class="lbl-row">
                <label for="password">Password</label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="link">Forgot?</a>
                @endif
            </div>
            <div class="input">
                <i class="bi bi-lock lead"></i>
                <input id="password" type="password" name="password"
                    @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                    class="has-eye @error('password') is-invalid @enderror" required autocomplete="current-password"
                    placeholder="Your password">
                <button type="button" class="eye" data-eye="password" aria-label="Show password">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
            @error('password')
                <p id="password-error" class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <label class="check">
            <input id="remember_me" type="checkbox" name="remember" @checked(old('remember'))>
            Remember this device
        </label>

        <button class="btn btn-brand" type="submit">
            <span>Sign In</span>
            <i class="bi bi-arrow-right btn-ic"></i>
        </button>
    </form>

    <p class="alt">Don't have an account? <a href="{{ route('register') }}" class="link">Sign up</a></p>
</x-layouts.auth>
