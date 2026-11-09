@props(['tone' => 'gray'])

@php
    $tones = [
        'green' => 'bg-green-100 text-green-800',
        'red' => 'bg-red-100 text-red-800',
        'yellow' => 'bg-yellow-100 text-yellow-800',
        'blue' => 'bg-blue-100 text-blue-800',
        'gray' => 'bg-gray-100 text-gray-700',
    ];
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', $tones[$tone] ?? $tones['gray']]) }}>
    {{ $slot }}
</span>
