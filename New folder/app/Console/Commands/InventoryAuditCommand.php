<?php

namespace App\Console\Commands;

use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InventoryAuditCommand extends Command
{
    protected $signature = 'inventory:audit {--limit=200 : Maximum number of variants to display}';

    protected $description = 'Report product-variant stock and price mismatches without changing data.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $rows = ProductVariant::query()
            ->with(['product:id,name'])
            ->withSum('warehouseStocks as warehouse_quantity_sum', 'quantity')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(function (ProductVariant $variant) {
                $lastValidPurchase = DB::table('purchase_items')
                    ->where('product_variant_id', $variant->id)
                    ->where('buy_price', '>', 0)
                    ->where('sell_price', '>', 0)
                    ->orderByDesc('id')
                    ->first(['id', 'buy_price', 'sell_price', 'quantity', 'created_at']);

                $lastMovement = DB::table('stock_movements')
                    ->where('product_id', $variant->product_id)
                    ->when(DB::getSchemaBuilder()->hasColumn('stock_movements', 'product_variant_id'), function ($query) use ($variant) {
                        $query->where('product_variant_id', $variant->id);
                    })
                    ->orderByDesc('id')
                    ->first(['id', 'type', 'reason', 'quantity', 'stock_before', 'stock_after', 'created_at']);

                $warehouseQty = (int) ($variant->warehouse_quantity_sum ?? 0);
                $expectedBuy = $lastValidPurchase?->buy_price;
                $expectedSell = $lastValidPurchase?->sell_price;

                return [
                    'variant_id' => $variant->id,
                    'product_id' => $variant->product_id,
                    'product' => $variant->product?->name,
                    'variant' => $variant->variant_name,
                    'variant_stock' => (int) $variant->stock,
                    'reserved' => (int) $variant->reserved,
                    'warehouse_sum' => $warehouseQty,
                    'last_valid_purchase' => $lastValidPurchase?->id,
                    'buy_price' => (int) $variant->buy_price,
                    'sell_price' => (int) $variant->sell_price,
                    'expected_buy' => $expectedBuy,
                    'expected_sell' => $expectedSell,
                    'price_diff' => ((int) $variant->buy_price !== (int) $expectedBuy) || ((int) $variant->sell_price !== (int) $expectedSell) ? 'yes' : 'no',
                    'stock_diff' => (int) $variant->stock !== $warehouseQty ? (int) $variant->stock - $warehouseQty : 0,
                    'last_movement' => $lastMovement ? sprintf('#%s %s/%s qty:%s %s', $lastMovement->id, $lastMovement->type, $lastMovement->reason, $lastMovement->quantity, $lastMovement->created_at) : null,
                    'updated_at' => optional($variant->updated_at)->toDateTimeString(),
                ];
            });

        $this->table(array_keys($rows->first() ?? ['variant_id' => null]), $rows->all());

        return self::SUCCESS;
    }
}
