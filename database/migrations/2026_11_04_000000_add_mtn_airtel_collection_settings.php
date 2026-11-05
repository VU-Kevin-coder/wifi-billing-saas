<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'mtn_subscription_key' => null,
            'mtn_api_user' => null,
            'mtn_api_key' => null,
            'mtn_environment' => 'sandbox',
            'mtn_status' => 'Not Configured',
            'airtel_client_id' => null,
            'airtel_client_secret' => null,
            'airtel_environment' => 'sandbox',
            'airtel_status' => 'Not Configured',
        ] as $key => $value) {
            DB::table('platform_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('platform_settings')
            ->whereIn('key', [
                'mtn_subscription_key',
                'mtn_api_user',
                'mtn_api_key',
                'mtn_environment',
                'mtn_status',
                'airtel_client_id',
                'airtel_client_secret',
                'airtel_environment',
                'airtel_status',
            ])
            ->delete();
    }
};
