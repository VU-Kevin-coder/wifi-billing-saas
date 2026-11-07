<?php

namespace Tests;

use App\Services\Policy\Policy;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        Policy::flush();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Policy::flush();
        parent::tearDown();
    }
}
