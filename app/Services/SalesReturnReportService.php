<?php

namespace App\Services;

use App\Models\{SalesReturnDocument, SalesReturnDocumentItem, WarehouseTransfer};
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Morilog\Jalali\Jalalian;

class SalesReturnReportService extends SalesReturnQueryService
{
    public function buildReportQuery(array $filters = []): Collection
    {
        return $this->getAllRows($filters);
    }

    public function getPaginatedRows(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $rows = $this->getAllRows($filters)->values();
        $page = LengthAwarePaginator::resolveCurrentPage();
        return new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page);
    }

    public function getAllRows(array $filters): Collection
    {
        $legacy = WarehouseTransfer::query()
            ->where('voucher_type', WarehouseTransfer::TYPE_CUSTOMER_RETURN)
            ->with(['items.product','items.variant','toWarehouse','customer','user']);
        $this->applyLegacyFilters($legacy, $filters);

        $new = $this->buildDocumentQuery($filters)
            ->where('status', SalesReturnDocument::STATUS_APPLIED)
            ->with(['items.destinationWarehouse:id,name,type','customer','creator']);

        $legacyRows = $legacy->get()->map(fn (WarehouseTransfer $transfer) => $this->normalizeLegacyRow($transfer));
        $legacyReferences = $legacyRows->pluck('document_number')->filter()->map(fn ($v) => (string) $v)->all();

        $newRows = $new->get()
            ->reject(fn (SalesReturnDocument $document) => $document->reference_number && in_array((string) $document->reference_number, $legacyReferences, true))
            ->map(fn (SalesReturnDocument $document) => $this->normalizeNewRow($document));

        return $legacyRows->concat($newRows)->sortByDesc(fn ($row) => $row['returned_at_sort'] ?? '')->values();
    }

    public function getExcelRows(array $filters): Collection { return $this->getAllRows($filters); }
    public function getPdfRows(array $filters): Collection { return $this->getAllRows($filters); }

    public function normalizeLegacyRow(WarehouseTransfer $transfer): array
    {
        $amount = (int) ($transfer->total_amount ?: $transfer->items->sum('line_total'));
        $warehouseName = $transfer->toWarehouse?->name ?: '—';
        $returnedAt = $transfer->transferred_at ?: $transfer->created_at;

        return [
            'row_key' => 'legacy-'.$transfer->id,
            'source' => 'legacy',
            'source_id' => $transfer->id,
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
            ->when($this->filterDate($filters['date_from'] ?? null), fn ($q, $d) => $q->where('transferred_at', '>=', $d->startOfDay()))
            ->when($this->filterDate($filters['date_to'] ?? null), fn ($q, $d) => $q->where('transferred_at', '<=', $d->endOfDay()));
    }

    private function filterDate(?string $value): ?Carbon
    {
        if (!$value) return null;
        try { return preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $value) ? Jalalian::fromFormat('Y/m/d', $value)->toCarbon() : Carbon::parse($value); } catch (\Throwable) { return null; }
    }
}
