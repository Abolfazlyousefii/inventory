<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verify the already-shipped, still-pending production migration using only
 * temporary SQLite fixtures. Never connect to real warehouse stock.
 */
final class StocktakeSnapshotSchemaMigrationTest extends TestCase
{
    private const MIGRATION = '2026_10_29_000001_add_bundle_snapshots_to_stock_count_items.php';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stock_count_document_items', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->integer('actual_quantity')->nullable();
        });

        DB::table('stock_count_document_items')->insert([
            ['id' => 1, 'document_id' => 209, 'product_variant_id' => 11, 'actual_quantity' => 0],
            ['id' => 2, 'document_id' => 210, 'product_variant_id' => 12, 'actual_quantity' => 6],
            ['id' => 3, 'document_id' => 211, 'product_variant_id' => 13, 'actual_quantity' => 0],
            ['id' => 4, 'document_id' => 212, 'product_variant_id' => 14, 'actual_quantity' => 1],
            ['id' => 5, 'document_id' => 300, 'product_variant_id' => 15, 'actual_quantity' => null],
        ]);
    }

    private function migrate(): object
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        $migration->up();

        return $migration;
    }

    private function oldCounts(): string
    {
        return DB::table('stock_count_document_items')
            ->orderBy('id')
            ->get(['id', 'document_id', 'product_variant_id', 'actual_quantity'])
            ->toJson();
    }

    public function test_migration_adds_all_three_protected_quantity_columns_without_rewriting_old_counts(): void
    {
        $before = $this->oldCounts();

        $this->migrate();

        foreach (['bundle_free_at_start', 'bundle_reserved_at_start', 'site_held_at_start'] as $column) {
            $this->assertTrue(Schema::hasColumn('stock_count_document_items', $column), $column);
            $this->assertSame(0, (int) DB::table('stock_count_document_items')->where('id', 1)->value($column));
        }

        $this->assertSame($before, $this->oldCounts());
        $this->assertNull(DB::table('stock_count_document_items')->where('id', 5)->value('actual_quantity'));
        $this->assertSame(0, (int) DB::table('stock_count_document_items')->where('id', 1)->value('actual_quantity'));
    }

    public function test_migration_can_run_again_without_overwriting_new_snapshot_values(): void
    {
        $migration = $this->migrate();
        DB::table('stock_count_document_items')->where('id', 2)->update([
            'bundle_free_at_start' => 7,
            'bundle_reserved_at_start' => 4,
            'site_held_at_start' => 2,
        ]);
        $counts = $this->oldCounts();

        $migration->up();

        $this->assertSame($counts, $this->oldCounts());
        $this->assertSame(
            [7, 4, 2],
            array_map('intval', (array) DB::table('stock_count_document_items')
                ->where('id', 2)->first([
                    'bundle_free_at_start',
                    'bundle_reserved_at_start',
                    'site_held_at_start',
                ])),
        );
    }

    public function test_partially_present_columns_are_preserved_without_duplicate_column_failure(): void
    {
        Schema::table('stock_count_document_items', static function (Blueprint $table): void {
            $table->unsignedInteger('bundle_free_at_start')->default(0);
        });
        DB::table('stock_count_document_items')->where('id', 3)->update([
            'bundle_free_at_start' => 11,
        ]);

        $this->migrate();

        $this->assertSame(11, (int) DB::table('stock_count_document_items')
            ->where('id', 3)->value('bundle_free_at_start'));
        $this->assertTrue(Schema::hasColumn('stock_count_document_items', 'bundle_reserved_at_start'));
        $this->assertTrue(Schema::hasColumn('stock_count_document_items', 'site_held_at_start'));
    }

    public function test_rollback_does_not_remove_historical_snapshot_columns_or_counts(): void
    {
        $migration = $this->migrate();
        $before = $this->oldCounts();

        $migration->down();

        $this->assertSame($before, $this->oldCounts());
        foreach (['bundle_free_at_start', 'bundle_reserved_at_start', 'site_held_at_start'] as $column) {
            $this->assertTrue(Schema::hasColumn('stock_count_document_items', $column), $column);
        }
    }
}
