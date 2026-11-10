<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoAccountPasswordSeeder extends Seeder
{
    public function run(): void
    {
        $adminPassword = $this->requiredPassword('SEED_ADMIN_PASSWORD');
        $tenantPassword = $this->requiredPassword('SEED_TENANT_PASSWORD');

        $this->updatePassword('platform@hamnet.test', $adminPassword);

        foreach (['admin@acme.test', 'finance@acme.test', 'admin@beta.test'] as $email) {
            $this->updatePassword($email, $tenantPassword);
        }

        $this->command?->info('Configured demo account passwords were applied.');
    }

    private function requiredPassword(string $key): string
    {
        $password = trim((string) env($key, ''));

        if ($password === '') {
            throw new RuntimeException("{$key} must be set to seed demo accounts.");
        }

        return $password;
    }

    private function updatePassword(string $email, string $password): void
    {
        $user = User::withoutTenancy()->where('email', $email)->first();

        if (! $user) {
            throw new RuntimeException("Cannot set a seeded password: account [{$email}] does not exist. Run DatabaseSeeder first.");
        }

        $user->forceFill(['password' => Hash::make($password)])->save();
    }
}
