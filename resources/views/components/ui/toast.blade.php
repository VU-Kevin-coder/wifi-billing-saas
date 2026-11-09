@php
    $initialType = session('error') ? 'error' : (session('warning') ? 'warning' : (session('status') || session('success') ? 'success' : null));
    $initialMessage = session('error') ?: (session('warning') ?: (session('status') ?: session('success')));
@endphp

<div
    x-data="uiToast({ type: @js($initialType), message: @js($initialMessage) })"
    @ui-toast.window="show($event.detail)"
    class="pointer-events-none fixed inset-x-4 bottom-4 z-[100] flex justify-end sm:inset-x-auto sm:right-6 sm:w-96"
    aria-live="polite"
    aria-atomic="true"
>
    <div
        x-cloak
        x-show="visible"
        x-transition.opacity.duration.200ms
        role="status"
        class="pointer-events-auto flex w-full items-start gap-3 rounded-xl border bg-white p-4 shadow-lg"
        :class="{
            'border-green-200': type === 'success',
            'border-red-200': type === 'error',
            'border-amber-200': type === 'warning',
            'border-blue-200': type === 'info'
        }"
    >
        <p class="min-w-0 flex-1 text-sm text-gray-800" x-text="message"></p>
        <button type="button" class="rounded p-1 text-gray-500 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500" aria-label="Dismiss notification" @click="dismiss()">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
</div>
