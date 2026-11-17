<?php

namespace Tests\Feature;

use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\ComponentSlot;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UiComponentsTest extends TestCase
{
    #[Test]
    public function shared_button_exposes_loading_text_and_accessible_submit_attributes(): void
    {
        $html = view('components.ui.button', [
            'type' => 'submit',
            'variant' => 'primary',
            'loadingText' => 'Creating',
            'attributes' => new ComponentAttributeBag,
            'slot' => new ComponentSlot('Create'),
        ])->render();

        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('data-loading-text="Creating"', $html);
        $this->assertStringContainsString('Create', $html);
    }

    #[Test]
    public function table_empty_row_and_accessible_skeleton_render(): void
    {
        $row = \Illuminate\Support\Facades\Blade::render('<table><tbody><x-ui.empty-table-row colspan="7" title="No results" action-url="/items" action-label="Browse" /></tbody></table>');
        $this->assertStringContainsString('<td colspan="7">', $row);
        $this->assertStringContainsString('No results', $row);
        $this->assertStringContainsString('href="/items"', $row);

        $skeleton = \Illuminate\Support\Facades\Blade::render('<x-ui.skeleton :rows="2" :columns="3" />');
        $this->assertStringContainsString('role="status"', $skeleton);
        $this->assertStringContainsString('Loading, please wait.', $skeleton);
        $this->assertStringContainsString('motion-safe:animate-pulse', $skeleton);
    }

    #[Test]
    public function shared_empty_state_renders_its_message_and_action(): void
    {
        $html = view('components.ui.empty-state', [
            'title' => 'Nothing here',
            'message' => 'Try adding an item.',
            'actionUrl' => null,
            'actionLabel' => null,
            'attributes' => new ComponentAttributeBag,
            'slot' => new ComponentSlot('<a href="/items">Add item</a>'),
        ])->render();

        $this->assertStringContainsString('Nothing here', $html);
        $this->assertStringContainsString('Try adding an item.', $html);
        $this->assertStringContainsString('href="/items"', $html);
        $this->assertStringContainsString('Add item', $html);
    }
}
