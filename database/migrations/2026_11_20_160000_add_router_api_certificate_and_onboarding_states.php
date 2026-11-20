<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table): void {
            $table->text('api_ca_certificate')->nullable();
        });

        Schema::table('router_onboardings', function (Blueprint $table): void {
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('tunnel_verified_at')->nullable();
            $table->timestamp('api_verified_at')->nullable();
            $table->timestamp('radius_ready_at')->nullable();
        });

        DB::table('router_onboardings')
            ->where('status', 'connected')
            ->update([
                'status' => 'enrolled',
                'enrolled_at' => DB::raw('COALESCE(connected_at, updated_at)'),
            ]);

        DB::table('router_onboardings')
            ->where('status', 'completed')
            ->update([
                'status' => 'complete',
                'enrolled_at' => DB::raw('COALESCE(connected_at, completed_at, updated_at)'),
                'tunnel_verified_at' => DB::raw('COALESCE(connected_at, completed_at, updated_at)'),
                'api_verified_at' => DB::raw('COALESCE(connected_at, completed_at, updated_at)'),
                'radius_ready_at' => DB::raw('COALESCE(completed_at, updated_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('router_onboardings', function (Blueprint $table): void {
            $table->dropColumn([
                'enrolled_at',
                'tunnel_verified_at',
                'api_verified_at',
                'radius_ready_at',
            ]);
        });

        Schema::table('routers', function (Blueprint $table): void {
            $table->dropColumn('api_ca_certificate');
        });
    }
};
