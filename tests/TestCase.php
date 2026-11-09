<?php

namespace Tests;

use App\Services\Policy\Policy;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Policy::flush();

        // Pages call @vite(); without this, every page test would need a built
        // public/build/manifest.json (and fail on a clean CI checkout).
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Policy::flush();
        parent::tearDown();
    }

    protected function idempotencyKey(): string
    {
        return bin2hex(random_bytes(16));
    }
}
