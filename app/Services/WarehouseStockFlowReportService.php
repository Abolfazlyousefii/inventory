<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Read-only warehouse counting support. Status totals are distinct buckets:
 * do not sum these with the existing reservation cache or call them
 * a confirmed physical stock balance.
 */
final class WarehouseStockFlowReportService
{
    public const BATCH_SIZE = 100;
    private const COLLECTION_STATUSES = [
        Invoice::STATUS_PENDING_COLLECTION,
        Invoice::STATUS_WAREHOUSE_RECEIVED,
        Invoice::STATUS_COLLECTING,
    ];

    public function rows(int $afterId, int $limit, string $search, bool $onlyInFlow): array
    {
        // Never use centralWarehouseId() here: it may create a warehouse.
        $warehouseId = (int) (Warehouse::query()
            ->where('type', 'central')->where('name', 'انبار مرکزی')->value('id') ?? 0);
        abort_if($warehouseId < 1, 503, 'انبار مرکزی تعریف نشده است؛ گزارش موجودی قابل اتکا نیست.');

        $query = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('model_lists as m', 'm.id', '=', 'v.model_list_id')
            ->leftJoin('colors as color', 'color.id', '=', 'v.color_id')
            ->where('v.id', '>', $afterId);
        if ($search !== '') {
            $escaped = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($q) use ($escaped) {
                $q->where('p.name', 'like', $escaped)
                    ->orWhere('v.variant_name', 'like', $escaped)
                    ->orWhere('v.variant_code', 'like', $escaped);
            });
        }

        $queueExists = static fn ($q) => $q->selectRaw('1')
            ->from('invoice_items as items')
            ->join('invoices as invoices', 'invoices.id', '=', 'items.invoice_id')
            ->whereColumn('items.variant_id', 'v.id')
            ->whereIn('invoices.status', self::COLLECTION_STATUSES)
            ->where('items.quantity', '>', 0);

        $holdExists = static fn ($q) => $q->selectRaw('1')
            ->from('preinvoice_draft_reservations as holds')
            ->join('preinvoice_orders as orders', 'orders.id', '=', 'holds.preinvoice_order_id')
            ->whereColumn('holds.variant_id', 'v.id')
            ->where('holds.reservation_scope', 'official')
            ->whereNull('holds.released_at')
            ->whereNull('holds.converted_at')
            ->whereNull('holds.release_reason')
            ->where('holds.quantity', '>', 0)
            ->whereIn('orders.status', PreinvoiceOrder::reservationHoldingStatuses())
            ->whereNotExists(fn ($i) => $i->selectRaw('1')
                ->from('invoices as invoice_check')
                ->whereColumn('invoice_check.preinvoice_order_id', 'orders.id'));

        if ($onlyInFlow) {
            $query->where(fn ($q) => $q->whereExists($queueExists)->orWhereExists($holdExists));
        } else {
            $query->where(function ($q) use ($warehouseId, $queueExists, $holdExists) {
                $q->whereExists(fn ($s) => $s->selectRaw('1')
                    ->from('warehouse_stocks as stock')
                    ->whereColumn('stock.product_variant_id', 'v.id')
                    ->where('stock.warehouse_id', $warehouseId)
                    ->where('stock.quantity', '>', 0))
                    ->orWhereExists($queueExists)
                    ->orWhereExists($holdExists);
            });
        }

        $variants = $query->orderBy('v.id')->limit($limit + 1)
            ->get([
                'v.id as variant_id', 'v.product_id', 'p.name as product_name',
                'v.variant_name', 'v.variant_code', 'v.variety_name',
                'm.model_name', 'color.name as color_name',
            ]);
        $hasMore = $variants->count() > $limit;
        $page = $variants->take($limit);
        $ids = $page->pluck('variant_id')->map(fn ($n) => (int) $n)->all();
        if ($ids === []) {
            return ['rows'=>[], 'next_cursor'=>$afterId, 'has_more'=>false];
        }

        $free = DB::table('warehouse_stocks')
            ->where('warehouse_id', $warehouseId)->whereIn('product_variant_id', $ids)
            ->select('product_variant_id')->selectRaw('SUM(quantity) as units')
            ->groupBy('product_variant_id')->pluck('units','product_variant_id');

