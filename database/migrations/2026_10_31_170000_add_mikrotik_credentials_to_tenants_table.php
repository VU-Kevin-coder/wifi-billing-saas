<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('mikrotik_host')->nullable()->after('status');
            $table->string('mikrotik_username')->nullable()->after('mikrotik_host');
            $table->text('mikrotik_password')->nullable()->after('mikrotik_username');
            $table->integer('mikrotik_port')->default(8728)->after('mikrotik_password');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'mikrotik_host',
                'mikrotik_username',
                'mikrotik_password',
                'mikrotik_port',
            ]);
        });
    }
};
