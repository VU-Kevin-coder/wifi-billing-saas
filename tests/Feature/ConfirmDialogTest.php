<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConfirmDialogTest extends TestCase
{
    public function test_confirmations_use_sweetalert_and_fail_closed_without_it(): void
    {
        $script = file_get_contents(resource_path('js/confirm.js'));

        $this->assertStringContainsString('window.Swal?.fire', $script);
        $this->assertStringContainsString('return Promise.resolve(false)', $script);
        $this->assertStringNotContainsString('window.confirm(', $script);
        $this->assertStringNotContainsString('window.prompt(', $script);
        $this->assertStringContainsString('form.dataset.confirmAction', $script);
        $this->assertStringContainsString('form.requestSubmit', $script);
    }

    public function test_destructive_forms_no_longer_use_inline_native_confirm_handlers(): void
    {
        foreach ([
            'operator/customers/index.blade.php',
            'operator/vouchers/index.blade.php',
            'operator/staff/index.blade.php',
            'operator/routers/index.blade.php',
            'operator/settings/router.blade.php',
            'platform/revenue/index.blade.php',
            'platform/billing/index.blade.php',
            'platform/refunds/index.blade.php',
        ] as $view) {
            $contents = file_get_contents(resource_path("views/{$view}"));
            $this->assertStringNotContainsString('onsubmit="return confirm(', $contents, $view);
            $this->assertStringNotContainsString('onclick="return confirm(', $contents, $view);
        }
    }
}
