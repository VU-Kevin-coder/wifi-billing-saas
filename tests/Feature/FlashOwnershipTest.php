<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class FlashOwnershipTest extends TestCase
{
    private function renderFlash(string $route, array $messages, array $validation = []): string
    {
        $this->app['request']->setRouteResolver(fn () => (new Route('GET', '/', []))->name($route));
        foreach (['success', 'status', 'warning', 'error'] as $key) {
            $this->app['session']->forget($key);
        }
        foreach ($messages as $key => $value) {
            $this->app['session']->put($key, $value);
        }
        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag($validation));

        return view('partials.swal-flash', ['errors' => $errors])->render();
    }

    public function test_page_owned_status_and_validation_are_not_repeated_but_other_feedback_remains(): void
    {
        $html = $this->renderFlash('operator.sites.index', ['status' => 'Saved', 'warning' => 'Check details'], ['name' => 'Name required']);
        $this->assertStringNotContainsString('Saved', $html);
        $this->assertStringNotContainsString('Name required', $html);
        $this->assertStringContainsString('Check details', $html);
        $this->assertStringContainsString('role="status"', $html);
    }

    public function test_unowned_feedback_and_validation_stay_visible_and_escaped(): void
    {
        $html = $this->renderFlash('platform.tenants.index', ['status' => '<b>Mixed</b>'], ['name' => 'Name required']);
        $this->assertStringContainsString('&lt;b&gt;Mixed&lt;/b&gt;', $html);
        $this->assertStringContainsString('Name required', $html);
        $this->assertStringContainsString('role="alert"', $html);
    }

    public function test_configuration_precedence_keeps_unshown_status(): void
    {
        $html = $this->renderFlash('platform.configuration.index', ['success' => 'Saved', 'status' => 'Still pending', 'error' => 'Problem']);
        $this->assertStringNotContainsString('Saved', $html);
        $this->assertStringNotContainsString('Problem', $html);
        $this->assertStringContainsString('Still pending', $html);
        $html = $this->renderFlash('platform.configuration.index', ['status' => 'Saved']);
        $this->assertStringNotContainsString('Saved', $html);
    }
}
