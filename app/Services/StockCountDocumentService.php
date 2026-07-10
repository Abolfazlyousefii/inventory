<?php

namespace App\Services;

use App\Models\StockCountDocument;
use App\Models\StockCountDocumentHistory;
use App\Models\StockCountDocumentItem;
use App\Models\StockMovement;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;

class StockCountDocumentService
{
    public function create(array $payload, int $userId): StockCountDocument
    {
        return DB::transaction(function () use ($payload, $userId) {
            $document = StockCountDocument::create([
                'document_number' => $this->nextDocumentNumber(),
                'warehouse_id' => (int) $payload['warehouse_id'],
                'document_date' => $payload['document_date'],
                'status' => 'draft',
                'description' => $payload['description'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            foreach ($payload['items'] as $item) {
                $systemQty = $this->getSystemQuantity((int) $document->warehouse_id, (int) $item['product_id'], false, (int) $item['variant_id']);
                StockCountDocumentItem::create([
                    'document_id' => $document->id,
                    'product_id' => (int) $item['product_id'],
                    'product_variant_id' => (int) $item['variant_id'],
                    'system_quantity' => $systemQty,
                    'actual_quantity' => (int) $item['actual_quantity'],
                    'difference_quantity' => (int) $item['actual_quantity'] - $systemQty,
                    'description' => $item['description'] ?? null,
                ]);
            }

            $this->addHistory($document->id, 'created', null, $document->only(['document_number', 'warehouse_id', 'document_date', 'status']), 'ایجاد سند انبارگردانی', $userId);

            return $document->fresh(['warehouse', 'items.product', 'items.variant', 'creator']);
        });
    }

    public function update(StockCountDocument $document, array $payload, int $userId): StockCountDocument
    {
        if ($document->status !== 'draft') {
            abort(422, 'فقط سند پیش‌نویس قابل ویرایش است.');
        }

        return DB::transaction(function () use ($document, $payload, $userId) {
            $oldItems = $document->items()->get()
                ->mapWithKeys(fn ($row) => [((int) $row->product_id) . ':' . ((int) $row->product_variant_id) => $row->toArray()])
                ->all();

            $oldDocument = [
                'warehouse_id' => (int) $document->warehouse_id,
                'document_date' => (string) $document->document_date,
                'description' => $document->description,
            ];

            $document->update([
                'warehouse_id' => (int) $payload['warehouse_id'],
                'document_date' => $payload['document_date'],
                'description' => $payload['description'] ?? null,
                'updated_by' => $userId,
            ]);

            $document->items()->delete();

            $newItems = [];
            foreach ($payload['items'] as $item) {
                $systemQty = $this->getSystemQuantity((int) $document->warehouse_id, (int) $item['product_id'], false, (int) $item['variant_id']);
                $row = StockCountDocumentItem::create([
                    'document_id' => $document->id,
                    'product_id' => (int) $item['product_id'],
                    'product_variant_id' => (int) $item['variant_id'],
                    'system_quantity' => $systemQty,
                    'actual_quantity' => (int) $item['actual_quantity'],
                    'difference_quantity' => (int) $item['actual_quantity'] - $systemQty,
                    'description' => $item['description'] ?? null,
                ]);
                $newItems[((int) $row->product_id) . ':' . ((int) $row->product_variant_id)] = $row->toArray();
            }

            $this->addHistory($document->id, 'updated', [
                ...$oldDocument,
                'items' => $oldItems,
            ], [
                'warehouse_id' => $document->warehouse_id,
                'document_date' => $document->document_date,
                'description' => $document->description,
                'items' => $newItems,
            ], 'ویرایش سند انبارگردانی', $userId);

            $removedProducts = array_values(array_diff(array_keys($oldItems), array_keys($newItems)));
            $addedProducts = array_values(array_diff(array_keys($newItems), array_keys($oldItems)));

            if (!empty($removedProducts)) {
                $this->addHistory($document->id, 'item_removed', $removedProducts, null, 'حذف ردیف از سند', $userId);
            }
            if (!empty($addedProducts)) {
                $this->addHistory($document->id, 'item_added', null, $addedProducts, 'افزودن ردیف به سند', $userId);
            }

            return $document->fresh(['warehouse', 'items.product', 'items.variant', 'creator', 'updater']);
        });
    }

    public function finalize(StockCountDocument $document, int $userId): StockCountDocument
    {
        if ($document->status === 'finalized') {
            abort(422, 'این سند قبلاً نهایی شده است.');
        }

        if ($document->status !== 'draft') {
            abort(422, 'فقط سند پیش‌نویس قابل نهایی‌سازی است.');
        }

        return DB::transaction(function () use ($document, $userId) {
            $document = StockCountDocument::query()->lockForUpdate()->findOrFail($document->id);
            $items = $document->items()->with('product')->lockForUpdate()->get();

            foreach ($items as $item) {
                $difference = (int) $item->actual_quantity - (int) $item->system_quantity;
                $item->update(['difference_quantity' => $difference]);

                if ($difference === 0) {
                    continue;
                }

                $before = $this->getSystemQuantity((int) $document->warehouse_id, (int) $item->product_id, true, (int) $item->product_variant_id);
                WarehouseStockService::change((int) $document->warehouse_id, (int) $item->product_id, $difference, (int) $item->product_variant_id);
                $after = $before + $difference;

                StockMovement::create([
                    'product_id' => (int) $item->product_id,
                    'product_variant_id' => (int) $item->product_variant_id,
                    'warehouse_id' => (int) $document->warehouse_id,
                    'user_id' => $userId,
                    'type' => $difference > 0 ? 'in' : 'out',
                    'reason' => 'adjustment',
                    'transaction_type' => $difference > 0 ? 'stock_adjustment_in' : 'stock_adjustment_out',
                    'quantity' => abs($difference),
                    'stock_before' => $before,
                    'stock_after' => $after,
                    'note' => 'تعدیل ناشی از انبارگردانی سند ' . $document->document_number,
                    'reference' => $document->document_number,
                    'reference_type' => 'stock_count_document',
                    'reference_id' => $document->id,
                ]);
            }

            $document->update([
                'status' => 'finalized',
                'finalized_by' => $userId,
                'finalized_at' => now(),
                'updated_by' => $userId,
            ]);

            $this->addHistory($document->id, 'finalized', null, ['status' => 'finalized'], 'نهایی‌سازی سند و اعمال تعدیل موجودی', $userId);

            return $document->fresh(['warehouse', 'items.product', 'items.variant', 'creator', 'updater', 'finalizer', 'history.doer']);
        });
    }

    public function cancel(StockCountDocument $document, int $userId): StockCountDocument
    {
        if ($document->status === 'finalized') {
            abort(422, 'لغو مستقیم سند نهایی‌شده مجاز نیست.');
        }

        if ($document->status === 'cancelled') {
            return $document;
        }

        $document->update([
            'status' => 'cancelled',
            'updated_by' => $userId,
        ]);

        $this->addHistory($document->id, 'cancelled', null, ['status' => 'cancelled'], 'لغو سند انبارگردانی', $userId);

        return $document->fresh(['warehouse', 'items.product', 'items.variant', 'creator', 'updater']);
    }

    public function getSystemQuantity(int $warehouseId, int $productId, bool $lock = false, ?int $variantId = null): int
    {
        $query = WarehouseStock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId);

        if ($variantId !== null && $variantId > 0) {
            $query->where('product_variant_id', $variantId);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return (int) ($query->value('quantity') ?? 0);
    }

    private function addHistory(int $documentId, string $actionType, mixed $oldValue, mixed $newValue, ?string $description, int $userId): void
    {
        StockCountDocumentHistory::create([
            'document_id' => $documentId,
            'action_type' => $actionType,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'description' => $description,
            'done_by' => $userId,
            'done_at' => now(),
        ]);
    }

    private function nextDocumentNumber(): string
    {
        $prefix = 'STC-' . now()->format('Ymd') . '-';
        $last = StockCountDocument::query()
            ->where('document_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('document_number');

        if (!$last) {
            return $prefix . '0001';
        }

        $lastSequence = (int) substr($last, -4);

        return $prefix . str_pad((string) ($lastSequence + 1), 4, '0', STR_PAD_LEFT);
    }
}
