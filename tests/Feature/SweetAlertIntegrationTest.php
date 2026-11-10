<?php

namespace Tests\Feature;

use Tests\TestCase;

class SweetAlertIntegrationTest extends TestCase
{
    public function test_sweetalert_is_pinned_with_integrity_and_loaded_only_for_authenticated_layouts(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('@auth', $layout);
        $this->assertStringContainsString('sweetalert2@11.14.5/dist/sweetalert2.all.min.js', $layout);
        $this->assertStringContainsString('integrity="sha384-YB/DdIkloKoRpclWB8bNcYXWakt57USgtQPDzvnIDHYU0lasD5eWlXVo1S4ODukY"', $layout);
        $this->assertStringContainsString("@include('partials.swal-flash')", $layout);

        foreach ([
            'components/layouts/auth.blade.php',
            'landing.blade.php',
            'portal/index.blade.php',
            'portal/pending.blade.php',
            'portal/success.blade.php',
        ] as $view) {
            $this->assertStringNotContainsString('sweetalert2', file_get_contents(resource_path("views/{$view}")), $view);
        }
    }

    public function test_flash_messages_use_the_sweetalert_toast_helper(): void
    {
        $partial = file_get_contents(resource_path('views/partials/swal-flash.blade.php'));
        $toast = file_get_contents(resource_path('js/toast.js'));

        $this->assertStringContainsString('window.toast?.[type]?.(message)', $partial);
        $this->assertStringContainsString('window.Swal?.fire', $toast);
        $this->assertStringContainsString("window.dispatchEvent(new CustomEvent('ui-toast'", $toast);
    }
}
