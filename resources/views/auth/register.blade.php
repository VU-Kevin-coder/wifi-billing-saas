<x-layouts.auth
    title="Your Wi-Fi business, always in your control."
    subtitle="Create an account to manage your routers, customers, and revenue from one secure workspace."
>
    <div class="head">
        <h1>Create an account</h1>
        <p>Start free — no credit card required.</p>
    </div>

    @if (session('email_code_sent'))
        <div class="auth-status" role="status">{{ session('email_code_sent') }}</div>
    @endif

    <form method="POST" action="{{ route('register') }}" class="auth-form">
        @csrf

        <div class="field">
            <label for="business_name">Business name</label>
            <div class="input">
                <i class="bi bi-shop lead"></i>
                <input id="business_name" type="text" name="business_name" value="{{ old('business_name') }}"
                    @error('business_name') aria-invalid="true" aria-describedby="business_name-error" @enderror
                    class="@error('business_name') is-invalid @enderror" required autofocus placeholder="e.g. Kampala Connect">
            </div>
            @error('business_name')
                <p id="business_name-error" class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="field">
            <label for="subdomain">Portal subdomain</label>
            <div class="input subdomain-input">
                <input id="subdomain" type="text" name="subdomain" value="{{ old('subdomain') }}"
                    @error('subdomain') aria-invalid="true" aria-describedby="subdomain-error" @enderror
                    class="plain @error('subdomain') is-invalid @enderror" required placeholder="acme">
                <span class="subdomain-suffix">.{{ \App\Models\Tenant::domainSuffix() }}</span>
            </div>
            @error('subdomain')
                <p id="subdomain-error" class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="field">
            <label for="name">Your full name</label>
            <div class="input">
                <i class="bi bi-person lead"></i>
                <input id="name" type="text" name="name" value="{{ old('name') }}"
                    @error('name') aria-invalid="true" aria-describedby="name-error" @enderror
                    class="@error('name') is-invalid @enderror" required placeholder="Jane Doe">
            </div>
            @error('name')
                <p id="name-error" class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="field">
            <label for="email">Email address</label>
            <div class="email-input-row">
                <div class="input">
                    <i class="bi bi-envelope lead"></i>
                    <input id="email" type="email" name="email" value="{{ old('email') }}"
                        @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                        class="@error('email') is-invalid @enderror" required autocomplete="email"
                        placeholder="operator@domain.com">
                </div>
                <button type="submit" formmethod="POST" formaction="{{ route('register.email-code') }}" formnovalidate
                    class="btn btn-ghost verify-email">
                    Verify email
                </button>
            </div>
            @error('email')
                <p id="email-error" class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="field">
            <label for="email_code">Email verification code</label>
            <div class="input">
                <i class="bi bi-shield-check lead"></i>
                <input id="email_code" type="text" name="email_code" value="{{ old('email_code') }}"
                    @error('email_code') aria-invalid="true" aria-describedby="email_code-error" @enderror
                    class="@error('email_code') is-invalid @enderror" required inputmode="numeric"
                    autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="6-digit code">
            </div>
            @error('email_code')
                <p id="email_code-error" class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="field">
            <label for="password">Password</label>
            <div class="input">
                <i class="bi bi-lock lead"></i>
                <input id="password" type="password" name="password"
                    @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                    class="has-eye @error('password') is-invalid @enderror" required autocomplete="new-password"
                    placeholder="Create a password">
                <button type="button" class="eye" data-eye="password" aria-label="Show password">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
            @error('password')
                <p id="password-error" class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="field">
            <label for="password_confirmation">Confirm password</label>
            <div class="input">
                <i class="bi bi-lock-fill lead"></i>
                <input id="password_confirmation" type="password" name="password_confirmation" required
                    autocomplete="new-password" placeholder="Enter your password again">
            </div>
        </div>

        <button class="btn btn-brand" type="submit">
            <span>Get Started Free</span>
            <i class="bi bi-arrow-right btn-ic"></i>
        </button>
    </form>

    <p class="alt">Already have an account? <a href="{{ route('login') }}" class="link">Sign in</a></p>
</x-layouts.auth>
