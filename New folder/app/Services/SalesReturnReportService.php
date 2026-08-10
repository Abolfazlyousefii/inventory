<?php

namespace App\Services;

use App\Models\{SalesReturnDocument, SalesReturnDocumentItem, WarehouseTransfer};
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Morilog\Jalali\Jalalian;

class SalesReturnReportService extends SalesReturnQueryService
{
    public function buildReportQuery(array $filters = []): Collection
    {
        return $this->getOfficialRows($filters);
    }

    public function getPaginatedRows(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $perPage = max(20, $perPage);
        $docTerm = trim((string) ($filters['document_number'] ?? ''));
        $like = $docTerm !== '' ? '%'.str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $docTerm).'%' : null;
        $from = $this->dateBoundary($filters['date_from'] ?? null);
        $to = $this->dateBoundary($filters['date_to'] ?? null, true);

        $new = DB::table('sales_return_documents as d')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->where('d.status', '!=', SalesReturnDocument::STATUS_CANCELLED)
            ->selectRaw("'new' as source, d.id as source_id, d.document_number, d.customer_id, '' as customer_name, COALESCE(d.applied_at, d.created_at) as canonical_date, d.status, d.total_quantity, d.total_refund_amount as total_amount, CASE WHEN d.document_number = ? THEN 1 ELSE 0 END as exact_rank", [$docTerm])
            ->when($like, fn($q) => $q->where('d.document_number', 'like', $like))
            ->when(($filters['status'] ?? null) && ($filters['status'] ?? 'all') !== 'all', fn($q) => $q->where('d.status', $filters['status']))
            ->when(($filters['official_only'] ?? false), fn($q) => $q->where('d.status', SalesReturnDocument::STATUS_APPLIED)->whereNotExists(function($sub){ $sub->selectRaw('1')->from('warehouse_transfers as dup')->whereColumn('dup.reference', 'd.reference_number')->whereNotNull('d.reference_number')->where('dup.voucher_type', WarehouseTransfer::TYPE_CUSTOMER_RETURN); }))
            ->when($filters['customer_name'] ?? null, fn($q,$v) => $q->where(fn($name) => $name->where('c.first_name', 'like', "%{$v}%")->orWhere('c.last_name', 'like', "%{$v}%")))
            ->when($filters['customer_id'] ?? null, fn($q,$v) => $q->where('d.customer_id', $v))
            ->when($from, fn($q,$d) => $q->whereRaw('COALESCE(d.applied_at, d.created_at) >= ?', [$d]))
            ->when($to, fn($q,$d) => $q->whereRaw('COALESCE(d.applied_at, d.created_at) <= ?', [$d]));

        $legacy = DB::table('warehouse_transfers as w')
            ->leftJoin('customers as c', 'c.id', '=', 'w.customer_id')
            ->leftJoin('warehouse_transfer_items as wi', 'wi.warehouse_transfer_id', '=', 'w.id')
            ->where('w.voucher_type', WarehouseTransfer::TYPE_CUSTOMER_RETURN)
            ->selectRaw("'legacy' as source, w.id as source_id, COALESCE(w.reference, CAST(w.id as CHAR)) as document_number, w.customer_id, COALESCE(w.beneficiary_name, '') as customer_name, COALESCE(w.transferred_at, w.created_at) as canonical_date, 'legacy' as status, COALESCE(SUM(wi.quantity),0) as total_quantity, COALESCE(w.total_amount, SUM(wi.line_total),0) as total_amount, CASE WHEN w.reference = ? OR CAST(w.id as CHAR) = ? THEN 1 ELSE 0 END as exact_rank", [$docTerm, $docTerm])
            ->when($like, fn($q) => $q->where(function($qq) use ($like) { $qq->where('w.reference', 'like', $like)->orWhere('w.id', 'like', $like); }))
            ->when($filters['customer_id'] ?? null, fn($q,$v) => $q->where('w.customer_id', $v))
            ->when($filters['customer_name'] ?? null, fn($q,$v) => $q->where(fn($name) => $name->where('c.first_name', 'like', "%{$v}%")->orWhere('c.last_name', 'like', "%{$v}%")->orWhere('w.beneficiary_name', 'like', "%{$v}%")))
            ->when($from, fn($q,$d) => $q->whereRaw('COALESCE(w.transferred_at, w.created_at) >= ?', [$d]))
            ->when($to, fn($q,$d) => $q->whereRaw('COALESCE(w.transferred_at, w.created_at) <= ?', [$d]))
            ->groupBy('w.id','w.reference','w.customer_id','w.beneficiary_name','w.transferred_at','w.created_at','w.total_amount');

