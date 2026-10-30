<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Clear cached roles/permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $roles = [
            'platform_admin',
            'operator_admin',
            'operator_finance',
            'operator_support',
            'operator_network',
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }
}
