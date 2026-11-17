@props(['colspan' => 1, 'title', 'message' => null, 'actionUrl' => null, 'actionLabel' => null])
<tr>
    <td colspan="{{ max(1, (int) $colspan) }}">
        <x-ui.empty-state :title="$title" :message="$message" :action-url="$actionUrl" :action-label="$actionLabel">{{ $slot }}</x-ui.empty-state>
    </td>
</tr>
