@props([
    'rows' => 3,
    'columns' => 1,
])

<div role="status" aria-live="polite">
    <span class="sr-only">Loading, please wait.</span>
    <div {{ $attributes->class('motion-safe:animate-pulse space-y-3') }} aria-hidden="true">
    @for($row = 0; $row < max(1, (int) $rows); $row++)
        <div class="grid gap-3" style="grid-template-columns: repeat({{ max(1, (int) $columns) }}, minmax(0, 1fr))">
            @for($column = 0; $column < max(1, (int) $columns); $column++)
                <div class="h-4 rounded bg-gray-200 {{ $column === (int) $columns - 1 ? 'w-3/4' : 'w-full' }}"></div>
            @endfor
        </div>
    @endfor
    </div>
</div>
