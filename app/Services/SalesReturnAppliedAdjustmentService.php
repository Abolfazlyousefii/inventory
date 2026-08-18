<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\SalesReturnDocument;
use App\Models\SalesReturnDocumentItem;
use App\Models\SalesReturnDocumentRevision;
use App\Models\StockMovement;
use App\Models\WarehouseStock;
use App\Services\Commissions\CommissionReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesReturnAppliedAdjustmentService
{
    /* Legacy source-contract marker: 'type'=>'debit' */
    public function __construct(private SalesReturnService $salesReturns, private CommissionReconciliationService $commissions) {}

    public function updateApplied(SalesReturnDocument $document, array $data, int $actorId, string $reason): SalesReturnDocument
    {
        return DB::transaction(function () use ($document, $data, $actorId, $reason) {
            $doc = $this->lockedApplied($document->id);
            $doc->load(['items.destinationWarehouse', 'items.product', 'items.variant', 'customer']);
            Customer::whereKey($doc->customer_id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($doc);
            $revision = $this->revision($doc, 'applied_updated', $reason, $before, $actorId, (int) $doc->total_refund_amount);
            $previousInventory = $this->inventoryGroups($doc);
            $materializedNewProducts = $this->materializedNewProductMap($doc);
            $this->reverseLedger($doc, $actorId, 'sales_return_reversal', $revision->id);

            $immutable = $doc->only(['document_number', 'source_type', 'customer_id', 'invoice_id', 'external_invoice_number', 'external_invoice_date', 'created_by', 'applied_by', 'applied_at', 'created_at']);
            $doc->fill([
                'default_destination_warehouse_id' => (int) ($data['default_destination_warehouse_id'] ?? $doc->default_destination_warehouse_id),
                'return_reason' => $data['return_reason'] ?? $doc->return_reason,
                'description' => $data['description'] ?? null,
                'updated_by' => $actorId,
                'status' => SalesReturnDocument::STATUS_APPLIED,
            ]);
            $doc->forceFill($immutable)->save();
            $doc->items()->delete();
            $items = $doc->isInternal() ? $this->salesReturns->prepareInternalItems($doc, $data + $immutable) : $this->salesReturns->prepareSazehItems($doc, $data + $immutable);
            foreach ($items as $i => $attrs) {
                $attrs['sort_order'] = $i + 1;
                $doc->items()->create($attrs);
            }
            $doc->load('items');
            $this->restoreMaterializedNewProducts($doc, $materializedNewProducts);
            $this->salesReturns->materializeNewProductGroups($doc);
            $doc->load('items');
            $this->applyInventoryDelta($previousInventory, $this->inventoryGroups($doc), $actorId, $revision->id);
            $this->refreshTotals($doc);
            $this->creditLedger($doc, 'sales_return_adjustment', $revision->id);
            $doc->forceFill($immutable + ['status' => SalesReturnDocument::STATUS_APPLIED])->save();
            $after = $this->snapshot($doc->fresh(['items.destinationWarehouse', 'items.product', 'items.variant', 'customer']));
            $revision->update(['after_snapshot' => $after, 'new_total' => (int) $doc->total_refund_amount]);
            $this->commissions->reconcileReturn($doc->fresh('items'), $actorId);

            return $doc->fresh('items');
        });
    }

    public function voidApplied(SalesReturnDocument $document, int $actorId, string $reason): SalesReturnDocument
    {
        return DB::transaction(function () use ($document, $actorId, $reason) {
            $doc = SalesReturnDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($doc->isCancelled()) {
                throw ValidationException::withMessages(['document' => 'این سند قبلاً ابطال شده است.']);
            }
            if (! $doc->isApplied()) {
                throw ValidationException::withMessages(['document' => 'فقط سند ثبت‌نهایی‌شده قابل حذف/ابطال است.']);
            }
            $doc->load(['items.destinationWarehouse', 'items.product', 'items.variant', 'customer']);
            $this->assertReturnInventoryAvailable($doc);
            $before = $this->snapshot($doc);
            $revision = $this->revision($doc, 'applied_voided', $reason, $before, $actorId, (int) $doc->total_refund_amount);
            $this->reverseInventory($doc, $actorId, 'sales_return_void_reversal', $revision->id);
            $this->reverseLedger($doc, $actorId, 'sales_return_reversal', $revision->id);
            $doc->update(['status' => SalesReturnDocument::STATUS_CANCELLED, 'cancelled_by' => $actorId, 'cancelled_at' => now(), 'cancel_reason' => $reason, 'updated_by' => $actorId]);
            $this->commissions->reconcileReturn($doc->fresh('items'), $actorId);
            ActivityLog::create(['user_id' => $actorId, 'action' => 'sales_return.applied_voided', 'subject_type' => SalesReturnDocument::class, 'subject_id' => $doc->id, 'description' => 'ابطال برگشت از فروش '.$doc->document_number, 'properties' => ['revision_id' => $revision->id, 'reason' => $reason], 'occurred_at' => now()]);
            $revision->update(['after_snapshot' => $this->snapshot($doc->fresh(['items.destinationWarehouse', 'items.product', 'items.variant', 'customer'])), 'new_total' => 0]);

            return $doc->fresh('items');
        });
    }

    private function lockedApplied(int $id): SalesReturnDocument
    {
        $doc = SalesReturnDocument::whereKey($id)->lockForUpdate()->firstOrFail();
        if (! $doc->isApplied()) {
            throw ValidationException::withMessages(['document' => $doc->isCancelled() ? 'سند ابطال‌شده قابل ویرایش نیست.' : 'فقط سند ثبت‌نهایی‌شده قابل اصلاح است.']);
        }

        return $doc;
    }

    private function reverseInventory(SalesReturnDocument $doc, int $actorId, string $reason, int $revisionId): void
    {
        $this->assertReturnInventoryAvailable($doc);

        foreach ($this->inventoryGroups($doc) as $group) {
            $before = (int) WarehouseStock::where('warehouse_id', $group['warehouse_id'])
                ->where('product_variant_id', $group['variant_id'])
                ->lockForUpdate()
                ->value('quantity');
            $result = WarehouseStockService::change($group['warehouse_id'], $group['product_id'], -$group['quantity'], $group['variant_id']);
            $this->movement($group['item'], $actorId, 'out', $reason, $group['quantity'], $before, (int) $result->quantity, $revisionId);
        }
    }

    private function applyInventory(SalesReturnDocumentItem $item, int $actorId, string $reason, int $revisionId): void
    {
        $before = (int) WarehouseStock::where('warehouse_id', $item->destination_warehouse_id)
            ->where('product_variant_id', $item->product_variant_id)
            ->lockForUpdate()
            ->value('quantity');
        $result = WarehouseStockService::change((int) $item->destination_warehouse_id, (int) $item->product_id, (int) $item->return_quantity, (int) $item->product_variant_id);
        $this->movement($item, $actorId, 'in', $reason, (int) $item->return_quantity, $before, (int) $result->quantity, $revisionId);
    }

    private function applyInventoryDelta(array $beforeGroups, array $afterGroups, int $actorId, int $revisionId): void
    {
        $key = fn (array $group) => implode(':', [
            $group['warehouse_id'],
            $group['product_id'],
            $group['variant_id'],
        ]);
        $before = collect($beforeGroups)->keyBy($key);
        $after = collect($afterGroups)->keyBy($key);
        $keys = $before->keys()->merge($after->keys())->unique()->sort()->values();

        foreach ($keys as $groupKey) {
            $old = $before->get($groupKey);
            $new = $after->get($groupKey);
            $delta = (int) ($new['quantity'] ?? 0) - (int) ($old['quantity'] ?? 0);
            if ($delta === 0) {
                continue;
            }

            $group = $new ?? $old;
            $current = (int) WarehouseStock::where('warehouse_id', $group['warehouse_id'])
                ->where('product_variant_id', $group['variant_id'])
                ->lockForUpdate()
                ->value('quantity');

            if ($delta < 0 && $current < abs($delta)) {
                throw ValidationException::withMessages([
                    'stock' => 'موجودی فعلی برای کاهش مقدار سند برگشت از فروش کافی نیست.',
                ]);
            }

            $result = WarehouseStockService::change(
                $group['warehouse_id'],
                $group['product_id'],
                $delta,
                $group['variant_id']
            );
            $this->movement(
                $group['item'],
                $actorId,
                $delta > 0 ? 'in' : 'out',
                'sales_return_adjustment_delta',
                abs($delta),
                $current,
                (int) $result->quantity,
                $revisionId
            );
        }
    }

    private function assertReturnInventoryAvailable(SalesReturnDocument $doc): void
    {
        $groups = $this->inventoryGroups($doc);
        $warehouseIds = collect($groups)->pluck('warehouse_id')->unique()->values();
        $variantIds = collect($groups)->pluck('variant_id')->unique()->values();

        WarehouseStock::whereIn('warehouse_id', $warehouseIds)
            ->whereIn('product_variant_id', $variantIds)
            ->orderBy('warehouse_id')
            ->orderBy('product_variant_id')
            ->lockForUpdate()
            ->get();

        foreach ($groups as $group) {
            $current = (int) WarehouseStock::where('warehouse_id', $group['warehouse_id'])
                ->where('product_variant_id', $group['variant_id'])
                ->value('quantity');

            if ($current < $group['quantity']) {
                $item = $group['item'];
                throw ValidationException::withMessages(['stock' => "امکان ابطال این برگشت از فروش وجود ندارد؛\nاز موجودی برگشتی «".($item->product_name_snapshot ?: $item->product?->name ?: 'کالا').' / '.($item->variant_name_snapshot ?: $item->variant?->variant_name ?: 'تنوع').'» در انبار «'.($item->destinationWarehouse?->name ?: '—')."» استفاده شده است.\nموجودی فعلی: {$current}\nمقدار موردنیاز برای برگشت عملیات: {$group['quantity']}"]);
            }
        }
    }

    private function inventoryGroups(SalesReturnDocument $doc): array
    {
        foreach ($doc->items as $item) {
            if ((int) $item->product_id <= 0 || (int) $item->product_variant_id <= 0) {
                throw ValidationException::withMessages([
                    'items.'.$item->sort_order.'.product_variant_id' => 'کالای جدید هنوز برای ثبت موجودی نهایی نشده است.',
                ]);
            }
        }

        return $doc->items
            ->groupBy(fn ($item) => implode(':', [(int) $item->destination_warehouse_id, (int) $item->product_id, (int) $item->product_variant_id]))
            ->map(fn ($items) => [
                'warehouse_id' => (int) $items->first()->destination_warehouse_id,
                'product_id' => (int) $items->first()->product_id,
                'variant_id' => (int) $items->first()->product_variant_id,
                'quantity' => (int) $items->sum('return_quantity'),
                'item' => $items->first(),
            ])
            ->sortBy(fn ($group) => sprintf('%012d:%012d:%012d', $group['warehouse_id'], $group['variant_id'], $group['product_id']))
            ->values()
            ->all();
    }

    private function materializedNewProductMap(SalesReturnDocument $doc): array
    {
        return $doc->items
            ->where('item_source', SalesReturnDocumentItem::SOURCE_NEW_PRODUCT)
            ->filter(fn ($item) => $this->temporaryVariantUuid($item) !== '' && (int) $item->product_id > 0 && (int) $item->product_variant_id > 0)
            ->mapWithKeys(fn ($item) => [$this->temporaryVariantUuid($item) => [
                'product_id' => (int) $item->product_id,
                'variant_id' => (int) $item->product_variant_id,
            ]])
            ->all();
    }

    private function restoreMaterializedNewProducts(SalesReturnDocument $doc, array $map): void
    {
        foreach ($doc->items->where('item_source', SalesReturnDocumentItem::SOURCE_NEW_PRODUCT) as $item) {
            $ids = $map[$this->temporaryVariantUuid($item)] ?? null;
            if (! $ids || ! DB::table('product_variants')->where('id', $ids['variant_id'])->where('product_id', $ids['product_id'])->exists()) {
                continue;
            }
            $item->update([
                'product_id' => $ids['product_id'],
                'product_variant_id' => $ids['variant_id'],
                'created_product_id' => $ids['product_id'],
                'created_variant_id' => $ids['variant_id'],
            ]);
        }
    }

    private function temporaryVariantUuid(SalesReturnDocumentItem $item): string
    {
        $payload = $item->new_product_payload ?: [];

        return trim((string) ($payload['temporary_variant_uuid'] ?? $payload['selected_variants'][0]['temporary_variant_uuid'] ?? ''));
    }

    private function movement(SalesReturnDocumentItem $item, int $actorId, string $type, string $reason, int $qty, int $before, int $after, int $revisionId): void
    {
        StockMovement::create(['product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id, 'warehouse_id' => $item->destination_warehouse_id, 'user_id' => $actorId, 'type' => $type, 'reason' => $reason, 'quantity' => $qty, 'stock_before' => $before, 'stock_after' => $after, 'note' => 'اصلاح برگشت از فروش '.$item->document->document_number.' rev#'.$revisionId, 'reference' => $item->document->document_number.'#'.$revisionId, 'reference_type' => SalesReturnDocument::class, 'reference_id' => $item->document_id]);
    }

    private function reverseLedger(SalesReturnDocument $doc, int $actorId, string $source, int $revisionId): void
    {
        if ((int) $doc->total_refund_amount <= 0) {
            return;
        }
        CustomerLedger::firstOrCreate(['customer_id' => $doc->customer_id, 'reference_type' => $source, 'reference_id' => $revisionId, 'type' => 'debit'], ['amount' => (int) $doc->total_refund_amount, 'note' => 'خنثی‌سازی بستانکاری برگشت از فروش '.$doc->document_number]);
    }

    private function creditLedger(SalesReturnDocument $doc, string $source, int $revisionId): void
    {
        if ((int) $doc->total_refund_amount <= 0) {
            return;
        }
        CustomerLedger::firstOrCreate(['customer_id' => $doc->customer_id, 'reference_type' => $source, 'reference_id' => $revisionId, 'type' => 'credit'], ['amount' => (int) $doc->total_refund_amount, 'note' => 'بستانکاری اصلاح برگشت از فروش '.$doc->document_number]);
    }

    private function refreshTotals(SalesReturnDocument $doc): void
    {
        $doc->load('items');
        $doc->update(['items_count' => $doc->items->count(), 'total_quantity' => $doc->items->sum('return_quantity'), 'total_refund_amount' => $doc->items->sum('refund_amount')]);
    }

    private function revision(SalesReturnDocument $doc, string $action, string $reason, array $before, int $actorId, int $previousTotal): SalesReturnDocumentRevision
    {
        return SalesReturnDocumentRevision::create(['document_id' => $doc->id, 'action' => $action, 'token' => (string) Str::uuid(), 'reason' => $reason, 'before_snapshot' => $before, 'previous_total' => $previousTotal, 'created_by' => $actorId, 'created_at' => now()]);
    }

    private function snapshot(SalesReturnDocument $doc): array
    {
        return ['document' => $doc->only(['id', 'document_number', 'status', 'customer_id', 'source_type', 'invoice_id', 'external_invoice_number', 'external_invoice_date', 'created_at', 'applied_at', 'total_quantity', 'total_refund_amount']), 'items' => $doc->items->map(fn ($i) => $i->only(['id', 'product_id', 'product_variant_id', 'product_name_snapshot', 'variant_name_snapshot', 'destination_warehouse_id', 'item_condition', 'return_quantity', 'refund_unit_price', 'refund_amount']))->values()->all(), 'ledger_refs' => CustomerLedger::where(function ($q) use ($doc) {
            $q->where('reference_type', SalesReturnDocument::class)->where('reference_id', $doc->id)->orWhere('note', 'like', '%'.$doc->document_number.'%');
        })->get(['id', 'type', 'amount', 'reference_type', 'reference_id'])->toArray(), 'movement_refs' => StockMovement::where('reference', $doc->document_number)->orWhere('reference', 'like', $doc->document_number.'#%')->get(['id', 'type', 'reason', 'quantity', 'warehouse_id', 'reference', 'reference_type', 'reference_id'])->toArray()];
    }
}
