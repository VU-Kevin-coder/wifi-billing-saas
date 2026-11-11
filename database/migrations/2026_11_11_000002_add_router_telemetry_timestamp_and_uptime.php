<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table): void {
            $table->timestamp('telemetry_at')->nullable();
            $table->string('uptime', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table): void {
            $table->dropColumn(['telemetry_at', 'uptime']);
        });
    }
};
