<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('preinvoice_orders')) {
            return;
        }

        $this->modifyColumnNullable('shipping_id');
        $this->modifyColumnNullable('province_id');
        $this->modifyColumnNullable('city_id');
        $this->modifyColumnNullable('customer_address');
        $this->ensureShippingPriceDefault();
    }

    public function down(): void
    {
        if (! Schema::hasTable('preinvoice_orders')) {
            return;
        }

        $this->modifyColumnNullable('customer_address', false);
        $this->modifyColumnNullable('province_id', false);
        $this->modifyColumnNullable('city_id', false);
        $this->modifyColumnNullable('shipping_id', false);
    }

    private function modifyColumnNullable(string $column, bool $nullable = true): void
    {
        if (! Schema::hasColumn('preinvoice_orders', $column)) {
            return;
        }

        $metadata = $this->columnMetadata($column);
        if (! $metadata) {
            return;
        }

        $currentlyNullable = strtoupper((string) $metadata->IS_NULLABLE) === 'YES';
        if ($currentlyNullable === $nullable) {
            return;
        }

        if (! $nullable && $this->hasNullValues($column)) {
            return;
        }

        $definition = $metadata->COLUMN_TYPE . ($nullable ? ' NULL' : ' NOT NULL');
        if (! $nullable && $metadata->COLUMN_DEFAULT !== null) {
            $definition .= ' DEFAULT ' . DB::getPdo()->quote((string) $metadata->COLUMN_DEFAULT);
        }

        DB::statement("ALTER TABLE preinvoice_orders MODIFY {$column} {$definition}");
    }

    private function ensureShippingPriceDefault(): void
    {
        if (! Schema::hasColumn('preinvoice_orders', 'shipping_price')) {
            return;
        }

        $metadata = $this->columnMetadata('shipping_price');
        if (! $metadata || $metadata->COLUMN_DEFAULT !== null) {
            return;
        }

        $nullable = strtoupper((string) $metadata->IS_NULLABLE) === 'YES' ? 'NULL' : 'NOT NULL';
        DB::statement("ALTER TABLE preinvoice_orders MODIFY shipping_price {$metadata->COLUMN_TYPE} {$nullable} DEFAULT 0");
    }

    private function columnMetadata(string $column): ?object
    {
        $result = DB::selectOne(
            "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'preinvoice_orders'
               AND COLUMN_NAME = ?",
            [$column]
        );

        return $result ?: null;
    }

    private function hasNullValues(string $column): bool
    {
        return (int) DB::table('preinvoice_orders')->whereNull($column)->limit(1)->count() > 0;
    }
};
