<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ZeroInventoryStockCommand extends Command
{
    protected $signature = 'inventory:zero-stock {product_code : Product code to zero, for example 030023}';

    protected $description = 'Set stock and quantity fields to zero for one product code across inventory tables.';

    public function handle(): int
    {
        $this->error('This command is disabled because it can overwrite quantities in historical inventory and financial documents.');
        $this->line('Use a traceable inventory adjustment document instead of inventory:zero-stock.');

        return self::FAILURE;
    }

    private function matchingProductIds(string $productCode): array
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'code')) {
            return [];
        }

        return DB::table('products')
            ->where('code', $productCode)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function matchingVariantIds(array $productIds): array
    {
        if ($productIds === [] || ! Schema::hasTable('product_variants') || ! Schema::hasColumn('product_variants', 'product_id')) {
            return [];
        }

        return DB::table('product_variants')
            ->whereIn('product_id', $productIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function updatePlans(string $productCode, array $productIds, array $variantIds): array
    {
        return [
            ['table' => 'products', 'columns' => ['stock'], 'where' => ['code', $productCode]],
            ['table' => 'product_variants', 'columns' => ['stock'], 'whereIn' => ['product_id', $productIds]],
            ['table' => 'purchase_items', 'columns' => ['quantity'], 'where' => ['product_code', $productCode]],
            ['table' => 'sales_items', 'columns' => ['quantity', 'stock'], 'where' => ['product_code', $productCode]],
            ['table' => 'stock_items', 'columns' => ['quantity', 'stock'], 'where' => ['product_code', $productCode]],
            ['table' => 'invoice_items', 'columns' => ['quantity'], 'whereIn' => ['product_id', $productIds]],
            ['table' => 'sales_items', 'columns' => ['quantity', 'stock'], 'whereIn' => ['product_id', $productIds]],
            ['table' => 'stock_items', 'columns' => ['quantity', 'stock'], 'whereIn' => ['product_id', $productIds]],
            ['table' => 'preinvoice_order_items', 'columns' => ['quantity'], 'whereIn' => ['product_id', $productIds]],
            ['table' => 'warehouse_stocks', 'columns' => ['quantity'], 'whereIn' => ['product_id', $productIds]],
            ['table' => 'warehouse_location_stocks', 'columns' => ['quantity'], 'whereIn' => ['product_variant_id', $variantIds]],
            ['table' => 'warehouse_location_movements', 'columns' => ['quantity'], 'whereIn' => ['product_variant_id', $variantIds]],
            ['table' => 'warehouse_transfer_items', 'columns' => ['quantity'], 'whereIn' => ['product_id', $productIds]],
            ['table' => 'stock_movements', 'columns' => ['quantity', 'stock_before', 'stock_after'], 'whereIn' => ['product_id', $productIds]],
            ['table' => 'stock_count_document_items', 'columns' => ['system_quantity', 'actual_quantity', 'difference_quantity'], 'whereIn' => ['product_id', $productIds]],
        ];
    }

    private function applyPlan(array $plan, array $columns): int
    {
        $query = DB::table($plan['table']);

        if (isset($plan['where'])) {
            [$column, $value] = $plan['where'];

            if (! Schema::hasColumn($plan['table'], $column)) {
                return 0;
            }

            $query->where($column, $value);
        } elseif (isset($plan['whereIn'])) {
            [$column, $values] = $plan['whereIn'];

            if ($values === [] || ! Schema::hasColumn($plan['table'], $column)) {
                return 0;
            }

            $query->whereIn($column, $values);
        } else {
            return 0;
        }

        $updates = array_fill_keys($columns, 0);

        if (Schema::hasColumn($plan['table'], 'updated_at')) {
            $updates['updated_at'] = now();
        }

        return $query->update($updates);
    }
}
