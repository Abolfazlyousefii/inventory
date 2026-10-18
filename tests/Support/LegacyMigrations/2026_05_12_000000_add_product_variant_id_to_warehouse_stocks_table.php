<?php

use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_stocks', function (Blueprint $table) {
            if (!Schema::hasColumn('warehouse_stocks', 'product_variant_id')) {
                $table->foreignId('product_variant_id')
                    ->nullable()
                    ->after('product_id')
                    ->constrained('product_variants')
                    ->nullOnDelete();
            }
        });

        // Old structure usually had one row per warehouse/product.
        // New structure must have one row per warehouse/variant.
        try {
            Schema::table('warehouse_stocks', function (Blueprint $table) {
                $table->dropUnique('warehouse_stocks_warehouse_id_product_id_unique');
            });
        } catch (\Throwable $e) {
            // Index may have a different name or may not exist.
        }

        // Auto-attach old stock rows only when the product has exactly one variant.
        DB::transaction(function () {
            WarehouseStock::query()
                ->whereNull('product_variant_id')
                ->orderBy('id')
                ->chunkById(200, function ($stocks) {
                    foreach ($stocks as $stock) {
                        $variants = ProductVariant::query()
                            ->where('product_id', $stock->product_id)
                            ->get(['id']);

                        if ($variants->count() === 1) {
                            $stock->update([
                                'product_variant_id' => $variants->first()->id,
                            ]);
                        }
                    }
                });
        });

        Schema::table('warehouse_stocks', function (Blueprint $table) {
            $table->unique(
                ['warehouse_id', 'product_variant_id'],
                'warehouse_stocks_warehouse_variant_unique'
            );

            $table->index(
                ['warehouse_id', 'product_id', 'product_variant_id'],
                'warehouse_stocks_wh_product_variant_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_stocks', function (Blueprint $table) {
            try {
                $table->dropUnique('warehouse_stocks_warehouse_variant_unique');
            } catch (\Throwable $e) {
            }

            try {
                $table->dropIndex('warehouse_stocks_wh_product_variant_index');
            } catch (\Throwable $e) {
            }

            if (Schema::hasColumn('warehouse_stocks', 'product_variant_id')) {
                $table->dropConstrainedForeignId('product_variant_id');
            }
        });
    }
};
