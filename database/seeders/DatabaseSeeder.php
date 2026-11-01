<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            // DemoDataSeeder::class, // ❌ DO NOT run in production — test data only
        ]);
    }
}
