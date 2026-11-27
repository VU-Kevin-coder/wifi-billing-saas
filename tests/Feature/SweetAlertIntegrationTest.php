<?php

namespace Tests\Feature;

use Tests\TestCase;

class SweetAlertIntegrationTest extends TestCase
{
    public function test_local_dialog_is_available_across_system_and_portal_layouts(): void
    {
        $assets = file_get_contents(resource_path('views/partials/sweetalert-assets.blade.php'));
        $this->assertStringContainsString("asset('js/ui-dialog.js')", $assets);
        $this->assertStringContainsString("asset('css/ui-dialog.css')", $assets);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $assets);
        $this->assertFileExists(public_path('js/ui-dialog.js'));
        $this->assertFileExists(public_path('css/ui-dialog.css'));

        foreach ([
            'layouts/app.blade.php',
            'layouts/guest.blade.php',
            'components/layouts/auth.blade.php',
            'portal/index.blade.php',
            'portal/plan.blade.php',
            'portal/plan-lookup.blade.php',
            'portal/pending.blade.php',
            'portal/success.blade.php',
        ] as $view) {
            $content = file_get_contents(resource_path("views/{$view}"));
            $this->assertStringContainsString("@include('partials.sweetalert-assets')", $content, $view);
            $this->assertStringContainsString("@include('partials.swal-flash')", $content, $view);
        }
    }

    public function test_feedback_and_confirmation_helpers_use_sweetalert_modals_without_native_dialogs(): void
    {
        $partial = file_get_contents(resource_path('views/partials/swal-flash.blade.php'));
        $toast = file_get_contents(resource_path('js/toast.js'));
        $confirm = file_get_contents(resource_path('js/confirm.js'));

        $this->assertStringContainsString('data-flash-message="{{ $message }}"', $partial);
        $this->assertStringContainsString('<details class="ui-flash" open>', $partial);
        $this->assertStringNotContainsString('<script>', $partial);
        $this->assertStringContainsString('window.Swal?.fire', $toast);
        $this->assertStringNotContainsString('toast: true', $toast);
        $this->assertStringContainsString('window.confirmWithReason', $confirm);
        $this->assertStringContainsString("input: 'textarea'", $confirm);
        $this->assertStringNotContainsString('window.confirm(', $confirm);
        $this->assertStringNotContainsString('window.alert(', $confirm);
        $this->assertStringNotContainsString('window.prompt(', $confirm);

        foreach ([
            'resources/views',
            'resources/js',
        ] as $path) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($path)));
            foreach ($files as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $content = file_get_contents($file->getPathname());
                $this->assertDoesNotMatchRegularExpression('/window\\.(?:alert|confirm|prompt)\\s*\\(/', $content, $file->getPathname());
            }
        }
    }
}
