<?php

namespace App\Services\Inventory;

use App\Models\PreinvoiceOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryReconciliationService
{
    public const VALID_INVOICE_STATUSES = ['pending_warehouse_approval', 'not_shipped', 'shipped'];
    public const ACTIVE_PREINVOICE_STATUSES = [
        PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE,
        PreinvoiceOrder::STATUS_WAREHOUSE_REVIEWING,
        PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE,
        PreinvoiceOrder::STATUS_FINANCE_REVIEWING,
        PreinvoiceOrder::STATUS_RETURNED_TO_WAREHOUSE,
        PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
    ];
    public const BLOCKING_FLAGS = ['reservation_without_purchase', 'sold_more_than_purchased', 'suspicious_reservation'];

    public function centralWarehouseId(): ?int
    {
        if (! Schema::hasTable('warehouses')) return null;
        return (int) (DB::table('warehouses')->where('type', 'central')->value('id') ?: DB::table('warehouses')->where('name', 'انبار مرکزی')->value('id')) ?: null;
    }

    public function invalidInvoiceStatuses(?int $productId = null): Collection
    {
        return DB::table('invoice_items AS ii')
            ->join('invoices AS i', 'i.id', '=', 'ii.invoice_id')
            ->whereNotNull('ii.variant_id')
            ->when($productId, fn ($q) => $q->where('ii.product_id', $productId))
            ->whereNotIn('i.status', self::VALID_INVOICE_STATUSES)
            ->select('i.status', DB::raw('COUNT(DISTINCT i.id) AS invoices_count'), DB::raw('SUM(ii.quantity) AS qty'))
            ->groupBy('i.status')->orderBy('i.status')->get();
    }


    public function activeReservationRowsForProduct(int $productId): Collection
    {
        return DB::table('preinvoice_order_items AS poi')
            ->join('preinvoice_orders AS po', 'po.id', '=', 'poi.preinvoice_order_id')
            ->where('poi.product_id', $productId)
            ->whereNotNull('poi.variant_id')
            ->whereNull('po.stock_released_at')
            ->whereIn('po.status', self::ACTIVE_PREINVOICE_STATUSES)
            ->get(['poi.id', 'poi.preinvoice_order_id', 'po.uuid', 'poi.variant_id', 'poi.quantity']);
    }

    public function hasReservationCalculationMismatchForProduct(int $productId, bool $excludeSuspiciousReservations = false): bool
    {
        $activeRows = $this->activeReservationRowsForProduct($productId);

        if ($activeRows->isNotEmpty()) {
            return false;
        }

        return $this->rows($productId, $excludeSuspiciousReservations)->contains(fn (array $row) => (int) $row['active_reserved_qty'] !== 0 || (int) $row['expected_reserved'] !== 0);
    }

    public function rows(?int $productId = null, bool $excludeSuspiciousReservations = false): Collection
    {
        $central = $this->centralWarehouseId();
        $purchase = $this->sumByVariant('purchase_items', 'product_variant_id', $productId);
        $invoice = $this->invoiceQty($productId);
        $reserved = $this->reservedQty($productId);
        $warehouse = $this->warehouseQty($central, $productId);
        $movement = $this->movementQty($productId);
        $prices = $this->priceSources($productId);
        $suspiciousReservations = $this->suspiciousReservations($productId);
        $suspicious = $suspiciousReservations['by_product'];
        $suspiciousQty = $suspiciousReservations['qty_by_variant'];

        return DB::table('product_variants AS pv')
            ->join('products AS p', 'p.id', '=', 'pv.product_id')
            ->when($productId, fn ($q) => $q->where('pv.product_id', $productId))
            ->orderBy('pv.product_id')->orderBy('pv.id')
            ->get(['pv.id AS variant_id','pv.product_id','p.name AS product_name','pv.variant_name','pv.stock','pv.reserved','pv.buy_price','pv.sell_price'])
            ->map(function ($r) use ($purchase, $invoice, $reserved, $warehouse, $movement, $prices, $suspicious, $suspiciousQty, $excludeSuspiciousReservations) {
                $vid = (int) $r->variant_id; $pid = (int) $r->product_id;
                $pq = (int) ($purchase[$vid] ?? 0); $iq = (int) ($invoice[$vid] ?? 0);
                $rawReserved = (int) ($reserved[$vid] ?? 0); $ignoredSuspiciousReserved = (int) ($suspiciousQty[$vid]['qty'] ?? 0);
                $rq = $excludeSuspiciousReservations ? max(0, $rawReserved - $ignoredSuspiciousReserved) : $rawReserved;
                $expected = ($pq === 0 && $iq === 0) ? 0 : $pq - $iq - $rq;
                $wh = (int) ($warehouse[$vid] ?? 0); $mov = $movement[$vid] ?? ['in'=>0,'out'=>0];
                $flags = [];
                if ((int) $r->stock >= 29900 && (int) $r->stock <= 30100) $flags[] = 'fake_30000';
                if ($pq === 0 && ((int) $r->stock > 0 || $wh > 0)) $flags[] = 'no_purchase_but_stock';
                if ($pq === 0 && $rq > 0) $flags[] = 'reservation_without_purchase';
                if ($iq > $pq || $expected < 0) $flags[] = 'sold_more_than_purchased';
                if (isset($suspicious[$pid])) $flags[] = 'suspicious_reservation';
                if ($wh !== $expected) $flags[] = 'warehouse_mismatch';
                if (((int) $mov['in'] - (int) $mov['out']) !== ($pq - $iq)) $flags[] = 'cartax_mismatch';
                if ((int) $r->sell_price <= 0) $flags[] = 'missing_sell_price';
                $price = $prices[$vid] ?? null;
                if ((int) $r->sell_price <= 0 && ! $price) $flags[] = 'missing_sell_price_source';
                $blockingFlags = $excludeSuspiciousReservations ? array_diff(self::BLOCKING_FLAGS, ['suspicious_reservation']) : self::BLOCKING_FLAGS;
                $blocked = $expected < 0 || count(array_intersect($flags, $blockingFlags)) > 0;
                return [
                    'variant_id'=>$vid,'product_id'=>$pid,'product_name'=>$r->product_name,'variant_name'=>$r->variant_name,
                    'current_stock'=>(int)$r->stock,'current_reserved'=>(int)$r->reserved,'warehouse_stock'=>$wh,'current_sell_price'=>(int)$r->sell_price,
                    'purchase_qty'=>$pq,'invoice_qty'=>$iq,'active_reserved_qty'=>$rq,'expected_stock'=>$expected,'expected_reserved'=>$rq,
                    'difference'=>$expected - (int)$r->stock,'movement_in'=>(int)$mov['in'],'movement_out'=>(int)$mov['out'],'movement_net'=>(int)$mov['in']-(int)$mov['out'],'document_net'=>$pq-$iq,
                    'expected_buy_price'=>$price['buy_price'] ?? null,'expected_sell_price'=>$price['sell_price'] ?? null,'price_source'=>$price['source'] ?? null,'has_purchase_sell_price_source'=>($price['source'] ?? null) === 'purchase_item',
                    'flags'=>$flags,'flags_text'=>implode(',', $flags),'blocked'=>$blocked,'suspicious_preinvoices'=>$suspicious[$pid] ?? '',
                    'ignored_suspicious_reservation'=>$excludeSuspiciousReservations ? ($suspiciousQty[$vid]['text'] ?? '') : '',
                ];
            });
    }

    public function cleanupNoPurchaseNoPriceRows(Collection $rows): Collection
    {
        return $rows->filter(function (array $row) {
            return (int) $row['purchase_qty'] === 0
                && ((int) $row['current_stock'] > 0 || (int) $row['warehouse_stock'] > 0)
                && (int) $row['current_sell_price'] <= 0;
        })->map(function (array $row) {
            $flags = ['cleanup_no_purchase_no_price'];
            if ((int) $row['invoice_qty'] > 0) {
                $flags[] = 'sold_without_purchase_left_for_manual_review';
            }
            $row['cleanup_flags'] = $flags;
            $row['cleanup_flags_text'] = implode(',', $flags);
            return $row;
        })->values();
    }

    public function summary(Collection $rows, ?Collection $cleanupRows = null): array
    {
        $cleanupRows ??= collect();
        $countFlag = fn ($f) => $rows->filter(fn ($r) => in_array($f, $r['flags'], true))->count();
        return [
            'total_variants'=>$rows->count(), 'safe_to_apply_count'=>$rows->where('blocked', false)->count(), 'blocked_conflicts_count'=>$rows->where('blocked', true)->count(),
            'fake_30000_count'=>$countFlag('fake_30000'), 'no_purchase_but_stock_count'=>$countFlag('no_purchase_but_stock'), 'reservation_without_purchase_count'=>$countFlag('reservation_without_purchase'),
            'sold_more_than_purchased_count'=>$countFlag('sold_more_than_purchased'), 'suspicious_reservation_count'=>$countFlag('suspicious_reservation'), 'missing_sell_price_count'=>$countFlag('missing_sell_price'), 'cleanup_no_purchase_no_price_count'=>$cleanupRows->count(), 'cleanup_no_purchase_no_price_with_invoice_count'=>$cleanupRows->filter(fn ($r) => (int) $r['invoice_qty'] > 0)->count(),
        ];
    }

    private function sumByVariant(string $table, string $variantCol, ?int $productId): array
    { return DB::table($table)->select($variantCol, DB::raw('SUM(quantity) qty'))->whereNotNull($variantCol)->when($productId, fn($q)=>$q->where('product_id',$productId))->groupBy($variantCol)->pluck('qty',$variantCol)->map(fn($v)=>(int)$v)->all(); }

    private function invoiceQty(?int $productId): array
    { return DB::table('invoice_items AS ii')->join('invoices AS i','i.id','=','ii.invoice_id')->whereNotNull('ii.variant_id')->whereIn('i.status', self::VALID_INVOICE_STATUSES)->when($productId, fn($q)=>$q->where('ii.product_id',$productId))->select('ii.variant_id',DB::raw('SUM(ii.quantity) qty'))->groupBy('ii.variant_id')->pluck('qty','variant_id')->map(fn($v)=>(int)$v)->all(); }

    private function reservedQty(?int $productId): array
    { return DB::table('preinvoice_order_items AS poi')->join('preinvoice_orders AS po','po.id','=','poi.preinvoice_order_id')->whereNotNull('poi.variant_id')->whereNull('po.stock_released_at')->whereIn('po.status', self::ACTIVE_PREINVOICE_STATUSES)->when($productId, fn($q)=>$q->where('poi.product_id',$productId))->select('poi.variant_id',DB::raw('SUM(poi.quantity) qty'))->groupBy('poi.variant_id')->pluck('qty','variant_id')->map(fn($v)=>(int)$v)->all(); }

    private function warehouseQty(?int $central, ?int $productId): array
    { if(!$central) return []; return DB::table('warehouse_stocks')->where('warehouse_id',$central)->whereNotNull('product_variant_id')->when($productId, fn($q)=>$q->where('product_id',$productId))->select('product_variant_id',DB::raw('SUM(quantity) qty'))->groupBy('product_variant_id')->pluck('qty','product_variant_id')->map(fn($v)=>(int)$v)->all(); }

    private function movementQty(?int $productId): array
    { if(!Schema::hasTable('stock_movements') || !Schema::hasColumn('stock_movements','product_variant_id')) return []; return DB::table('stock_movements')->whereNotNull('product_variant_id')->when($productId, fn($q)=>$q->where('product_id',$productId))->select('product_variant_id', DB::raw("SUM(CASE WHEN type = 'in' THEN quantity ELSE 0 END) movement_in"), DB::raw("SUM(CASE WHEN type = 'out' THEN quantity ELSE 0 END) movement_out"))->groupBy('product_variant_id')->get()->mapWithKeys(fn($r)=>[(int)$r->product_variant_id=>['in'=>(int)$r->movement_in,'out'=>(int)$r->movement_out]])->all(); }

    private function priceSources(?int $productId): array
    {
        $out = [];
        DB::table('purchase_items')->whereNotNull('product_variant_id')->where('sell_price','>',0)->when($productId, fn($q)=>$q->where('product_id',$productId))->orderByDesc('id')->get(['product_variant_id','buy_price','sell_price'])->each(function($r) use (&$out){ $out[$r->product_variant_id] ??= ['buy_price'=>(int)$r->buy_price,'sell_price'=>(int)$r->sell_price,'source'=>'purchase_item']; });
        DB::table('invoice_items')->whereNotNull('variant_id')->where('price','>',0)->when($productId, fn($q)=>$q->where('product_id',$productId))->orderByDesc('id')->get(['variant_id','price'])->each(function($r) use (&$out){ $out[$r->variant_id] ??= ['buy_price'=>null,'sell_price'=>(int)$r->price,'source'=>'invoice_item']; });
        return $out;
    }

    private function suspiciousReservations(?int $productId): array
    {
        $variantCounts = DB::table('product_variants')->when($productId, fn($q)=>$q->where('product_id',$productId))->select('product_id',DB::raw('COUNT(*) c'))->groupBy('product_id')->pluck('c','product_id');
        $rows = DB::table('preinvoice_order_items AS poi')->join('preinvoice_orders AS po','po.id','=','poi.preinvoice_order_id')->whereNotNull('poi.variant_id')->whereNull('po.stock_released_at')->whereIn('po.status', self::ACTIVE_PREINVOICE_STATUSES)->when($productId, fn($q)=>$q->where('poi.product_id',$productId))->select('poi.product_id','po.id','po.uuid','poi.quantity',DB::raw('COUNT(DISTINCT poi.variant_id) variants'))->groupBy('poi.product_id','po.id','po.uuid','poi.quantity')->get();
        $byProduct=[]; $qtyByVariant=[];
        foreach($rows as $r){
            $total=(int)($variantCounts[$r->product_id]??0);
            if($total<=1 || (int)$r->variants < max(2, (int)ceil($total*0.8))) continue;
            $text = "#{$r->id}/{$r->uuid}/qty:{$r->quantity}";
            $byProduct[(int)$r->product_id][] = $text;
            DB::table('preinvoice_order_items')->where('preinvoice_order_id',$r->id)->where('product_id',$r->product_id)->where('quantity',$r->quantity)->whereNotNull('variant_id')->get(['variant_id','quantity'])->each(function($item) use (&$qtyByVariant, $text){
                $variantId = (int) $item->variant_id;
                $qtyByVariant[$variantId]['qty'] = (int)($qtyByVariant[$variantId]['qty'] ?? 0) + (int)$item->quantity;
                $qtyByVariant[$variantId]['text'] = trim(($qtyByVariant[$variantId]['text'] ?? '').'; '.$text, '; ');
            });
        }
        return ['by_product'=>array_map(fn($v)=>implode('; ', $v), $byProduct), 'qty_by_variant'=>$qtyByVariant];
    }
}
