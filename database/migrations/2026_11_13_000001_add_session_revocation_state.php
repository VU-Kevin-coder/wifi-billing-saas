<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions_log', function (Blueprint $table): void {
            $table->boolean('pending_revocation')->default(false);
            $table->string('pending_revocation_status', 20)->nullable();
            $table->boolean('pending_device_ban')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sessions_log', function (Blueprint $table): void {
            $table->dropColumn(['pending_revocation', 'pending_revocation_status', 'pending_device_ban']);
        });
    }
};
