@php
    $flashMessages = array_filter([
        'success' => session('success') ?: session('status'),
        'warning' => session('warning'),
        'error' => session('error') ?: ($errors->any() ? $errors->first() : null),
    ], fn ($message) => is_string($message) && $message !== '');

    $flashMessages = array_map(fn ($message) => match ($message) {
        'verification-link-sent' => __('A new verification link has been sent to your email address.'),
        'profile-updated' => __('Profile updated successfully.'),
        'password-updated' => __('Password updated successfully.'),
        default => $message,
    }, $flashMessages);
@endphp

@if($flashMessages)
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const messages = @js($flashMessages);
            for (const [type, message] of Object.entries(messages)) {
                window.toast?.[type]?.(message);
            }
        }, { once: true });
    </script>
@endif
