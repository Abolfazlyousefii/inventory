<?php

namespace App\Services;

use App\Models\{CustomerLedger, SalesReturnDocument, SalesReturnDocumentItem, StockMovement};
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;

class SalesReturnQueryService
{
    public function buildDocumentQuery(array $filters = []): Builder
    {
        $query = SalesReturnDocument::query()
            ->with(['customer:id,first_name,last_name,mobile,crm_customer_id,opening_balance', 'invoice:id,uuid,total,customer_id', 'creator:id,name', 'applier:id,name'])
            ->withCount('items')
            ->withSum('items as returned_quantity_sum', 'return_quantity');

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort'] ?? 'newest');
        return $query;
    }

    public function buildItemQuery(array $filters = []): Builder
    {
        $query = SalesReturnDocumentItem::query()
            ->with(['document.customer:id,first_name,last_name,mobile,crm_customer_id', 'document.invoice:id,uuid,total', 'document.creator:id,name', 'document.applier:id,name', 'destinationWarehouse:id,name,type', 'product:id,name', 'variant:id,variant_name,variant_code']);

        $query->whereHas('document', fn (Builder $doc) => $this->applyDocumentOnlyFilters($doc, $filters));
        $this->applyItemFilters($query, $filters);
        return $query->orderByDesc('id');
    }

    public function applyFilters(Builder $query, array $filters): Builder
    {
        $this->applyDocumentOnlyFilters($query, $filters);
        if ($this->hasItemFilters($filters)) {
            $query->whereHas('items', fn (Builder $items) => $this->applyItemFilters($items, $filters));
        }
        return $query;
    }

