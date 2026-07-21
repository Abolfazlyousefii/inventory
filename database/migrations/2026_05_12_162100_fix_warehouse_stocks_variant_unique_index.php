<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // حذف unique قدیمی warehouse_id + product_id
        if ($this->indexExists(
            'warehouse_stocks',
            'warehouse_stocks_warehouse_id_product_id_unique'
        )) {
            Schema::table('warehouse_stocks', function (Blueprint $table) {
                $table->dropUnique('warehouse_stocks_warehouse_id_product_id_unique');
            });
        }

        // اگر ستون product_variant_id هنوز وجود ندارد، اضافه شود
        if (!Schema::hasColumn('warehouse_stocks', 'product_variant_id')) {
            Schema::table('warehouse_stocks', function (Blueprint $table) {
                $table->foreignId('product_variant_id')
                    ->nullable()
                    ->after('product_id')
                    ->constrained('product_variants')
                    ->nullOnDelete();
            });
        }

        // اضافه کردن unique درست: warehouse_id + product_variant_id
        if (!$this->indexExists('warehouse_stocks', 'warehouse_stocks_warehouse_variant_unique')) {
            Schema::table('warehouse_stocks', function (Blueprint $table) {
                $table->unique(
                    ['warehouse_id', 'product_variant_id'],
                    'warehouse_stocks_warehouse_variant_unique'
                );
            });
        }

        // ایندکس معمولی برای سرعت جستجو
        if (!$this->indexExists('warehouse_stocks', 'warehouse_stocks_wh_product_variant_index')) {
            Schema::table('warehouse_stocks', function (Blueprint $table) {
                $table->index(
                    ['warehouse_id', 'product_id', 'product_variant_id'],
                    'warehouse_stocks_wh_product_variant_index'
                );
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('warehouse_stocks', 'warehouse_stocks_wh_product_variant_index')) {
            Schema::table('warehouse_stocks', function (Blueprint $table) {
                $table->dropIndex('warehouse_stocks_wh_product_variant_index');
            });
        }

        if ($this->indexExists('warehouse_stocks', 'warehouse_stocks_warehouse_variant_unique')) {
            Schema::table('warehouse_stocks', function (Blueprint $table) {
                $table->dropUnique('warehouse_stocks_warehouse_variant_unique');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            return (int) DB::scalar(
                <<<'SQL'
                    SELECT COUNT(1)
                    FROM information_schema.statistics
                    WHERE table_schema = DATABASE()
                      AND table_name = ?
                      AND index_name = ?
                SQL,
                [$table, $indexName]
            ) > 0;
        }

        if ($driver === 'sqlite') {
            $indexes = DB::select(
                "PRAGMA index_list('".str_replace("'", "''", $table)."')"
            );

            return collect($indexes)->contains(
                fn (object $index): bool =>
                    isset($index->name)
                    && (string) $index->name === $indexName
            );
        }

        if (method_exists(Schema::getFacadeRoot(), 'getIndexes')) {
            return collect(Schema::getIndexes($table))->contains(
                fn (array $index): bool =>
                    ($index['name'] ?? null) === $indexName
            );
        }

        throw new \RuntimeException(
            "Unsupported database driver for index inspection: {$driver}"
        );
    }
};
