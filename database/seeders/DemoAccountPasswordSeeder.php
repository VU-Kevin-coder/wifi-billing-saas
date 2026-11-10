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
        $adminPassword = $this->configuredPassword('SEED_ADMIN_PASSWORD');
        $tenantPassword = $this->configuredPassword('SEED_TENANT_PASSWORD');

        if ($adminPassword === '' && $tenantPassword === '') {
            $this->command?->info('No demo account passwords configured; existing passwords were left unchanged.');

            return;
        }

        if ($adminPassword === '' || $tenantPassword === '') {
            throw new RuntimeException('SEED_ADMIN_PASSWORD and SEED_TENANT_PASSWORD must either both be set or both be empty.');
        }

        $this->updatePassword('platform@hamnet.test', $adminPassword);

        foreach (['admin@acme.test', 'finance@acme.test', 'admin@beta.test'] as $email) {
            $this->updatePassword($email, $tenantPassword);
        }

        $this->command?->info('Configured demo account passwords were applied.');
    }

    private function configuredPassword(string $key): string
    {
        $password = trim((string) env($key, ''));

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
