@php
    $flashMessages = array_filter([
        'success' => session('success') ?: session('status'),
        'error' => session('error'),
        'warning' => session('warning'),
    ], fn ($message) => is_string($message) && $message !== '');
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
