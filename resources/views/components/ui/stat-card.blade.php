@props([
    'label',
    'value',
    'description' => null,
    'tone' => 'default',
])

@php
    $tones = [
        'default' => 'border-gray-200 bg-white text-gray-900',
        'blue' => 'border-blue-700 bg-gradient-to-br from-sky-500 to-blue-700 text-white',
        'amber' => 'border-orange-600 bg-gradient-to-br from-amber-500 to-orange-600 text-white',
        'green' => 'border-green-700 bg-gradient-to-br from-emerald-500 to-green-700 text-white',
        'purple' => 'border-purple-700 bg-gradient-to-br from-blue-600 to-purple-700 text-white',
        'red' => 'border-red-700 bg-gradient-to-br from-rose-500 to-red-700 text-white',
    ];
    $muted = $tone === 'default' ? 'text-gray-500' : 'text-white/75';
    $detail = $tone === 'default' ? 'text-gray-500' : 'text-white/80';
@endphp

<article {{ $attributes->class(['rounded-xl border p-5 shadow-sm', $tones[$tone] ?? $tones['default']]) }}>
    <div class="flex items-start justify-between gap-3">
        <p class="text-sm font-medium {{ $muted }}">{{ $label }}</p>
        @if(isset($icon))
            <span class="shrink-0" aria-hidden="true">{{ $icon }}</span>
        @endif
    </div>
    <p class="mt-3 text-2xl font-bold tracking-tight">{{ $value }}</p>
    @if($description)
        <p class="mt-1 text-xs {{ $detail }}">{{ $description }}</p>
    @endif
    @if($slot->isNotEmpty())
        <div class="mt-2 text-xs {{ $detail }}">{{ $slot }}</div>
    @endif
</article>
