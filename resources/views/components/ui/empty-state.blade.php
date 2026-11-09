@props([
    'title',
    'message' => null,
    'actionUrl' => null,
    'actionLabel' => null,
])

<div {{ $attributes->class('flex flex-col items-center justify-center px-6 py-12 text-center') }}>
    <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-500" aria-hidden="true">
        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2h7m7-7l-4 4m0-4l4 4M8 9h8M8 13h4"/>
        </svg>
    </div>
    <h3 class="text-sm font-semibold text-gray-900">{{ $title }}</h3>
    @if($message)
        <p class="mt-1 max-w-md text-sm text-gray-500">{{ $message }}</p>
    @endif
    @if($slot->isNotEmpty())
        <div class="mt-4">{{ $slot }}</div>
    @elseif($actionUrl && $actionLabel)
        <a href="{{ $actionUrl }}" class="mt-4 inline-flex items-center rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
            {{ $actionLabel }}
        </a>
    @endif
</div>
