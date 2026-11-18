<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table): void {
            $table->timestamp('identity_verified_at')->nullable()->after('serial_number');
        });

        Schema::table('site_agent_commands', function (Blueprint $table): void {
            $table->json('result')->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        Schema::table('site_agent_commands', function (Blueprint $table): void {
            $table->dropColumn('result');
        });

        Schema::table('routers', function (Blueprint $table): void {
            $table->dropColumn('identity_verified_at');
        });
    }
};