        $holds = DB::table('preinvoice_draft_reservations as r')
            ->join('preinvoice_orders as o', 'o.id','=','r.preinvoice_order_id')
            ->whereIn('r.variant_id', $ids)
            ->where('r.reservation_scope','official')
            ->whereNull('r.converted_at')->whereNull('r.released_at')
            ->whereNull('r.release_reason')->where('r.quantity','>',0)
            ->whereIn('o.status', PreinvoiceOrder::reservationHoldingStatuses())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoices as i')
                ->whereColumn('i.preinvoice_order_id','o.id'))
            ->groupBy('r.variant_id')->select('r.variant_id')
            ->selectRaw('SUM(r.quantity) as units')
            ->pluck('units','variant_id');

        $queues = DB::table('invoice_items as ii')
            ->join('invoices as i','i.id','=','ii.invoice_id')
            ->whereIn('ii.variant_id',$ids)
            ->whereIn('i.status',self::COLLECTION_STATUSES)
            ->where('ii.quantity','>',0)
            ->groupBy('ii.variant_id')
            ->select('ii.variant_id')
            ->selectRaw('SUM(CASE WHEN i.status = ? THEN ii.quantity ELSE 0 END) AS pending_collection',
                [Invoice::STATUS_PENDING_COLLECTION])
            ->selectRaw('SUM(CASE WHEN i.status = ? THEN ii.quantity ELSE 0 END) AS warehouse_received',
                [Invoice::STATUS_WAREHOUSE_RECEIVED])
            ->selectRaw('SUM(CASE WHEN i.status = ? THEN ii.quantity ELSE 0 END) AS collecting',
                [Invoice::STATUS_COLLECTING])
            ->get()->keyBy('variant_id');

        return [
            'rows'=>$page->map(static function ($v) use ($free,$holds,$queues) {
                $queue = $queues->get($v->variant_id);
                $pending = (int) ($queue->pending_collection ?? 0);
                $received = (int) ($queue->warehouse_received ?? 0);
                $collecting = (int) ($queue->collecting ?? 0);
                return [
                    'product_id'=>(int)$v->product_id,
                    'variant_id'=>(int)$v->variant_id,
                    'product_name'=>(string)$v->product_name,
                    'variant_name'=>(string)($v->variant_name ?? ''),
                    'variant_code'=>(string)($v->variant_code ?? ''),
                    'details'=>implode(' / ',array_values(array_filter([
                        $v->model_name, $v->color_name, $v->variety_name,
                    ], static fn ($x) => $x !== null && trim((string)$x) !== ''))),
                    'available'=>(int)($free->get($v->variant_id) ?? 0),
                    'official_reserved'=>(int)($holds->get($v->variant_id) ?? 0),
                    'pending_collection'=>$pending,
                    'warehouse_received'=>$received,
                    'collecting'=>$collecting,
                    'queue_total'=>$pending+$received+$collecting,
                ];
            })->values()->all(),
            'next_cursor'=>(int)($page->last()?->variant_id ?? $afterId),
            'has_more'=>$hasMore,
        ];
    }

    /**
     * Identifies the specific documents contributing to the visible stock-flow
     * buckets. This query only runs after a user opens the detail drawer.
     * Document ID cursor prevents scanning/loading the whole invoice archive.
     *
     * @return array{rows: array<int,array<string,mixed>>, next_cursor:int, has_more:bool}
     */
    public function documentDetails(int $variantId, string $kind, int $afterId, int $limit = 50): array
    {
        abort_unless(DB::table('product_variants')->where('id', $variantId)->exists(), 404);

        if ($kind === 'queue') {
            $query = DB::table('invoice_items as ii')
                ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
                ->leftJoin('preinvoice_orders as o', 'o.id', '=', 'i.preinvoice_order_id')
                ->leftJoin('users as seller', 'seller.id', '=', DB::raw('COALESCE(i.seller_id, o.seller_id, o.created_by)'))
                ->where('ii.variant_id', $variantId)
                ->where('ii.quantity', '>', 0)
                ->whereIn('i.status', self::COLLECTION_STATUSES)
                ->where('i.id', '>', $afterId)
                ->groupBy('i.id', 'i.uuid', 'i.customer_name', 'i.status', 'seller.name')
                ->select([
                    'i.id as document_id', 'i.uuid as document_number',
                    'i.customer_name', 'i.status',
                    'seller.name as seller_name',
                ])
                ->selectRaw('SUM(ii.quantity) as quantity')
                ->orderBy('i.id');
            $labels = Invoice::statusLabels();
            $routeName = 'invoices.show';
        } else {
            // Mirror the exact official-hold predicates of rows(), including the
            // NOT EXISTS invoice guard. These are not counted again as queue.
            $query = DB::table('preinvoice_draft_reservations as r')
                ->join('preinvoice_orders as o', 'o.id', '=', 'r.preinvoice_order_id')
                ->leftJoin('users as seller', 'seller.id', '=', DB::raw('COALESCE(o.seller_id, o.created_by)'))
                ->where('r.variant_id', $variantId)
                ->where('r.reservation_scope', 'official')
                ->whereNull('r.converted_at')->whereNull('r.released_at')
                ->whereNull('r.release_reason')->where('r.quantity', '>', 0)
                ->whereIn('o.status', PreinvoiceOrder::reservationHoldingStatuses())
                ->whereNotExists(fn ($q) => $q->selectRaw('1')
                    ->from('invoices as linked_invoice')
                    ->whereColumn('linked_invoice.preinvoice_order_id', 'o.id'))
                ->where('o.id', '>', $afterId)
                ->groupBy('o.id', 'o.uuid', 'o.customer_name', 'o.status', 'seller.name')
                ->select([
                    'o.id as document_id', 'o.uuid as document_number',
                    'o.customer_name', 'o.status',
                    'seller.name as seller_name',
                ])
                ->selectRaw('SUM(r.quantity) as quantity')
                ->orderBy('o.id');
            $labels = PreinvoiceOrder::statusLabels();
            $routeName = 'archive.preinvoices.show';
        }

        $documents = $query->limit($limit + 1)->get();
        $hasMore = $documents->count() > $limit;
        $page = $documents->take($limit);
        return [
            'rows' => $page->map(static fn ($document) => [
                'document_id' => (int) $document->document_id,
                'document_number' => (string) $document->document_number,
                'document_url' => route($routeName, (string) $document->document_number),
                'customer_name' => (string) ($document->customer_name ?: 'نام ثبت نشده'),
                'seller_name' => (string) ($document->seller_name ?: 'ثبت نشده'),
                'status_label' => (string) ($labels[$document->status] ?? $document->status),
                'quantity' => (int) $document->quantity,
            ])->values()->all(),
            'next_cursor' => (int) ($page->last()?->document_id ?? $afterId),
            'has_more' => $hasMore,
        ];
    }

}
