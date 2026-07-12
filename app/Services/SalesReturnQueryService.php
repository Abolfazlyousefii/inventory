<?php

namespace App\Services;

use App\Models\SalesReturnDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class SalesReturnQueryService
{
    public function filtersFromRequest(Request $request): array
    {
        return [
            'status' => $request->query('status'),
            'source_type' => $request->query('source_type'),
            'document_number' => trim((string) $request->query('document_number', '')),
            'customer_id' => $request->integer('customer_id') ?: null,
            'invoice_number' => trim((string) $request->query('invoice_number', '')),
            'external_invoice_number' => trim((string) $request->query('external_invoice_number', '')),
            'destination_warehouse_id' => $request->integer('destination_warehouse_id') ?: null,
            'item_condition' => $request->query('item_condition'),
            'return_reason' => trim((string) $request->query('return_reason', '')),
            'product_id' => $request->integer('product_id') ?: null,
            'product_variant_id' => $request->integer('product_variant_id') ?: null,
            'created_by' => $request->integer('created_by') ?: null,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ];
    }

    public function defaultStatus(): string
    {
        $hasDrafts = SalesReturnDocument::query()->where('status', SalesReturnDocument::STATUS_DRAFT)->exists();

        return $hasDrafts ? SalesReturnDocument::STATUS_DRAFT : SalesReturnDocument::STATUS_APPLIED;
    }

    public function statusCounts(array $filters = []): array
    {
        $baseFilters = $filters;
        unset($baseFilters['status']);

        return collect(SalesReturnDocument::statusLabels())
            ->keys()
            ->mapWithKeys(fn (string $status) => [
                $status => (clone $this->buildQuery($baseFilters + ['status' => $status]))->count(),
            ])
            ->all();
    }

    public function buildQuery(array $filters = []): Builder
    {
        return SalesReturnDocument::query()
            ->with([
                'customer:id,first_name,last_name,mobile,crm_customer_id',
                'invoice:id,uuid,total,status,created_at',
                'creator:id,name',
                'items.destinationWarehouse:id,name,type',
            ])
            ->withCount('items')
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['source_type'] ?? null, fn (Builder $q, $source) => $q->where('source_type', $source))
            ->when($filters['document_number'] ?? '', fn (Builder $q, $number) => $q->where('document_number', 'like', '%' . $number . '%'))
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $customerId) => $q->where('customer_id', $customerId))
            ->when($filters['invoice_number'] ?? '', fn (Builder $q, $invoiceNumber) => $q->whereHas('invoice', fn (Builder $invoice) => $invoice->where('uuid', 'like', '%' . $invoiceNumber . '%')))
            ->when($filters['external_invoice_number'] ?? '', fn (Builder $q, $number) => $q->where('external_invoice_number', 'like', '%' . $number . '%'))
            ->when($filters['return_reason'] ?? '', fn (Builder $q, $reason) => $q->where('return_reason', $reason))
            ->when($filters['created_by'] ?? null, fn (Builder $q, $userId) => $q->where('created_by', $userId))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))
            ->when($filters['destination_warehouse_id'] ?? null, fn (Builder $q, $warehouseId) => $q->whereHas('items', fn (Builder $item) => $item->where('destination_warehouse_id', $warehouseId)))
            ->when($filters['item_condition'] ?? null, fn (Builder $q, $condition) => $q->whereHas('items', fn (Builder $item) => $item->where('item_condition', $condition)))
            ->when($filters['product_id'] ?? null, fn (Builder $q, $productId) => $q->whereHas('items', fn (Builder $item) => $item->where('product_id', $productId)))
            ->when($filters['product_variant_id'] ?? null, fn (Builder $q, $variantId) => $q->whereHas('items', fn (Builder $item) => $item->where('product_variant_id', $variantId)))
            ->latest('id');
    }
}
