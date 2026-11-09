<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Nothing third-party may run on the login page or the customer portal, and the
 * production image must be able to build the stylesheet those pages use.
 */
class PublicAssetsTest extends TestCase
{
    private const PUBLIC_VIEWS = [
        'resources/views/components/layouts/auth.blade.php',
        'resources/views/landing.blade.php',
        'resources/views/portal/index.blade.php',
        'resources/views/portal/pending.blade.php',
        'resources/views/portal/success.blade.php',
    ];

    public function test_public_pages_use_the_built_stylesheet_not_the_tailwind_cdn(): void
    {
        foreach (self::PUBLIC_VIEWS as $view) {
            $html = file_get_contents(base_path($view));

            $this->assertStringNotContainsString('cdn.tailwindcss.com', $html, $view);
            $this->assertStringContainsString("@vite('resources/css/public.css')", $html, $view);
        }
    }

    public function test_no_view_loads_the_tailwind_cdn(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('resources/views'))) as $file) {
            if ($file->isFile() && str_contains(file_get_contents($file->getPathname()), 'cdn.tailwindcss.com')) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_the_public_stylesheet_is_part_of_the_build(): void
    {
        $this->assertStringContainsString('resources/css/public.css', file_get_contents(base_path('vite.config.js')));
        $this->assertFileExists(base_path('resources/css/public.css'));
        $this->assertFileExists(base_path('tailwind.public.config.js'));
        $this->assertStringContainsString('tailwind.public.config.js', file_get_contents(base_path('Dockerfile.render')),
            'the production image build must copy the public Tailwind config');
    }

    public function test_the_login_page_renders_without_a_built_manifest(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/')->assertOk();
    }

    public function test_the_csp_no_longer_allows_the_tailwind_cdn(): void
    {
        $this->assertStringNotContainsString('cdn.tailwindcss.com', file_get_contents(base_path('docker/nginx/render.conf')));
    }
}
