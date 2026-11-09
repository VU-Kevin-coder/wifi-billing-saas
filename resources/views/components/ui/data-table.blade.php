@props([
    'title' => null,
    'description' => null,
    'paginator' => null,
])

<section {{ $attributes->class('overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm') }}>
    @if($title || $description || isset($header))
        <div class="border-b border-gray-100 px-4 py-3 sm:px-5">
            @if(isset($header))
                {{ $header }}
            @else
                @if($title)
                    <h2 class="text-sm font-semibold text-gray-900">{{ $title }}</h2>
                @endif
                @if($description)
                    <p class="mt-1 text-sm text-gray-500">{{ $description }}</p>
                @endif
            @endif
        </div>
    @endif
    <div class="overflow-x-auto">
        {{ $slot }}
    </div>
    @if($paginator && $paginator->hasPages())
        <div class="border-t border-gray-100 px-4 py-3 sm:px-5">
            {{ $paginator->links() }}
        </div>
    @endif
</section>
