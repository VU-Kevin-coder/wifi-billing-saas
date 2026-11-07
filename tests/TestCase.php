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
    }

    protected function tearDown(): void
    {
        Policy::flush();
        parent::tearDown();
    }
}
