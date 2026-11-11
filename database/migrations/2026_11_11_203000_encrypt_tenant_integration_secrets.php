<?php

use App\Models\Tenant;
use App\Support\TenantSettingsSecrets;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Tenant::query()
            ->select(['id', 'settings'])
            ->chunkById(100, function ($tenants): void {
                foreach ($tenants as $tenant) {
                    $settings = json_decode($tenant->getRawOriginal('settings') ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                    $encrypted = TenantSettingsSecrets::encrypt(is_array($settings) ? $settings : []);
                    DB::table('tenants')->where('id', $tenant->id)->update([
                        'settings' => json_encode($encrypted, JSON_THROW_ON_ERROR),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Tenant::query()
            ->select(['id', 'settings'])
            ->chunkById(100, function ($tenants): void {
                foreach ($tenants as $tenant) {
                    $settings = json_decode($tenant->getRawOriginal('settings') ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                    $decrypted = TenantSettingsSecrets::decrypt(is_array($settings) ? $settings : []);
                    DB::table('tenants')->where('id', $tenant->id)->update([
                        'settings' => json_encode($decrypted, JSON_THROW_ON_ERROR),
                    ]);
                }
            });
    }
};
