<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions_log', function (Blueprint $table): void {
            // Existing sessions deliberately remain unknown, not backfilled.
            $table->string('access_backend_origin', 16)->nullable();
            $table->string('radius_admission_session_id', 255)->nullable();
            $table->string('radius_admission_mac', 17)->nullable();
            $table->unique(['router_id', 'radius_admission_session_id'], 'sessions_radius_admission_unique');
        });
    }

    public function down(): void
    {
        // Removing these fields can turn an unknown in-flight connection into a
        // new entitlement; rollback requires an explicit operational migration.
        throw new LogicException('RADIUS admission evidence cannot be removed automatically.');
    }
};
