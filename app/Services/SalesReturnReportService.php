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
        $from = $this->filterDate($filters['date_from'] ?? null)?->startOfDay();
        $to = $this->filterDate($filters['date_to'] ?? null)?->endOfDay();

        $new = DB::table('sales_return_documents as d')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->selectRaw("'new' as source, d.id as source_id, d.document_number, d.customer_id, '' as customer_name, COALESCE(d.applied_at, d.created_at) as canonical_date, d.status, d.total_quantity, d.total_refund_amount as total_amount, CASE WHEN d.document_number = ? THEN 1 ELSE 0 END as exact_rank", [$docTerm])
            ->when($like, fn($q) => $q->where('d.document_number', 'like', $like))
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
        $filters['status'] = SalesReturnDocument::STATUS_APPLIED;
        return $this->getIndexRows($filters)->filter(fn ($row) => ($row['source'] ?? null) === 'legacy' || ($row['status'] ?? null) === SalesReturnDocument::STATUS_APPLIED)->values();
    }

    public function getExcelRows(array $filters): Collection { return $this->getOfficialRows($filters); }
    public function getPdfRows(array $filters): Collection { return $this->getOfficialRows($filters); }

    public function normalizeLegacyRow(WarehouseTransfer $transfer): array
    {
        $amount = (int) ($transfer->total_amount ?: $transfer->items->sum('line_total'));
        $warehouseName = $transfer->toWarehouse?->name ?: '—';
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
            'return_type' => $this->legacyReturnType($transfer),
            'healthy_amount' => $warehouseName !== '—' && str_contains($warehouseName, 'مرجوع') ? 0 : $amount,
            'damaged_amount' => $warehouseName !== '—' && str_contains($warehouseName, 'مرجوع') ? $amount : 0,
            'total_amount' => $amount,
            'destination_warehouse_name' => $warehouseName,
            'destination_warehouse_label' => 'انبار مقصد: '.$warehouseName,
            'created_by_name' => $transfer->user?->name ?: '—',
            'show_url' => route('vouchers.show', $transfer),
            'print_url' => route('vouchers.return-from-sale.legacy.print', $transfer),
            'items_summary' => $transfer->items->map(fn ($item) => trim(($item->product?->name ?: '—').' / '.($item->variant?->variant_name ?: ($item->variant_name ?: '—')).' × '.number_format((int) $item->quantity)))->filter()->implode('، ') ?: ($transfer->note ?: '—'),
            'quantity' => (int) $transfer->items->sum('quantity'),
            'condition_label' => $warehouseName !== '—' && str_contains($warehouseName, 'مرجوع') ? 'معیوب' : 'سالم',
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
            ->when($this->filterDate($filters['date_from'] ?? null), fn ($q, $d) => $q->whereRaw('COALESCE(transferred_at, created_at) >= ?', [$d->startOfDay()]))
            ->when($this->filterDate($filters['date_to'] ?? null), fn ($q, $d) => $q->whereRaw('COALESCE(transferred_at, created_at) <= ?', [$d->endOfDay()]));
    }

    private function filterDate(?string $value): ?Carbon
    {
        if (!$value) return null;
        try { return preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $value) ? Jalalian::fromFormat('Y/m/d', $value)->toCarbon() : Carbon::parse($value); } catch (\Throwable) { return null; }
    }
}
