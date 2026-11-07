@php
    $type = is_bool($policy->value) ? 'boolean' : (is_numeric($policy->value) ? 'number' : 'text');
@endphp
<div>
    <label class="block text-sm font-medium text-gray-700 mb-1">{{ $policy->description ?? $policy->key }}</label>
    <div class="flex items-center gap-2">
        @if($type === 'boolean')
            <input type="hidden" name="policies[{{ $policy->key }}]" value="0">
            <input type="checkbox" name="policies[{{ $policy->key }}]" value="1"
                   {{ $policy->value ? 'checked' : '' }}
                   class="rounded border-gray-300 text-blue-600 shadow-sm">
        @else
            <input type="number" step="{{ $type === 'number' && floor($policy->value) != $policy->value ? '0.01' : '1' }}"
                   name="policies[{{ $policy->key }}]"
                   value="{{ $policy->value }}"
                   class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
        @endif
    </div>
    <p class="text-xs text-gray-400 mt-1">{{ $policy->key }}</p>
</div>

