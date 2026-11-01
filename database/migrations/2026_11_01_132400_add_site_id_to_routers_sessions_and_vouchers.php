<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
        });

        Schema::table('sessions_log', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
        });

        $legacySites = DB::table('routers')
            ->whereNotNull('site')
            ->where('site', '<>', '')
            ->select('tenant_id', 'site as name')
            ->distinct()
            ->get()
            ->merge(
                DB::table('vouchers')
                    ->whereNotNull('assigned_site')
                    ->where('assigned_site', '<>', '')
                    ->select('tenant_id', 'assigned_site as name')
                    ->distinct()
                    ->get()
            )
            ->unique(fn ($site) => $site->tenant_id.'|'.$site->name);

        foreach ($legacySites as $legacySite) {
            DB::table('sites')->insertOrIgnore([
                'tenant_id' => $legacySite->tenant_id,
                'name' => $legacySite->name,
                'status' => 'healthy',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $site = DB::table('sites')
                ->where('tenant_id', $legacySite->tenant_id)
                ->where('name', $legacySite->name)
                ->first();

            DB::table('routers')
                ->where('tenant_id', $legacySite->tenant_id)
                ->where('site', $legacySite->name)
                ->update(['site_id' => $site->id]);

            DB::table('vouchers')
                ->where('tenant_id', $legacySite->tenant_id)
                ->where('assigned_site', $legacySite->name)
                ->update(['site_id' => $site->id]);
        }

        $routers = DB::table('routers')->whereNotNull('site_id')->get(['id', 'tenant_id', 'site_id']);
        foreach ($routers as $router) {
            DB::table('sessions_log')
                ->where('tenant_id', $router->tenant_id)
                ->where('router_id', $router->id)
                ->update(['site_id' => $router->site_id]);
        }
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
        });

        Schema::table('sessions_log', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
        });

        Schema::table('routers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
        });
    }
};
