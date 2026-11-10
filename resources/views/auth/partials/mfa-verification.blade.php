<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $pageTitle }} · {{ config('app.name', 'Hamnet') }}</title>
    @vite('resources/css/public.css')
    <style>
        :root {
            color-scheme: light;
            --paper: #f2eee6;
            --panel: #fbf9f4;
            --field: #fffdf9;
            --ink: #1c1a17;
            --muted: #6f6a60;
            --line: #ddd6c8;
            --accent: #1f5f4a;
            --bad: #b4372b;
        }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; background: var(--paper); color: var(--ink); font-family: Inter, ui-sans-serif, system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
        button, input { font: inherit; }
        .mfa-shell { position: relative; display: grid; min-height: 100vh; place-items: center; overflow: hidden; padding: 24px 16px; }
        .mfa-orbit { position: absolute; width: min(74vw, 780px); aspect-ratio: 1; border: 1px solid rgba(31,95,74,.1); border-radius: 50%; pointer-events: none; transform-origin: center; animation: mfa-drift 90s ease-in-out infinite alternate; }
        .mfa-orbit::before, .mfa-orbit::after { position: absolute; inset: 8%; border: inherit; border-radius: inherit; content: ""; }
        .mfa-orbit::after { inset: 16%; }
        @keyframes mfa-drift { from { transform: rotate(-2deg) scale(1); } to { transform: rotate(3deg) scale(1.03); } }
        .mfa-panel { position: relative; width: min(100%, 440px); padding: 28px 30px 22px; border: 1px solid var(--line); border-radius: 18px; background: rgba(251,249,244,.96); box-shadow: 0 1px 0 rgba(255,255,255,.8) inset, 0 18px 40px -22px rgba(60,50,30,.35); animation: mfa-rise .7s cubic-bezier(.22,.9,.3,1) both; }
        @keyframes mfa-rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
        .mfa-top, .mfa-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .mfa-top { margin-bottom: 30px; }
        .mfa-brand { display: flex; align-items: center; gap: 9px; font-family: Georgia, "Times New Roman", serif; font-size: 1.1rem; font-weight: 600; }
        .mfa-brand svg { width: 23px; height: 23px; color: var(--accent); }
        .mfa-step { color: var(--muted); font-size: .76rem; }
        .mfa-title { margin: 0 0 8px; font-family: Georgia, "Times New Roman", serif; font-size: 2rem; font-weight: 500; letter-spacing: -.025em; line-height: 1.12; }
        .mfa-enter { animation: mfa-swap .38s cubic-bezier(.22,.9,.3,1) both; }
        @keyframes mfa-swap { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
        .mfa-lede { margin: 0; color: var(--muted); font-size: .95rem; line-height: 1.55; }
        .mfa-error { margin: 20px 0 0; padding: 12px 14px; border: 1px solid #e8b8b2; border-radius: 12px; background: #f7e3df; color: #8d2a22; font-size: .88rem; animation: mfa-swap .3s cubic-bezier(.22,.9,.3,1) both; }
        .mfa-error p { margin: 0; }
        .mfa-error p + p { margin-top: 4px; }
        .mfa-form { margin-top: 24px; }
        .mfa-label { display: block; margin-bottom: 8px; color: #3d3932; font-size: .82rem; font-weight: 600; }
        .mfa-otp { position: relative; }
        .mfa-cells { display: flex; justify-content: center; gap: 8px; }
        .mfa-cell { display: grid; width: 100%; max-width: 54px; aspect-ratio: 54/66; place-items: center; border: 1.5px solid var(--line); border-radius: 12px; background: var(--field); color: var(--ink); font-family: Georgia, "Times New Roman", serif; font-size: 1.8rem; transition: border-color .2s, box-shadow .2s, transform .2s; }
        .mfa-cell.is-filled { border-color: #b9b09c; }
        .mfa-cell.is-entering { animation: mfa-digit .42s cubic-bezier(.22,.9,.3,1); }
        @keyframes mfa-digit { 0% { opacity: 0; transform: translateY(-14px) scale(.6); filter: blur(3px); } 55% { opacity: 1; transform: translateY(2px) scale(1.1); filter: blur(0); } 100% { transform: none; } }
        .mfa-cell:nth-child(4) { margin-left: 12px; }
        .mfa-otp:focus-within .mfa-cell { border-color: #b9b09c; }
        .mfa-otp:focus-within .mfa-cell.is-current { border-color: var(--accent); box-shadow: 0 0 0 4px rgba(31,95,74,.12); transform: translateY(-2px); }
        .mfa-otp:focus-within .mfa-cell.is-current::after { width: 2px; height: 28px; border-radius: 2px; background: var(--accent); content: ""; animation: mfa-blink 1.05s steps(1) infinite; }
        @keyframes mfa-blink { 50% { opacity: 0; } }
        .mfa-otp.is-invalid .mfa-cells, .mfa-recovery.is-invalid .mfa-code-input { animation: mfa-shake .45s cubic-bezier(.36,.07,.19,.97); }
        .mfa-otp.is-invalid .mfa-cell, .mfa-recovery.is-invalid .mfa-code-input { border-color: var(--bad); background: #f7e3df; color: var(--bad); box-shadow: none; }
        @keyframes mfa-shake { 15% { transform: translateX(-8px); } 30% { transform: translateX(7px); } 45% { transform: translateX(-5px); } 60% { transform: translateX(4px); } 80% { transform: translateX(-2px); } }
        .mfa-code-input { width: 100%; margin-top: 14px; padding: 14px 15px; border: 1.5px solid var(--line); border-radius: 12px; background: var(--field); color: var(--ink); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 1.05rem; letter-spacing: .22em; text-align: center; }
        .has-js .mfa-code-input.mfa-otp-input { position: absolute; inset: 0; z-index: 1; height: 100%; margin: 0; opacity: .01; cursor: text; }
        .mfa-otp:focus-within + .mfa-hint { color: var(--accent); }
        .mfa-hint { margin: 10px 0 0; color: var(--muted); font-size: .82rem; line-height: 1.5; }
        .mfa-submit { display: flex; width: 100%; align-items: center; justify-content: center; gap: 9px; margin-top: 22px; padding: 14px 16px; border: 0; border-radius: 12px; background: var(--accent); color: white; font-size: .94rem; font-weight: 600; cursor: pointer; transition: filter .2s, transform .15s; }
        .mfa-submit:hover { filter: brightness(.92); }
        .mfa-submit:active { transform: scale(.99); }
        .mfa-switch { margin-top: 16px; text-align: center; color: var(--muted); font-size: .85rem; }
        .mfa-switch a, .mfa-foot button { border: 0; background: none; color: var(--accent); font-size: inherit; font-weight: 600; text-decoration: underline; text-underline-offset: 3px; cursor: pointer; }
        .mfa-foot { margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--line); }
        .mfa-help { max-width: 270px; margin: 0; color: var(--muted); font-size: .78rem; line-height: 1.45; }
        .mfa-foot form { margin: 0; }
        .mfa-foot button { color: #686258; font-size: .82rem; font-weight: 500; white-space: nowrap; }
        .mfa-recovery { margin-top: 18px; }
        .mfa-recovery .mfa-code-input { margin-top: 0; letter-spacing: .1em; text-align: left; text-transform: uppercase; transition: border-color .2s, box-shadow .25s, background .25s; }
        .mfa-recovery .mfa-code-input:focus { border-color: var(--accent); box-shadow: 0 0 0 4px rgba(31,95,74,.12); }
        [hidden] { display: none !important; }
        @media (max-width: 480px) {
            .mfa-panel { padding: 24px 20px 20px; }
            .mfa-top { margin-bottom: 24px; }
            .mfa-title { font-size: 1.75rem; }
            .mfa-cells { gap: 5px; }
            .mfa-cell { border-radius: 9px; font-size: 1.5rem; }
            .mfa-cell:nth-child(4) { margin-left: 7px; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { scroll-behavior: auto !important; animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; }
        }
    </style>
</head>
<body>
<main class="mfa-shell">
    <div class="mfa-orbit" aria-hidden="true"></div>
    <section class="mfa-panel" aria-labelledby="mfa-title">
        <header class="mfa-top">
            <div class="mfa-brand">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M12 3 19 6v5c0 4.7-3 8-7 10-4-2-7-5.3-7-10V6l7-3Z" stroke-linejoin="round"/>
                    <path d="m9 12 2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span>{{ config('app.name', 'Hamnet') }}</span>
            </div>
            <span class="mfa-step">{{ $stepLabel }}</span>
        </header>

        <h1 id="mfa-title" class="mfa-title">{{ $pageTitle }}</h1>
        <p class="mfa-lede">Enter the 6-digit code from your authenticator app, or use one of your unused recovery codes.</p>

        @if ($errors->any())
            <div class="mfa-error" role="alert">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ $formAction }}" class="mfa-form" id="mfa-form">
            @csrf
            <div id="totp-panel" class="mfa-pane" @if ($mfaMode === 'recovery') hidden @endif>
                <label class="mfa-label" for="totp-code">Authenticator code</label>
                <div class="mfa-otp">
                    <div class="mfa-cells" aria-hidden="true">
                        @for ($digit = 0; $digit < 6; $digit++)
                            <span class="mfa-cell" data-digit="{{ $digit }}"></span>
                        @endfor
                    </div>
                    <input id="totp-code" class="mfa-code-input mfa-otp-input" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" aria-label="6-digit authenticator code" required @if ($mfaMode === 'recovery') disabled @else autofocus @endif>
                </div>
                <p class="mfa-hint">Open your authenticator app and enter the current 6-digit code.</p>
            </div>

            <div id="recovery-panel" class="mfa-recovery mfa-pane" @if ($mfaMode !== 'recovery') hidden @endif>
                <label class="mfa-label" for="recovery-code">Recovery code</label>
                <input id="recovery-code" class="mfa-code-input" name="code" type="text" maxlength="32" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="Enter an unused recovery code" aria-label="Recovery code" required @if ($mfaMode !== 'recovery') disabled @else autofocus @endif>
                <p class="mfa-hint">Each recovery code can only be used once.</p>
            </div>

            <button type="submit" class="mfa-submit">
                {{ $submitLabel }}
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M5 12h14m-7-7 7 7-7 7" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </form>

        <p class="mfa-switch" id="mfa-switch">
            @if ($mfaMode === 'recovery')
                Have your authenticator? <a href="{{ $totpUrl }}" data-mode="totp">Use an authenticator code</a>
            @else
                Can't use your authenticator? <a href="{{ $recoveryUrl }}" data-mode="recovery">Use a recovery code</a>
            @endif
        </p>

        <footer class="mfa-foot">
            <p class="mfa-help">Locked out? Contact your workspace administrator for help.</p>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">{{ $signOutLabel }}</button>
            </form>
        </footer>
    </section>
</main>
<script>
    (() => {
        const root = document.documentElement;
        root.classList.add('has-js');
        const input = document.getElementById('totp-code');
        const cells = [...document.querySelectorAll('.mfa-cell')];
        const totpPanel = document.getElementById('totp-panel');
        const recoveryPanel = document.getElementById('recovery-panel');
        const switchLink = document.querySelector('#mfa-switch a');
        const hasCodeError = {{ $errors->has('code') ? 'true' : 'false' }};
        if (hasCodeError) {
            if ({{ $mfaMode === 'recovery' ? 'true' : 'false' }}) {
                recoveryPanel.classList.add('is-invalid');
            } else {
                totpPanel.querySelector('.mfa-otp').classList.add('is-invalid');
            }
        }

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
        });
        input.addEventListener('focus', renderCode);
        input.addEventListener('blur', renderCode);

        switchLink?.addEventListener('click', event => {
            event.preventDefault();
            const recovery = switchLink.dataset.mode === 'recovery';
            totpPanel.hidden = recovery;
            recoveryPanel.hidden = !recovery;
            const activePanel = recovery ? recoveryPanel : totpPanel;
            activePanel.classList.remove('mfa-enter');
            void activePanel.offsetWidth;
            activePanel.classList.add('mfa-enter');
            input.disabled = recovery;
            document.getElementById('recovery-code').disabled = !recovery;
            switchLink.dataset.mode = recovery ? 'totp' : 'recovery';
            switchLink.href = recovery ? @json($totpUrl) : @json($recoveryUrl);
            switchLink.textContent = recovery ? 'Use an authenticator code' : 'Use a recovery code';
            switchLink.parentElement.firstChild.textContent = recovery
                ? 'Have your authenticator? '
                : "Can't use your authenticator? ";
            (recovery ? document.getElementById('recovery-code') : input).focus();
        });

        renderCode();
    })();
</script>
</body>
</html>
