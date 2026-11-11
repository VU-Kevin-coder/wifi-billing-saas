<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_agent_commands', function (Blueprint $table): void {
            $table->foreignId('session_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('site_agent_commands', function (Blueprint $table): void {
            $table->foreignId('session_id')->nullable(false)->change();
        });
    }
};
