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
        $adminPassword = trim((string) env('SEED_ADMIN_PASSWORD', ''));
        $tenantPassword = trim((string) env('SEED_TENANT_PASSWORD', ''));

        if ($adminPassword === '' && $tenantPassword === '') {
            $this->command?->info('No demo account passwords configured; existing passwords were left unchanged.');

            return;
        }

        if ($adminPassword !== '') {
            $this->updatePassword('platform@hamnet.test', $adminPassword);
        }

        if ($tenantPassword !== '') {
            foreach (['admin@acme.test', 'finance@acme.test', 'admin@beta.test'] as $email) {
                $this->updatePassword($email, $tenantPassword);
            }
        }

        $this->command?->info('Configured demo account passwords were applied.');
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
