<?php

namespace App\Services\Inventory;

use App\Models\PreinvoiceOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryCorruptionAuditService
{
    public const CORRUPTION_BASE = 30023;
    public const CORRUPTED_STOCK_VALUES = [30003, 30023];

    public const ACTIVE_PREINVOICE_STATUSES = [
        PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE,
        PreinvoiceOrder::STATUS_WAREHOUSE_REVIEWING,
        PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE,
        PreinvoiceOrder::STATUS_FINANCE_REVIEWING,
        PreinvoiceOrder::STATUS_RETURNED_TO_WAREHOUSE,
        PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
    ];

    public function rows(?int $productId = null): Collection
    {
        $variantQuery = DB::table('product_variants AS pv')
            ->join('products AS p', 'p.id', '=', 'pv.product_id')
            ->when($productId, fn ($query) => $query->where('pv.product_id', $productId))
            ->orderBy('pv.product_id')
            ->orderBy('pv.id');

        $centralWarehouseId = $this->centralWarehouseId();
        $purchaseQty = $this->purchaseQuantities();
        $invoiceQty = $this->invoiceQuantities();
        $activeReservedQty = $this->activeReservationQuantities();
        $warehouseQty = $this->warehouseQuantities($centralWarehouseId);
        $lastValidPurchase = $this->lastValidPurchaseRows();
        $unmappedSalesByProduct = $this->unmappedProductLevelSales();

        return $variantQuery->get([
                'pv.id AS variant_id',
                'pv.product_id',
                'p.name AS product_name',
                'pv.variant_name',
                'pv.variant_code',
                'pv.stock AS current_product_variant_stock',
                'pv.reserved AS current_product_variant_reserved',
                'pv.buy_price AS current_buy_price',
                'pv.sell_price AS current_sell_price',
            ])
            ->map(function ($row) use ($purchaseQty, $invoiceQty, $activeReservedQty, $warehouseQty, $lastValidPurchase, $unmappedSalesByProduct) {
                $variantId = (int) $row->variant_id;
                $productId = (int) $row->product_id;
                $purchase = (int) ($purchaseQty[$variantId] ?? 0);
                $invoice = (int) ($invoiceQty[$variantId] ?? 0);
                $activeReserved = (int) ($activeReservedQty[$variantId] ?? 0);
                $expectedAvailable = $purchase - $invoice - $activeReserved;
                $currentStock = (int) $row->current_product_variant_stock;
                $currentReserved = (int) $row->current_product_variant_reserved;
                $currentWarehouse = (int) ($warehouseQty[$variantId] ?? 0);
                $lastPurchase = $lastValidPurchase[$variantId] ?? null;
                $unmappedSale = (int) ($unmappedSalesByProduct[$productId] ?? 0);

                $notes = [];
                if ($expectedAvailable < 0) {
                    $notes[] = 'conflict: expected_available_stock is negative';
                }
                if (! $lastPurchase) {
                    $notes[] = 'no valid purchase price';
                }
                if ($unmappedSale > 0) {
                    $notes[] = 'unmapped product-level sale exists; not applied to variants';
                }

                $hasCorruptionBase = in_array($currentStock + $currentReserved, [self::CORRUPTION_BASE, self::CORRUPTION_BASE - 20 + 20], true)
                    || in_array($currentStock, self::CORRUPTED_STOCK_VALUES, true)
                    || ($currentWarehouse > 0 && in_array($currentWarehouse + $activeReserved, [self::CORRUPTION_BASE], true));

                $hasNoPurchasePositiveStock = $purchase === 0 && ($currentStock > 0 || $currentWarehouse > 0);
                if ($hasNoPurchasePositiveStock) {
                    $notes[] = 'no purchase but positive stock';
                }

                return [
                    'product_id' => $productId,
                    'product_name' => $row->product_name,
                    'variant_id' => $variantId,
                    'variant_name' => $row->variant_name,
                    'variant_code' => $row->variant_code,
                    'current_product_variant_stock' => $currentStock,
                    'current_product_variant_reserved' => $currentReserved,
                    'current_warehouse_stock' => $currentWarehouse,
                    'purchase_qty_by_variant' => $purchase,
                    'invoice_qty_by_variant' => $invoice,
                    'active_reserved_qty_by_variant' => $activeReserved,
                    'expected_available_stock' => $expectedAvailable,
                    'expected_reserved' => $activeReserved,
                    'current_buy_price' => $row->current_buy_price,
                    'current_sell_price' => $row->current_sell_price,
                    'last_valid_purchase_item_id' => $lastPurchase?->id,
                    'expected_buy_price' => $lastPurchase?->buy_price,
                    'expected_sell_price' => $lastPurchase?->sell_price,
                    'has_corruption_30023_pattern' => $hasCorruptionBase ? 'yes' : 'no',
                    'has_no_purchase_but_positive_stock' => $hasNoPurchasePositiveStock ? 'yes' : 'no',
                    'has_unmapped_product_level_sale' => $unmappedSale > 0 ? 'yes' : 'no',
                    'unmapped_product_level_sale_qty' => $unmappedSale,
                    'notes' => implode('; ', array_unique($notes)),
                ];
            });
    }

    public function inactiveDraftReservationIds(?int $productId = null): Collection
    {
        return DB::table('preinvoice_draft_reservations AS pdr')
            ->leftJoin('preinvoice_orders AS po', 'po.id', '=', 'pdr.preinvoice_order_id')
            ->whereNull('pdr.preinvoice_order_id')
            ->whereNull('pdr.converted_at')
            ->whereNotNull('pdr.expires_at')
            ->where('pdr.expires_at', '<=', now())
            ->when($productId, fn ($query) => $query->where('pdr.product_id', $productId))
            ->pluck('pdr.id');
    }

    public function centralWarehouseId(): ?int
    {
        if (! Schema::hasTable('warehouses')) {
            return null;
        }

        $query = DB::table('warehouses');
        if (Schema::hasColumn('warehouses', 'type')) {
            $id = (clone $query)->where('type', 'central')->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        return DB::table('warehouses')->where('name', 'انبار مرکزی')->value('id') ?: null;
    }

    private function purchaseQuantities(): array
    {
        return DB::table('purchase_items')
            ->select('product_variant_id', DB::raw('SUM(quantity) AS qty'))
            ->whereNotNull('product_variant_id')
            ->groupBy('product_variant_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->product_variant_id => (int) $row->qty])
            ->all();
    }

    private function invoiceQuantities(): array
    {
        return DB::table('invoice_items AS ii')
            ->select('ii.variant_id', DB::raw('SUM(ii.quantity) AS qty'))
            ->join('invoices AS i', 'i.id', '=', 'ii.invoice_id')
            ->whereNotNull('ii.variant_id')
            ->whereNotIn('i.status', ['not_shipped', 'canceled', 'cancelled'])
            ->groupBy('ii.variant_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->variant_id => (int) $row->qty])
            ->all();
    }

    private function activeReservationQuantities(): array
    {
        return DB::table('preinvoice_order_items AS poi')
            ->select('poi.variant_id', DB::raw('SUM(poi.quantity) AS qty'))
            ->join('preinvoice_orders AS po', 'po.id', '=', 'poi.preinvoice_order_id')
            ->whereNotNull('poi.variant_id')
            ->whereIn('po.status', self::ACTIVE_PREINVOICE_STATUSES)
            ->whereNull('po.stock_released_at')
            ->groupBy('poi.variant_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->variant_id => (int) $row->qty])
            ->all();
    }

    private function warehouseQuantities(?int $centralWarehouseId): array
    {
        if (! $centralWarehouseId) {
            return [];
        }

        return DB::table('warehouse_stocks')
            ->select('product_variant_id', DB::raw('SUM(quantity) AS qty'))
            ->where('warehouse_id', $centralWarehouseId)
            ->whereNotNull('product_variant_id')
            ->groupBy('product_variant_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->product_variant_id => (int) $row->qty])
            ->all();
    }

    private function lastValidPurchaseRows(): array
    {
        $lastIds = DB::table('purchase_items')
            ->select('product_variant_id', DB::raw('MAX(id) AS max_id'))
            ->whereNotNull('product_variant_id')
            ->where('buy_price', '>', 0)
            ->where('sell_price', '>', 0)
            ->groupBy('product_variant_id');

        return DB::table('purchase_items AS pi')
            ->joinSub($lastIds, 'last_pi', fn ($join) => $join->on('last_pi.max_id', '=', 'pi.id'))
            ->get(['pi.id', 'pi.product_variant_id', 'pi.buy_price', 'pi.sell_price'])
            ->keyBy('product_variant_id')
            ->all();
    }

    private function unmappedProductLevelSales(): array
    {
        if (! Schema::hasColumn('stock_movements', 'product_variant_id')) {
            return [];
        }

        return DB::table('stock_movements')
            ->select('product_id', DB::raw('SUM(quantity) AS qty'))
            ->whereNull('product_variant_id')
            ->where('type', 'out')
            ->where(function ($query) {
                $query->where('reason', 'sale')->orWhere('reason', 'like', '%sale%');
            })
            ->groupBy('product_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->product_id => (int) $row->qty])
            ->all();
    }
}
