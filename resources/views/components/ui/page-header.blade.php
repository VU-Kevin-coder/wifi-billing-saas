@props([
    'title',
    'subtitle' => null,
])

<header {{ $attributes->class('mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between') }}>
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-gray-900">{{ $title }}</h1>
        @if($subtitle)
            <p class="mt-1 text-sm text-gray-500">{{ $subtitle }}</p>
        @endif
        @if(isset($description))
            <div class="mt-1 text-sm text-gray-500">{{ $description }}</div>
        @endif
    </div>
    @if(isset($actions))
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endif
</header>
