<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table): void {
            $table->integer('api_port')->default(8729)->change();
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->integer('mikrotik_port')->default(8729)->change();
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table): void {
            $table->integer('api_port')->default(8728)->change();
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->integer('mikrotik_port')->default(8728)->change();
        });
    }
};
