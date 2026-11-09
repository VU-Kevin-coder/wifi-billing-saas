@props([
    'variant' => 'primary',
    'type' => 'button',
    'loadingText' => 'Saving…',
])

@php
    $variants = [
        'primary' => 'bg-blue-600 text-white hover:bg-blue-700 focus-visible:ring-blue-500',
        'secondary' => 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus-visible:ring-blue-500',
        'danger' => 'bg-red-600 text-white hover:bg-red-700 focus-visible:ring-red-500',
        'ghost' => 'bg-transparent text-gray-700 hover:bg-gray-100 focus-visible:ring-blue-500',
    ];
@endphp

<button
    type="{{ $type }}"
    data-ui-button
    data-loading-text="{{ $loadingText }}"
    {{ $attributes->class([
        'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60',
        $variants[$variant] ?? $variants['primary'],
    ]) }}
>
    {{ $slot }}
</button>
