<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform-admin:create';

    protected $description = 'Create a platform administrator account interactively';

    public function handle(): int
    {
        $attributes = [
            'name' => trim((string) $this->ask('Administrator name')),
            'email' => strtolower(trim((string) $this->ask('Administrator email'))),
            'password' => $this->secret('Password'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];

        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        $role = Role::firstOrCreate(['name' => 'platform_admin', 'guard_name' => 'web']);
        $admin = User::create([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'password' => Hash::make($attributes['password']),
            'tenant_id' => null,
            'email_verified_at' => now(),
        ]);
        $admin->assignRole($role);

        $this->components->info("Platform administrator {$admin->email} created.");

        return self::SUCCESS;
    }
}
