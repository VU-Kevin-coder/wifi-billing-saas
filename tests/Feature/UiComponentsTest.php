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
            'attributes' => new ComponentAttributeBag(),
            'slot' => new ComponentSlot('Create'),
        ])->render();

        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('data-loading-text="Creating"', $html);
        $this->assertStringContainsString('Create', $html);
    }

    #[Test]
    public function shared_empty_state_renders_its_message_and_action(): void
    {
        $html = view('components.ui.empty-state', [
            'title' => 'Nothing here',
            'message' => 'Try adding an item.',
            'actionUrl' => null,
            'actionLabel' => null,
            'attributes' => new ComponentAttributeBag(),
            'slot' => new ComponentSlot('<a href="/items">Add item</a>'),
        ])->render();

        $this->assertStringContainsString('Nothing here', $html);
        $this->assertStringContainsString('Try adding an item.', $html);
        $this->assertStringContainsString('href="/items"', $html);
        $this->assertStringContainsString('Add item', $html);
    }
}
