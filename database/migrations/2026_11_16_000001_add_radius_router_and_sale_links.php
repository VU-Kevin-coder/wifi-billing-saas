<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROUTER_TENANT_KEY = 'routers_tenant_id_id_unique';

    private const TRANSACTION_TENANT_KEY = 'transactions_tenant_id_id_unique';

    private const SESSION_TENANT_ROUTER_KEY = 'sessions_log_tenant_router_id_unique';

    private const VOUCHER_SALE_UNIQUE = 'vouchers_sale_transaction_id_unique';

    private const VOUCHER_SALE_TENANT_FK = 'vouchers_tenant_sale_transaction_fk';

    public function up(): void
    {
        $this->addColumnIfMissing('routers', 'access_backend', function (Blueprint $table): void {
            $table->string('access_backend', 16)->default('site_agent');
        });

        $this->addColumnIfMissing('routers', 'radius_nas_identifier', function (Blueprint $table): void {
            $table->string('radius_nas_identifier', 255)->nullable();
        });

        $this->addColumnIfMissing('routers', 'radius_shared_secret', function (Blueprint $table): void {
            $table->text('radius_shared_secret')->nullable();
        });

        $this->addColumnIfMissing('vouchers', 'sale_transaction_id', function (Blueprint $table): void {
            $table->unsignedBigInteger('sale_transaction_id')->nullable();
        });

        $this->addUniqueIfMissing('routers', ['tenant_id', 'id'], self::ROUTER_TENANT_KEY);
        $this->addUniqueIfMissing('transactions', ['tenant_id', 'id'], self::TRANSACTION_TENANT_KEY);
        $this->addUniqueIfMissing('sessions_log', ['tenant_id', 'router_id', 'id'], self::SESSION_TENANT_ROUTER_KEY);
        $this->addUniqueIfMissing('routers', ['radius_nas_identifier'], 'routers_radius_nas_identifier_unique');
        $this->addUniqueIfMissing('vouchers', ['sale_transaction_id'], self::VOUCHER_SALE_UNIQUE);
        $this->addForeignKeyIfMissing(
            'vouchers',
            self::VOUCHER_SALE_TENANT_FK,
            ['tenant_id', 'sale_transaction_id'],
            'transactions',
            ['tenant_id', 'id']
        );
    }

    public function down(): void
    {
        $this->assertNoRouterConfigurationOrSaleLinks();

        $this->dropForeignKeyIfPresent('vouchers', self::VOUCHER_SALE_TENANT_FK);

        if (Schema::hasIndex('vouchers', self::VOUCHER_SALE_UNIQUE)) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->dropUnique(self::VOUCHER_SALE_UNIQUE);
            });
        }

        if (Schema::hasColumn('vouchers', 'sale_transaction_id')) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->dropColumn('sale_transaction_id');
            });
        }

        $this->dropUniqueIfPresent('routers', 'routers_radius_nas_identifier_unique');
        $this->dropColumnIfPresent('routers', 'radius_shared_secret');
        $this->dropColumnIfPresent('routers', 'radius_nas_identifier');
        $this->dropColumnIfPresent('routers', 'access_backend');
        $this->dropUniqueIfPresent('sessions_log', self::SESSION_TENANT_ROUTER_KEY);
        $this->dropUniqueIfPresent('transactions', self::TRANSACTION_TENANT_KEY);
        $this->dropUniqueIfPresent('routers', self::ROUTER_TENANT_KEY);
    }

    private function assertNoRouterConfigurationOrSaleLinks(): void
    {
        if (Schema::hasColumn('routers', 'access_backend')
            && DB::table('routers')->where('access_backend', '<>', 'site_agent')->exists()) {
            throw new RuntimeException('Cannot roll back router access mode while a router is not using site_agent.');
        }

        if ((Schema::hasColumn('routers', 'radius_nas_identifier')
                && DB::table('routers')->whereNotNull('radius_nas_identifier')->exists())
            || (Schema::hasColumn('routers', 'radius_shared_secret')
                && DB::table('routers')->whereNotNull('radius_shared_secret')->exists())) {
            throw new RuntimeException('Cannot roll back populated RADIUS router identity or credential data.');
        }

        if (Schema::hasColumn('vouchers', 'sale_transaction_id')
            && DB::table('vouchers')->whereNotNull('sale_transaction_id')->exists()) {
            throw new RuntimeException('Cannot roll back populated typed sale-to-voucher links.');
        }
    }

    private function addColumnIfMissing(string $tableName, string $columnName, Closure $definition): void
    {
        if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, $columnName)) {
            Schema::table($tableName, $definition);
        }
    }

    private function addUniqueIfMissing(string $tableName, array $columns, string $name): void
    {
        if (Schema::hasTable($tableName) && ! Schema::hasIndex($tableName, $name)) {
            Schema::table($tableName, function (Blueprint $table) use ($columns, $name): void {
                $table->unique($columns, $name);
            });
        }
    }

    private function addForeignKeyIfMissing(
        string $tableName,
        string $name,
        array $columns,
        string $referencedTable,
        array $referencedColumns
    ): void {
        if (! Schema::hasTable($tableName) || $this->hasForeignKey($tableName, $name)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($name, $columns, $referencedTable, $referencedColumns): void {
            $table->foreign($columns, $name)
                ->references($referencedColumns)
                ->on($referencedTable)
                ->restrictOnDelete();
        });
    }

    private function hasForeignKey(string $tableName, string $name): bool
    {
        return collect(Schema::getForeignKeys($tableName))
            ->contains(fn (array $foreignKey): bool => $foreignKey['name'] === $name);
    }

    private function dropForeignKeyIfPresent(string $tableName, string $name): void
    {
        if ($this->hasForeignKey($tableName, $name)) {
            Schema::table($tableName, function (Blueprint $table) use ($name): void {
                $table->dropForeign($name);
            });
        }
    }

    private function dropUniqueIfPresent(string $tableName, string $name): void
    {
        if (Schema::hasTable($tableName) && Schema::hasIndex($tableName, $name)) {
            Schema::table($tableName, function (Blueprint $table) use ($name): void {
                $table->dropUnique($name);
            });
        }
    }

    private function dropColumnIfPresent(string $tableName, string $columnName): void
    {
        if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, $columnName)) {
            Schema::table($tableName, function (Blueprint $table) use ($columnName): void {
                $table->dropColumn($columnName);
            });
        }
    }
};
