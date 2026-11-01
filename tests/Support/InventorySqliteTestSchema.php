<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Schema additions required by a standalone legacy SQLite fixture.
 * Not a migration: never use this helper on a persistent or live database.
 */
final class InventorySqliteTestSchema
{
    public static function forReservationProductLookup(): void
    {
        if (! app()->environment('testing')
            || DB::connection()->getDriverName() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('Inventory test schema setup requires the isolated in-memory SQLite testing connection.');
        }

        // The legacy product fixture predates the bundle availability predicate.
        self::columnIfMissing('products', 'is_bundle', fn (Blueprint $t) => $t->boolean('is_bundle')->default(false));
        self::columnIfMissing('products', 'bundle_stock_mode', fn (Blueprint $t) => $t->string('bundle_stock_mode')->default('physical'));
        self::columnIfMissing('products', 'is_sellable', fn (Blueprint $t) => $t->boolean('is_sellable')->default(true));
        self::columnIfMissing('product_variants', 'sales_enabled', fn (Blueprint $t) => $t->boolean('sales_enabled')->default(true));

        if (! Schema::hasTable('product_bundle_components')) {
            Schema::create('product_bundle_components', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('bundle_variant_id');
                $table->unsignedBigInteger('component_variant_id');
                $table->unsignedInteger('quantity');
                $table->string('discount_type')->default('percent');
                $table->unsignedBigInteger('discount_value')->default(0);
                $table->timestamps();
                $table->unique(['bundle_variant_id', 'component_variant_id']);
            });
        }

        if (! Schema::hasTable('bundle_component_allocations')) {
            Schema::create('bundle_component_allocations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('bundle_variant_id');
                $table->unsignedBigInteger('component_variant_id');
                $table->unsignedInteger('remaining')->default(0);
                $table->timestamps();
                $table->unique(['bundle_variant_id', 'component_variant_id']);
            });
        }
    }

    private static function columnIfMissing(string $table, string $column, callable $define): void
    {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException("Test fixture is missing the {$table} table.");
        }
        if (! Schema::hasColumn($table, $column)) {
            Schema::table($table, static function (Blueprint $schema) use ($define): void {
                $define($schema);
            });
        }
    }
}