        $union = $new->unionAll($legacy);
        $page = DB::query()->fromSub($union, 'sr_rows')->orderByDesc('exact_rank')->orderByDesc('canonical_date')->orderByDesc('source_id')->paginate($perPage)->withQueryString();
        $rows = collect($page->items());
        $newIds = $rows->where('source','new')->pluck('source_id')->all();
        $legacyIds = $rows->where('source','legacy')->pluck('source_id')->all();
        $docs = SalesReturnDocument::with(['items.destinationWarehouse:id,name,type','items.product','items.variant','customer','creator'])->whereIn('id',$newIds)->get()->keyBy('id');
        $legacyModels = WarehouseTransfer::with(['items.product','items.variant','toWarehouse','customer','user'])->whereIn('id',$legacyIds)->get()->keyBy('id');
        $mapped = $rows->map(fn($r) => $r->source === 'new' ? $this->normalizeNewRow($docs[(int)$r->source_id]) : $this->normalizeLegacyRow($legacyModels[(int)$r->source_id]))->values();
        return new LengthAwarePaginator($mapped, $page->total(), $page->perPage(), $page->currentPage(), ['path' => request()->url(), 'query' => request()->query()]);
    }

    public function getIndexRows(array $filters): Collection
    {
        return collect($this->getPaginatedRows($filters, 100000)->items());
    }

    public function getAllRows(array $filters): Collection
    {
        return $this->getOfficialRows($filters);
    }

    private function getOfficialRows(array $filters): Collection
    {
        $filters['official_only'] = true;
        $filters['status'] = SalesReturnDocument::STATUS_APPLIED;
        return $this->getIndexRows($filters)->filter(fn ($row) => ($row['source'] ?? null) === 'legacy' || ($row['status'] ?? null) === SalesReturnDocument::STATUS_APPLIED)->values();
    }

    public function getExcelRows(array $filters): Collection { return $this->getOfficialRows($filters); }
    public function getPdfRows(array $filters): Collection { return $this->getOfficialRows($filters); }

    public function normalizeLegacyRow(WarehouseTransfer $transfer): array
    {
        $amount = (int) ($transfer->total_amount ?: $transfer->items->sum('line_total'));
        $healthyAmount = (int) $transfer->items->where('return_kind', 'healthy')->sum('line_total');
        $damagedAmount = (int) $transfer->items->where('return_kind', 'damaged')->sum('line_total');
        $warehouseName = $transfer->toWarehouse?->name ?: '—';
        if ($healthyAmount + $damagedAmount === 0) {
            $damagedAmount = $warehouseName !== '—' && str_contains($warehouseName, 'مرجوع') ? $amount : 0;
            $healthyAmount = $damagedAmount === 0 ? $amount : 0;
        }
        $returnedAt = $transfer->transferred_at ?: $transfer->created_at;

        return [
            'row_key' => 'legacy-'.$transfer->id,
            'source' => 'legacy',
            'source_id' => $transfer->id,
            'status' => 'legacy',
            'status_label' => 'قدیمی',
            'is_draft' => false,
            'is_applied' => true,
            'is_cancelled' => false,
            'can_edit' => false,
            'can_cancel' => false,
            'edit_url' => null,
            'cancel_url' => null,
            'document_number' => $transfer->reference ?: (string) $transfer->id,
            'customer_name' => $transfer->customer?->display_name ?: ($transfer->beneficiary_name ?: '—'),
            'returned_at' => $returnedAt,
            'returned_at_display' => $this->jalaliDateTime($returnedAt),
            'returned_at_sort' => $returnedAt?->format('Y-m-d H:i:s'),
            'return_type' => $healthyAmount && $damagedAmount ? 'ترکیبی' : ($damagedAmount ? 'مرجوعی' : 'سالم'),
            'healthy_amount' => $healthyAmount,
            'damaged_amount' => $damagedAmount,
            'total_amount' => $amount,
            'destination_warehouse_name' => $warehouseName,
            'destination_warehouse_label' => 'انبار مقصد: '.$warehouseName,
            'created_by_name' => $transfer->user?->name ?: '—',
            'show_url' => route('vouchers.show', $transfer),
            'print_url' => route('vouchers.return-from-sale.legacy.print', $transfer),
            'items_summary' => $transfer->items->map(fn ($item) => trim(($item->product?->name ?: '—').' / '.($item->variant?->variant_name ?: ($item->variant_name ?: '—')).' × '.number_format((int) $item->quantity)))->filter()->implode('، ') ?: ($transfer->note ?: '—'),
            'quantity' => (int) $transfer->items->sum('quantity'),
            'condition_label' => $healthyAmount && $damagedAmount ? 'سالم و مرجوعی' : ($damagedAmount ? 'معیوب' : 'سالم'),
            'destination_warehouse_details' => $warehouseName.': '.number_format((int) $transfer->items->sum('quantity')),
        ];
    }

    public function normalizeNewRow(SalesReturnDocument $document): array
    {
        $healthy = (int) $document->items->where('item_condition', SalesReturnDocumentItem::CONDITION_HEALTHY)->sum('refund_amount');
        $damaged = (int) $document->items->where('item_condition', SalesReturnDocumentItem::CONDITION_DAMAGED)->sum('refund_amount');
        $warehouses = $document->items->pluck('destinationWarehouse.name')->filter()->unique()->values();
        $returnedAt = $document->applied_at ?: $document->created_at;

        return [
            'row_key' => 'new-'.$document->id,
            'source' => 'new',
            'source_id' => $document->id,
            'status' => $document->status,
            'status_label' => SalesReturnDocument::statusLabels()[$document->status] ?? $document->status,
            'is_draft' => $document->isDraft(),
            'is_applied' => $document->isApplied(),
            'is_cancelled' => $document->isCancelled(),
            'can_edit' => $document->isDraft() ? (auth()->user()?->can('sales_returns.edit_draft') ?? false) : ($document->isApplied() && (auth()->user()?->can('sales_returns.edit_applied') ?? false)),
            'can_cancel' => $document->isDraft() ? (auth()->user()?->can('sales_returns.cancel_draft') ?? false) : ($document->isApplied() && (auth()->user()?->can('sales_returns.void_applied') ?? false)),
            'edit_url' => $document->isDraft() ? route('vouchers.return-from-sale.edit', $document) : ($document->isApplied() ? route('vouchers.return-from-sale.applied.edit', $document) : null),
            'cancel_url' => $document->isDraft() ? route('vouchers.return-from-sale.cancel', $document) : ($document->isApplied() ? route('vouchers.return-from-sale.applied.void', $document) : null),
            'document_number' => $document->document_number,
            'customer_name' => $document->customer?->display_name ?: '—',
            'returned_at' => $returnedAt,
            'returned_at_display' => $this->jalaliDateTime($returnedAt),
            'returned_at_sort' => $returnedAt?->format('Y-m-d H:i:s'),
            'return_type' => $healthy && $damaged ? 'ترکیبی' : ($damaged ? 'مرجوعی' : 'سالم'),
            'healthy_amount' => $healthy,
            'damaged_amount' => $damaged,
            'total_amount' => $healthy + $damaged,
            'destination_warehouse_name' => $warehouses->count() > 1 ? 'چند انبار' : ($warehouses->first() ?: '—'),
            'destination_warehouse_label' => 'انبار مقصد: '.($warehouses->count() > 1 ? 'چند انبار' : ($warehouses->first() ?: '—')),
            'destination_warehouse_details' => $document->items->groupBy('destination_warehouse_id')->map(fn ($items) => ($items->first()->destinationWarehouse?->name ?: '—').': '.number_format((int) $items->sum('return_quantity')))->implode('، '),
            'condition_label' => $document->items->where('item_condition', SalesReturnDocumentItem::CONDITION_HEALTHY)->sum('return_quantity') && $document->items->where('item_condition', SalesReturnDocumentItem::CONDITION_DAMAGED)->sum('return_quantity') ? 'ترکیبی' : ($document->items->where('item_condition', SalesReturnDocumentItem::CONDITION_DAMAGED)->sum('return_quantity') ? 'معیوب' : 'سالم'),
            'created_by_name' => $document->creator?->name ?: '—',
            'show_url' => route('vouchers.return-from-sale.show', $document),
            'print_url' => route('vouchers.return-from-sale.print', $document),
            'items_summary' => $document->items->map(fn ($item) => trim(($item->product_name_snapshot ?: $item->product?->name ?: '—').' / '.($item->variant_name_snapshot ?: $item->variant?->variant_name ?: '—').' × '.number_format((int) $item->return_quantity)))->filter()->implode('، ') ?: '—',
            'quantity' => (int) $document->total_quantity,
        ];
    }


    public function getProductReturnSummary(array $filters): Collection
    {
        $rows = $this->aggregateProductRows($filters);
        $warehouses = $this->aggregateProductWarehouses($filters);

        $merged = collect();
        foreach ($rows as $row) {
            $key = $this->productGroupKey($row);
            $current = $merged->get($key, [
                'group_key' => $key,
                'product_name' => $row->product_name ?: '—',
                'variant_name' => $row->variant_name ?: '—',
                'sku' => $row->sku ?: '—',
                'total_quantity' => 0,
                'healthy_quantity' => 0,
                'damaged_quantity' => 0,
                'total_refund_amount' => 0,
                'document_keys' => [],
                'customer_ids' => [],
                'first_return_at' => null,
                'last_return_at' => null,
                'warehouses' => [],
            ]);
            $current['total_quantity'] += (int) $row->total_quantity;
            $current['healthy_quantity'] += (int) $row->healthy_quantity;
            $current['damaged_quantity'] += (int) $row->damaged_quantity;
            $current['total_refund_amount'] += (int) $row->total_refund_amount;
            foreach (explode(',', (string) $row->document_keys) as $docKey) if ($docKey !== '') $current['document_keys'][$row->source.'-'.$docKey] = true;
            foreach (explode(',', (string) $row->customer_ids) as $customerId) if ($customerId !== '') $current['customer_ids'][$customerId] = true;
            $first = $row->first_return_at ? Carbon::parse($row->first_return_at) : null;
            $last = $row->last_return_at ? Carbon::parse($row->last_return_at) : null;
            if ($first && (! $current['first_return_at'] || $first->lt($current['first_return_at']))) $current['first_return_at'] = $first;
            if ($last && (! $current['last_return_at'] || $last->gt($current['last_return_at']))) $current['last_return_at'] = $last;
            $merged[$key] = $current;
        }

        foreach ($warehouses as $row) {
            $key = $this->productGroupKey($row);
            if (! $merged->has($key)) continue;
            $current = $merged[$key];
            $name = $row->warehouse_name ?: '—';
            $current['warehouses'][$name] = ($current['warehouses'][$name] ?? 0) + (int) $row->quantity;
            $merged[$key] = $current;
        }

        return $merged->values()->map(function (array $row) {
            $row['documents_count'] = count($row['document_keys']);
            $row['customers_count'] = count($row['customer_ids']);
            $row['weighted_unit_price'] = $row['total_quantity'] > 0 ? (int) round($row['total_refund_amount'] / $row['total_quantity']) : 0;
            $row['warehouses_label'] = collect($row['warehouses'])->map(fn($qty, $name) => $name.': '.number_format((int) $qty))->implode("\n");
            $row['last_return_at_display'] = $this->jalaliDateTime($row['last_return_at']);
            return $row;
        })->sortByDesc(fn($row) => $row['last_return_at']?->timestamp ?? 0)->values();
    }

    public function getProductReturnTotals(array $filters): array
    {
        $rows = $this->getProductReturnSummary($filters);
        $documentKeys = [];
        $customerIds = [];
        foreach ($rows as $row) {
            foreach (array_keys($row['document_keys'] ?? []) as $key) $documentKeys[$key] = true;
            foreach (array_keys($row['customer_ids'] ?? []) as $key) $customerIds[$key] = true;
        }
        return [
            'unique_products' => $rows->count(),
            'total_quantity' => (int) $rows->sum('total_quantity'),
            'healthy_quantity' => (int) $rows->sum('healthy_quantity'),
            'damaged_quantity' => (int) $rows->sum('damaged_quantity'),
            'documents_count' => count($documentKeys),
            'customers_count' => count($customerIds),
            'total_refund_amount' => (int) $rows->sum('total_refund_amount'),
        ];
    }

    public function legacyConditionSql(): string
    {
        return "CASE WHEN COALESCE(wi.return_kind, CASE WHEN wh.type = 'return' OR (wh.type IS NULL AND wh.name LIKE '%مرجوع%') THEN 'damaged' ELSE 'healthy' END) = 'damaged' THEN 'damaged' ELSE 'healthy' END";
    }

    private function aggregateProductRows(array $filters): Collection
    {
        $docTerm = trim((string) ($filters['document_number'] ?? ''));
        $like = $docTerm !== '' ? '%'.str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $docTerm).'%' : null;
        $from = $this->dateBoundary($filters['date_from'] ?? null);
        $to = $this->dateBoundary($filters['date_to'] ?? null, true);
        $legacyCondition = $this->legacyConditionSql();

        $new = DB::table('sales_return_document_items as i')
            ->join('sales_return_documents as d', 'd.id', '=', 'i.document_id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'i.product_variant_id')
            ->where('d.status', SalesReturnDocument::STATUS_APPLIED)
            ->whereNotExists(function($sub){ $sub->selectRaw('1')->from('warehouse_transfers as dup')->whereColumn('dup.reference', 'd.reference_number')->whereNotNull('d.reference_number')->where('dup.voucher_type', WarehouseTransfer::TYPE_CUSTOMER_RETURN); })
            ->when($like, fn($q) => $q->where('d.document_number', 'like', $like))
            ->when($filters['customer_id'] ?? null, fn($q,$v) => $q->where('d.customer_id', $v))
            ->when($from, fn($q,$d) => $q->whereRaw('COALESCE(d.applied_at, d.created_at) >= ?', [$d]))
            ->when($to, fn($q,$d) => $q->whereRaw('COALESCE(d.applied_at, d.created_at) <= ?', [$d]))
            ->selectRaw("'new' as source, i.product_variant_id, i.product_id, COALESCE(i.product_name_snapshot, p.name, '') as product_name, COALESCE(i.variant_name_snapshot, pv.variant_name, '') as variant_name, COALESCE(i.sku_snapshot, i.barcode_snapshot, pv.variant_code, '') as sku, SUM(i.return_quantity) as total_quantity, SUM(CASE WHEN i.item_condition = 'healthy' THEN i.return_quantity ELSE 0 END) as healthy_quantity, SUM(CASE WHEN i.item_condition = 'damaged' THEN i.return_quantity ELSE 0 END) as damaged_quantity, SUM(i.refund_amount) as total_refund_amount, GROUP_CONCAT(DISTINCT d.id) as document_keys, GROUP_CONCAT(DISTINCT d.customer_id) as customer_ids, MIN(COALESCE(d.applied_at, d.created_at)) as first_return_at, MAX(COALESCE(d.applied_at, d.created_at)) as last_return_at")
            ->groupBy('i.product_variant_id','i.product_id','i.product_name_snapshot','p.name','i.variant_name_snapshot','pv.variant_name','i.sku_snapshot','i.barcode_snapshot','pv.variant_code');

        $legacy = DB::table('warehouse_transfer_items as wi')
            ->join('warehouse_transfers as w', 'w.id', '=', 'wi.warehouse_transfer_id')
            ->leftJoin('warehouses as wh', 'wh.id', '=', DB::raw('COALESCE(wi.destination_warehouse_id, w.to_warehouse_id)'))
            ->leftJoin('products as p', 'p.id', '=', 'wi.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'wi.product_variant_id')
            ->where('w.voucher_type', WarehouseTransfer::TYPE_CUSTOMER_RETURN)
            ->when($like, fn($q) => $q->where('w.reference', 'like', $like))
            ->when($filters['customer_id'] ?? null, fn($q,$v) => $q->where('w.customer_id', $v))
            ->when($from, fn($q,$d) => $q->whereRaw('COALESCE(w.transferred_at, w.created_at) >= ?', [$d]))
            ->when($to, fn($q,$d) => $q->whereRaw('COALESCE(w.transferred_at, w.created_at) <= ?', [$d]))
            ->selectRaw("'legacy' as source, wi.product_variant_id, wi.product_id, COALESCE(p.name, '') as product_name, COALESCE(pv.variant_name, wi.variant_name, '') as variant_name, COALESCE(wi.variant_code, pv.variant_code, '') as sku, SUM(wi.quantity) as total_quantity, SUM(CASE WHEN {$legacyCondition} = 'healthy' THEN wi.quantity ELSE 0 END) as healthy_quantity, SUM(CASE WHEN {$legacyCondition} = 'damaged' THEN wi.quantity ELSE 0 END) as damaged_quantity, SUM(COALESCE(wi.line_total, wi.quantity * COALESCE(wi.unit_price, 0))) as total_refund_amount, GROUP_CONCAT(DISTINCT w.id) as document_keys, GROUP_CONCAT(DISTINCT w.customer_id) as customer_ids, MIN(COALESCE(w.transferred_at, w.created_at)) as first_return_at, MAX(COALESCE(w.transferred_at, w.created_at)) as last_return_at")
            ->groupBy('wi.product_variant_id','wi.product_id','p.name','pv.variant_name','wi.variant_name','wi.variant_code','pv.variant_code');

        return $new->get()->concat($legacy->get());
    }

    private function aggregateProductWarehouses(array $filters): Collection
    {
        $docTerm = trim((string) ($filters['document_number'] ?? ''));
        $like = $docTerm !== '' ? '%'.str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $docTerm).'%' : null;
        $from = $this->dateBoundary($filters['date_from'] ?? null);
        $to = $this->dateBoundary($filters['date_to'] ?? null, true);

        $new = DB::table('sales_return_document_items as i')
            ->join('sales_return_documents as d', 'd.id', '=', 'i.document_id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'i.product_variant_id')
            ->leftJoin('warehouses as wh', 'wh.id', '=', 'i.destination_warehouse_id')
            ->where('d.status', SalesReturnDocument::STATUS_APPLIED)
            ->whereNotExists(function($sub){ $sub->selectRaw('1')->from('warehouse_transfers as dup')->whereColumn('dup.reference', 'd.reference_number')->whereNotNull('d.reference_number')->where('dup.voucher_type', WarehouseTransfer::TYPE_CUSTOMER_RETURN); })
            ->when($like, fn($q) => $q->where('d.document_number', 'like', $like))
            ->when($filters['customer_id'] ?? null, fn($q,$v) => $q->where('d.customer_id', $v))
            ->when($from, fn($q,$d) => $q->whereRaw('COALESCE(d.applied_at, d.created_at) >= ?', [$d]))
            ->when($to, fn($q,$d) => $q->whereRaw('COALESCE(d.applied_at, d.created_at) <= ?', [$d]))
            ->selectRaw("i.product_variant_id, i.product_id, COALESCE(i.product_name_snapshot, p.name, '') as product_name, COALESCE(i.variant_name_snapshot, pv.variant_name, '') as variant_name, COALESCE(i.sku_snapshot, i.barcode_snapshot, pv.variant_code, '') as sku, COALESCE(wh.name, '—') as warehouse_name, SUM(i.return_quantity) as quantity")
            ->groupBy('i.product_variant_id','i.product_id','i.product_name_snapshot','p.name','i.variant_name_snapshot','pv.variant_name','i.sku_snapshot','i.barcode_snapshot','pv.variant_code','wh.name');

        $legacy = DB::table('warehouse_transfer_items as wi')
            ->join('warehouse_transfers as w', 'w.id', '=', 'wi.warehouse_transfer_id')
            ->leftJoin('warehouses as wh', 'wh.id', '=', DB::raw('COALESCE(wi.destination_warehouse_id, w.to_warehouse_id)'))
            ->leftJoin('products as p', 'p.id', '=', 'wi.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'wi.product_variant_id')
            ->where('w.voucher_type', WarehouseTransfer::TYPE_CUSTOMER_RETURN)
            ->when($like, fn($q) => $q->where('w.reference', 'like', $like))
            ->when($filters['customer_id'] ?? null, fn($q,$v) => $q->where('w.customer_id', $v))
            ->when($from, fn($q,$d) => $q->whereRaw('COALESCE(w.transferred_at, w.created_at) >= ?', [$d]))
            ->when($to, fn($q,$d) => $q->whereRaw('COALESCE(w.transferred_at, w.created_at) <= ?', [$d]))
            ->selectRaw("wi.product_variant_id, wi.product_id, COALESCE(p.name, '') as product_name, COALESCE(pv.variant_name, wi.variant_name, '') as variant_name, COALESCE(wi.variant_code, pv.variant_code, '') as sku, COALESCE(wh.name, '—') as warehouse_name, SUM(wi.quantity) as quantity")
            ->groupBy('wi.product_variant_id','wi.product_id','p.name','pv.variant_name','wi.variant_name','wi.variant_code','pv.variant_code','wh.name');

        return $new->get()->concat($legacy->get());
    }

    private function productGroupKey(object $row): string
    {
        if (!empty($row->product_variant_id)) return 'variant:'.(int)$row->product_variant_id;
        if (!empty($row->product_id) && ($this->normalizeKey($row->sku ?? '') !== '' || $this->normalizeKey($row->variant_name ?? '') !== '')) {
            return 'product:'.(int)$row->product_id.'|sku:'.$this->normalizeKey($row->sku ?? '').'|variant:'.$this->normalizeKey($row->variant_name ?? '');
        }
        return 'snapshot:'.$this->normalizeKey($row->product_name ?? '').'|'.$this->normalizeKey($row->variant_name ?? '').'|'.$this->normalizeKey($row->sku ?? '');
    }

    private function normalizeKey(?string $value): string
    {
        $value = strtr((string)$value, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        $value = preg_replace('/\s+/u', ' ', trim($value));
        return mb_strtolower($value ?? '', 'UTF-8');
    }

    public function jalaliDateTime($date): string
    {
        if (!$date) return '—';
        return Jalalian::fromDateTime($date)->format('Y/m/d H:i');
    }

    private function legacyReturnType(WarehouseTransfer $transfer): string
    {
        $name = $transfer->toWarehouse?->name ?: '';
        return str_contains($name, 'مرجوع') ? 'مرجوعی' : 'سالم';
    }

    private function applyLegacyFilters($query, array $filters): void
    {
        $query->when($filters['document_number'] ?? null, fn ($q, $v) => $q->where('reference', 'like', "%{$v}%"))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($filters['destination_warehouse_id'] ?? null, fn ($q, $v) => $q->where('to_warehouse_id', $v))
            ->when($this->dateBoundary($filters['date_from'] ?? null), fn ($q, $d) => $q->whereRaw('COALESCE(transferred_at, created_at) >= ?', [$d]))
            ->when($this->dateBoundary($filters['date_to'] ?? null, true), fn ($q, $d) => $q->whereRaw('COALESCE(transferred_at, created_at) <= ?', [$d]));
    }
}