    private function applyDocumentOnlyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(($filters['status'] ?? 'all') !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['source_type'] ?? 'all') !== 'all', fn ($q) => $q->where('source_type', $filters['source_type']))
            ->when($filters['document_number'] ?? null, fn ($q, $v) => $q->where('document_number', 'like', "%{$v}%"))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($filters['invoice_number'] ?? null, fn ($q, $v) => $q->whereHas('invoice', fn ($i) => $i->where('uuid', 'like', "%{$v}%")))
            ->when($filters['external_invoice_number'] ?? null, fn ($q, $v) => $q->where('external_invoice_number', 'like', "%{$v}%"))
            ->when($filters['reference_number'] ?? null, fn ($q, $v) => $q->where('reference_number', 'like', "%{$v}%"))
            ->when($filters['return_reason'] ?? null, fn ($q, $v) => $q->where('return_reason', 'like', "%{$v}%"))
            ->when($filters['created_by'] ?? null, fn ($q, $v) => $q->where('created_by', $v))
            ->when($filters['applied_by'] ?? null, fn ($q, $v) => $q->where('applied_by', $v))
            ->when($filters['min_amount'] ?? null, fn ($q, $v) => $q->where('total_refund_amount', '>=', $v))
            ->when($filters['max_amount'] ?? null, fn ($q, $v) => $q->where('total_refund_amount', '<=', $v))
            ->when($this->dateBoundary($filters['date_from'] ?? null), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($this->dateBoundary($filters['date_to'] ?? null, true), fn ($q, $d) => $q->where('created_at', '<=', $d));
    }

    private function applyItemFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['destination_warehouse_id'] ?? null, fn ($q, $v) => $q->where('destination_warehouse_id', $v))
            ->when(($filters['item_condition'] ?? 'all') !== 'all', fn ($q) => $q->where('item_condition', $filters['item_condition']))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($filters['product_variant_id'] ?? null, fn ($q, $v) => $q->where('product_variant_id', $v));
    }

    public function summary(array $filters): array
    {
        $base = SalesReturnDocument::query();
        $this->applyFilters($base, $filters);
        $allDocuments = (clone $base)->count();
        $applied = (clone $base)->where('status', SalesReturnDocument::STATUS_APPLIED);
        $appliedIds = (clone $applied)->pluck('id');
        $itemBase = SalesReturnDocumentItem::query()->whereIn('document_id', $appliedIds);
        if ($this->hasItemFilters($filters)) $this->applyItemFilters($itemBase, $filters);

        return [
            'documents_count' => $allDocuments,
            'applied_count' => (clone $applied)->count(),
            'total_refund_amount' => (int) (clone $applied)->sum('total_refund_amount'),
            'healthy_amount' => (int) (clone $itemBase)->where('item_condition', SalesReturnDocumentItem::CONDITION_HEALTHY)->sum('refund_amount'),
            'damaged_amount' => (int) (clone $itemBase)->where('item_condition', SalesReturnDocumentItem::CONDITION_DAMAGED)->sum('refund_amount'),
        ];
    }

    public function tabCounts(array $filters): array
    {
        unset($filters['status']);
        $q = SalesReturnDocument::query(); $this->applyFilters($q, $filters);
        $rows = (clone $q)->select('status', DB::raw('count(*) as aggregate'))->groupBy('status')->pluck('aggregate', 'status');
        return ['all'=>(clone $q)->count(),'draft'=>(int)($rows['draft']??0),'pending_warehouse'=>(int)($rows[SalesReturnDocument::STATUS_PENDING_WAREHOUSE]??0),'applied'=>(int)($rows['applied']??0),'cancelled'=>(int)($rows['cancelled']??0)];
    }

    public function destinationLabels(SalesReturnDocument $document): array
    {
        $groups = $document->items->groupBy('destination_warehouse_id')->map(fn ($items) => ['name'=>$items->first()->destinationWarehouse?->name ?: '—','quantity'=>$items->sum('return_quantity')])->values();
        return ['label'=>$groups->count() > 1 ? 'چند انبار' : ($groups->first()['name'] ?? '—'), 'details'=>$groups->map(fn($g)=>$g['name'].': '.number_format($g['quantity']))->implode('، ')];
    }

    public function healthStatusSummary(SalesReturnDocument $document): array
    {
        $groups = $document->items->groupBy('item_condition')->map(fn ($items) => $items->sum('return_quantity'));
        $healthy = (int)($groups[SalesReturnDocumentItem::CONDITION_HEALTHY] ?? 0); $damaged = (int)($groups[SalesReturnDocumentItem::CONDITION_DAMAGED] ?? 0);
        return ['label'=>$healthy && $damaged ? 'ترکیبی' : ($damaged ? 'مرجوعی' : 'سالم'), 'details'=>($healthy && $damaged) ? "سالم: {$healthy}، مرجوعی: {$damaged}" : ''];
    }

    public function appliedHealthCheck(SalesReturnDocument $document): array
    {
        $ledgerAmount = (int) CustomerLedger::where('reference_type', SalesReturnDocument::class)->where('reference_id', $document->id)->where('type', 'credit')->sum('amount');
        $positiveItems = $document->items->filter(fn ($item) => (int) $item->return_quantity > 0);
        $movementCount = $positiveItems->isEmpty()
            ? 0
            : StockMovement::where('reference_type', SalesReturnDocumentItem::class)->whereIn('reference_id', $positiveItems->pluck('id'))->count();
        $checks = ['status_applied'=>$document->isApplied(), 'ledger_exists'=>$ledgerAmount > 0, 'ledger_amount_matches'=>$ledgerAmount === (int)$document->total_refund_amount, 'stock_movements_complete'=>$movementCount >= $positiveItems->count(), 'new_products_materialized'=>$document->items->every(fn($i)=>$i->item_source !== SalesReturnDocumentItem::SOURCE_NEW_PRODUCT || ($i->created_product_id && $i->created_variant_id))];
        return ['checks'=>$checks, 'ok'=>!in_array(false, $checks, true), 'ledger_amount'=>$ledgerAmount, 'stock_movement_count'=>$movementCount];
    }

    private function applySort(Builder $query, string $sort): void { match($sort){ 'oldest'=>$query->oldest('created_at')->orderBy('id'), 'amount_desc'=>$query->orderByDesc('total_refund_amount')->orderByDesc('id'), 'amount_asc'=>$query->orderBy('total_refund_amount')->orderBy('id'), 'customer'=>$query->join('customers','sales_return_documents.customer_id','=','customers.id')->orderBy('customers.last_name')->orderBy('customers.first_name')->select('sales_return_documents.*'), default=>$query->orderByRaw('COALESCE(applied_at, created_at) DESC')->orderByDesc('id') }; }
    private function hasItemFilters(array $filters): bool { return (bool) array_filter([$filters['destination_warehouse_id']??null, ($filters['item_condition']??'all') !== 'all' ? $filters['item_condition'] : null, $filters['product_id']??null, $filters['product_variant_id']??null]); }
    protected function dateBoundary(?string $value, bool $endOfRange = false): ?Carbon
    {
        if (! $value) return null;

        try {
            if (preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $value)) {
                $date = Jalalian::fromFormat('Y/m/d', $value)->toCarbon();
                return $endOfRange ? $date->endOfDay() : $date->startOfDay();
            }

            if (preg_match('/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}$/', $value)) {
                $date = Jalalian::fromFormat('Y/m/d H:i', $value)->toCarbon();
                return $endOfRange ? $date->endOfMinute() : $date->startOfMinute();
            }

            if (preg_match('/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
                return Jalalian::fromFormat('Y/m/d H:i:s', $value)->toCarbon();
            }

            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
