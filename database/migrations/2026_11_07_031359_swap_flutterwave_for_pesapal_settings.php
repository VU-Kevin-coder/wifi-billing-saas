<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add Pesapal defaults
        $defaults = [
            ['key' => 'pesapal_consumer_key', 'value' => null],
            ['key' => 'pesapal_consumer_secret', 'value' => null],
            ['key' => 'pesapal_environment', 'value' => 'sandbox'],
            ['key' => 'pesapal_ipn_id', 'value' => null],
            ['key' => 'pesapal_status', 'value' => 'Not Configured'],
        ];

        foreach ($defaults as $setting) {
            DB::table('platform_settings')->updateOrInsert(
                ['key' => $setting['key']],
                ['value' => $setting['value'], 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('platform_settings')->where('key', 'like', 'pesapal_%')->delete();
    }
};
