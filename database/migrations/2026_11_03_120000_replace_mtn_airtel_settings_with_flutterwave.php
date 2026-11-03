<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')
            ->whereIn('key', [
                'mtn_api_user',
                'mtn_api_key',
                'mtn_subscription_key',
                'mtn_environment',
                'mtn_target_environment',
                'mtn_status',
                'mtn_callback_url',
                'airtel_client_id',
                'airtel_client_secret',
                'airtel_environment',
                'airtel_status',
                'airtel_callback_url',
            ])
            ->delete();

        foreach ([
            'flutterwave_public_key' => null,
            'flutterwave_secret_key' => null,
            'flutterwave_encryption_key' => null,
            'flutterwave_webhook_secret' => null,
            'flutterwave_environment' => 'sandbox',
            'flutterwave_status' => 'Not Configured',
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
                'flutterwave_public_key',
                'flutterwave_secret_key',
                'flutterwave_encryption_key',
                'flutterwave_webhook_secret',
                'flutterwave_environment',
                'flutterwave_status',
            ])
            ->delete();
    }
};
