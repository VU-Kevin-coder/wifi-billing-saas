<x-layouts.auth
    title="Your account security matters."
    subtitle="Verify it’s you to continue securely."
>
    <div class="head mfa-screen-item">
        <div class="badge-ic" aria-hidden="true"><i class="bi bi-shield-lock"></i></div>
        <p class="mfa-step-label">{{ $stepLabel }}</p>
        <h1 id="mfa-title">{{ $pageTitle }}</h1>
        <p>Enter a verification code to confirm it’s you.</p>
    </div>

    @if (session('status'))
        <div class="mfa-success-message mfa-screen-item" role="status">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mfa-error mfa-screen-item" role="alert">{{ session('error') }}</div>
    @endif
    <div id="mfa-error" class="mfa-error mfa-screen-item" role="alert" @if (! $errors->has('code')) hidden @endif>{{ $errors->first('code') }}</div>

    <form method="POST" action="{{ $formAction }}" class="auth-form mfa-form mfa-screen-item" aria-labelledby="mfa-title">
        @csrf
        <input id="mfa-method" type="hidden" name="method" value="{{ $mfaMode }}">

        <section id="totp-panel" class="mfa-pane" @if ($mfaMode === 'recovery') hidden @endif>
            <label for="totp-code" class="mfa-label">
                @if ($mfaMode === 'email')
                    Email verification code
                @elseif ($mfaMode === 'sms')
                    SMS verification code
                @else
                    Authenticator code
                @endif
            </label>
            <div class="mfa-otp" aria-describedby="totp-hint">
                <div class="mfa-cells" aria-hidden="true">
                    @for ($index = 0; $index < 6; $index++)
                        <span class="mfa-cell"></span>
                    @endfor
                </div>
                <input
                    id="totp-code"
                    name="code"
                    class="mfa-code-input"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    placeholder="000000"
                    aria-label="Six-digit verification code"
                    @if ($mfaMode === 'recovery') disabled @else required autofocus @endif
                >
            </div>
            <p class="mfa-hint" id="totp-hint">
                @if ($mfaMode === 'email')
                    Enter the six-digit code sent to your verified email address.
                @elseif ($mfaMode === 'sms')
                    Enter the six-digit code sent to your verified phone number.
                @else
                    Open your authenticator app and enter the six-digit code.
                @endif
            </p>
        </section>

        <section id="recovery-panel" class="mfa-pane" @if ($mfaMode !== 'recovery') hidden @endif>
            <label for="recovery-code" class="mfa-label">Recovery code</label>
            <div class="input">
                <input
                    id="recovery-code"
                    name="code"
                    class="plain mfa-recovery-input"
                    type="text"
                    autocomplete="one-time-code"
                    placeholder="Enter a recovery code"
                    @if ($mfaMode !== 'recovery') disabled @else required autofocus @endif
                >
            </div>
            <p class="mfa-hint">Each recovery code can only be used once.</p>
        </section>

        <button type="submit" class="btn btn-brand mfa-submit">
            <span>{{ $submitLabel }}</span>
            <i class="bi bi-arrow-right" aria-hidden="true"></i>
        </button>
    </form>

    @if ($mfaMode === 'totp' || $mfaMode === 'recovery')
    <div class="methods mfa-switch mfa-screen-item">
        <a
            class="method mfa-method"
            href="{{ $mfaMode === 'recovery' ? $totpUrl.'?mode=totp' : $recoveryUrl }}"
            data-mode="{{ $mfaMode === 'recovery' ? 'totp' : 'recovery' }}"
            id="mfa-switch"
            @if ($mfaMode === 'totp' && ! $recoveryAvailable) hidden @endif
        >
            <span class="mi"><i class="bi bi-key mfa-switch-icon" aria-hidden="true"></i></span>
            <span>
                <span class="mt mfa-switch-title">{{ $mfaMode === 'recovery' ? 'Use an authenticator code' : 'Use a recovery code' }}</span><br>
                <span class="ms mfa-switch-sub">{{ $mfaMode === 'recovery' ? 'Enter a code from your authenticator' : 'Use one of your saved backup codes' }}</span>
            </span>
            <i class="bi bi-chevron-right arrow" aria-hidden="true"></i>
        </a>
    </div>
    @else
        <div class="methods mfa-switch mfa-screen-item">
            @if (in_array('totp', $availableMethods, true))
                <a class="method mfa-method" href="{{ $totpUrl }}?mode=totp">
                    <span class="mi"><i class="bi bi-shield-lock" aria-hidden="true"></i></span>
                    <span><span class="mt">Use an authenticator app</span><br><span class="ms">Enter a code from your authenticator</span></span>
                    <i class="bi bi-chevron-right arrow" aria-hidden="true"></i>
                </a>
            @endif
            @if ($recoveryAvailable)
                <a class="method mfa-method" href="{{ $recoveryUrl }}">
                    <span class="mi"><i class="bi bi-key" aria-hidden="true"></i></span>
                    <span><span class="mt">Use a recovery code</span><br><span class="ms">Enter one of your saved backup codes</span></span>
                    <i class="bi bi-chevron-right arrow" aria-hidden="true"></i>
                </a>
            @endif
        </div>
    @endif

    @if (in_array('email', $availableMethods, true) || in_array('sms', $availableMethods, true))
        <div class="methods mfa-switch mfa-screen-item">
            @foreach (['email' => 'Email code', 'sms' => 'SMS code'] as $method => $label)
                @if (in_array($method, $availableMethods, true))
                    <form method="POST" action="{{ $sendChallengeUrl }}" class="method mfa-method">
                        @csrf
                        <input type="hidden" name="method" value="{{ $method }}">
                        <span class="mi"><i class="bi {{ $method === 'email' ? 'bi-envelope' : 'bi-phone' }}" aria-hidden="true"></i></span>
                        <span>
                            <span class="mt">{{ $method === $mfaMode ? 'Resend '.$label : 'Send '.$label }}</span><br>
                            <span class="ms">{{ $method === 'email' ? 'Deliver a new code to your verified email' : 'Deliver a new code to your verified phone' }}</span>
                        </span>
                        <button type="submit" class="arrow" aria-label="Send {{ $label }}"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                    </form>
                @endif
            @endforeach
        </div>
    @endif

    <footer class="mfa-foot mfa-screen-item">
        <p class="mfa-help">Locked out? Contact your workspace administrator for help.</p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">{{ $signOutLabel }}</button>
        </form>
    </footer>

    <section id="mfa-success" class="success-wrap mfa-success" aria-live="polite" aria-labelledby="mfa-success-title" hidden>
        <div class="confetti mfa-confetti" aria-hidden="true">
            <i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i>
        </div>
        <svg class="check-circle" viewBox="0 0 96 96" aria-hidden="true">
            <circle cx="48" cy="48" r="44"></circle>
            <path d="m27 49 14 14 29-31"></path>
        </svg>
        <h1 id="mfa-success-title">Identity verified</h1>
        <p>Your account is secure. Continuing…</p>
        <div class="progress" aria-hidden="true"><div></div></div>
    </section>

    <script>
        (() => {
            document.documentElement.classList.add('has-js');

            const form = document.querySelector('.mfa-form');
            const input = document.getElementById('totp-code');
            const recoveryInput = document.getElementById('recovery-code');
            const cells = [...document.querySelectorAll('.mfa-cell')];
            const totpPanel = document.getElementById('totp-panel');
            const recoveryPanel = document.getElementById('recovery-panel');
            const switchLink = document.getElementById('mfa-switch');
            const switchTitle = document.querySelector('.mfa-switch-title');
            const switchSub = document.querySelector('.mfa-switch-sub');
            const switchIcon = document.querySelector('.mfa-switch-icon');
            const errorBox = document.getElementById('mfa-error');
            const methodInput = document.getElementById('mfa-method');
            const successPanel = document.getElementById('mfa-success');
            const submitButton = form.querySelector('[type="submit"]');
            const submitLabel = submitButton.querySelector('span');
            const defaultSubmitLabel = submitLabel.textContent;
            const hasCodeError = {{ $errors->has('code') ? 'true' : 'false' }};
            let isSubmitting = false;
            let isRedirecting = false;

            if (hasCodeError) {
                if ({{ $mfaMode === 'recovery' ? 'true' : 'false' }}) {
                    recoveryPanel.classList.add('is-invalid');
                    recoveryInput.setAttribute('aria-invalid', 'true');
                } else {
                    totpPanel.querySelector('.mfa-otp').classList.add('is-invalid');
                    input.setAttribute('aria-invalid', 'true');
                }
            }

            const activeInput = () => input.disabled ? recoveryInput : input;

            const renderCode = () => {
                const value = input.value;
                cells.forEach((cell, index) => {
                    const digit = value[index] || '';
                    if (digit !== cell.textContent) {
                        cell.textContent = digit;
                        cell.classList.toggle('is-filled', Boolean(digit));
                        if (digit) {
                            cell.classList.remove('is-entering');
                            void cell.offsetWidth;
                            cell.classList.add('is-entering');
                        } else {
                            cell.classList.remove('is-entering');
                        }
                    }
                    cell.classList.toggle('is-current', document.activeElement === input && index === value.length && value.length < cells.length);
                });
            };

            input.addEventListener('input', () => {
                input.value = input.value.replace(/\D/g, '').slice(0, 6);
                renderCode();
                if (input.value.length === cells.length && !isSubmitting) {
                    form.requestSubmit();
                }
            });
            input.addEventListener('focus', renderCode);
            input.addEventListener('blur', renderCode);

            const showError = message => {
                errorBox.textContent = message;
                errorBox.hidden = false;
                if (input.disabled) {
                    recoveryPanel.classList.add('is-invalid');
                    recoveryInput.setAttribute('aria-invalid', 'true');
                } else {
                    totpPanel.querySelector('.mfa-otp').classList.add('is-invalid');
                    input.setAttribute('aria-invalid', 'true');
                }
            };

            const resetFormState = () => {
                isSubmitting = false;
                form.setAttribute('aria-busy', 'false');
                submitButton.disabled = false;
                submitLabel.textContent = defaultSubmitLabel;
                input.readOnly = false;
                recoveryInput.readOnly = false;
                if (switchLink) {
                    switchLink.removeAttribute('aria-disabled');
                    switchLink.removeAttribute('tabindex');
                }
                totpPanel.querySelector('.mfa-otp').classList.remove('is-verifying');
            };

            form.addEventListener('submit', async event => {
                event.preventDefault();
                if (isSubmitting) {
                    return;
                }

                isSubmitting = true;
                const activeCodeInput = activeInput();
                const formData = new FormData(form);
                errorBox.hidden = true;
                errorBox.textContent = '';
                input.removeAttribute('aria-invalid');
                recoveryInput.removeAttribute('aria-invalid');
                totpPanel.querySelector('.mfa-otp').classList.remove('is-invalid');
                recoveryPanel.classList.remove('is-invalid');
                totpPanel.querySelector('.mfa-otp').classList.add('is-verifying');
                form.setAttribute('aria-busy', 'true');
                submitButton.disabled = true;
                submitLabel.textContent = 'Verifying…';
                activeCodeInput.readOnly = true;
                if (switchLink) {
                    switchLink.setAttribute('aria-disabled', 'true');
                    switchLink.tabIndex = -1;
                }

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (!(response.headers.get('content-type') || '').includes('application/json')) {
                        throw new Error('Your session may have expired. Reload and sign in again.');
                    }

                    const result = await response.json();
                    if (!response.ok) {
                        const message = response.status === 422
                            ? result.errors?.code?.[0] || result.message || 'The code could not be verified. Try again.'
                            : 'Verification is temporarily unavailable. Please try again.';
                        showError(message);
                        if (response.status === 422) {
                            activeCodeInput.value = '';
                            renderCode();
                        }
                        activeCodeInput.focus();
                        return;
                    }

                    if (typeof result.redirect !== 'string' || result.redirect.length === 0) {
                        throw new Error('Verification succeeded, but the next page could not be loaded. Reload to continue.');
                    }

                    document.querySelectorAll('.mfa-screen-item').forEach(element => {
                        element.hidden = true;
                    });
                    successPanel.hidden = false;
                    isRedirecting = true;
                    window.setTimeout(() => window.location.assign(result.redirect), 1800);
                } catch (error) {
                    showError(error instanceof Error ? error.message : 'Verification could not be completed. Please try again.');
                } finally {
                    if (!isRedirecting) {
                        resetFormState();
                    }
                }
            });

            if (switchLink) {
                switchLink.addEventListener('click', event => {
                if (isSubmitting) {
                    event.preventDefault();
                    return;
                }
                event.preventDefault();
                const recovery = switchLink.dataset.mode === 'recovery';
                totpPanel.hidden = recovery;
                recoveryPanel.hidden = !recovery;
                methodInput.value = recovery ? 'recovery' : 'totp';
                const activePanel = recovery ? recoveryPanel : totpPanel;
                activePanel.classList.remove('mfa-enter');
                void activePanel.offsetWidth;
                activePanel.classList.add('mfa-enter');
                totpPanel.querySelector('.mfa-otp').classList.remove('is-invalid');
                recoveryPanel.classList.remove('is-invalid');
                input.removeAttribute('aria-invalid');
                recoveryInput.removeAttribute('aria-invalid');
                input.disabled = recovery;
                recoveryInput.disabled = !recovery;
                switchLink.dataset.mode = recovery ? 'totp' : 'recovery';
                switchLink.href = recovery ? @json($totpUrl) : @json($recoveryUrl);
                switchTitle.textContent = recovery ? 'Use an authenticator code' : 'Use a recovery code';
                switchSub.textContent = recovery
                    ? 'Enter a code from your authenticator'
                    : 'Use one of your saved backup codes';
                switchIcon.className = `bi ${recovery ? 'bi-shield-lock' : 'bi-key'} mfa-switch-icon`;
                (recovery ? document.getElementById('recovery-code') : input).focus();
                });
            }

            renderCode();
        })();
    </script>
</x-layouts.auth>
