<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionSeedGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_database_seeder_is_disabled_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Demo data seeding is disabled in production.');

        (new DatabaseSeeder)->run();
    }
}
