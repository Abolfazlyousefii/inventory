<?php

namespace App\Services;

use App\Models\PreinvoiceOrder;
use App\Models\PreinvoiceOrderItem;
use App\Models\ProductVariant;
use App\Models\WarehouseReviewItemLog;
use App\Models\WarehouseReviewLog;
use App\Models\WarehouseReviewSnapshot;
use Illuminate\Support\Collection;

class WarehouseReviewAuditService
{
    public const ACTIVE_PENDING_REFRESH_NOTE = 'اطلاعات صف تأیید انبار پس از ویرایش سند به‌روزرسانی شد.';

    public const REASONS = [
        'low_stock' => 'کمبود موجودی',
        'available_less_than_order' => 'موجودی قابل تخصیص کمتر از سفارش',
        'damaged' => 'کالا آسیب‌دیده',
        'mismatch' => 'مغایرت کالا با سفارش',
        'seller_mistake' => 'اشتباه ثبت فروشنده',
        'cannot_ship' => 'عدم امکان ارسال',
        'not_found' => 'کالا در انبار پیدا نشد',
        'other' => 'سایر',
    ];

    public function ensureBeforeSnapshot(PreinvoiceOrder $order, ?int $userId = null, ?string $statusFrom = null): WarehouseReviewSnapshot
    {
        $latestBefore = $order->warehouseReviewSnapshots()
            ->where('type', WarehouseReviewSnapshot::TYPE_BEFORE)
            ->latest('id')
            ->first();
        $latestAfter = $order->warehouseReviewSnapshots()
            ->where('type', WarehouseReviewSnapshot::TYPE_AFTER)
            ->latest('id')
            ->first();

        if ($latestBefore && (! $latestAfter || $latestAfter->id < $latestBefore->id)) {
            return $latestBefore;
        }

        $attempt = $this->nextAttempt($order, WarehouseReviewSnapshot::TYPE_BEFORE);

        $snapshot = $this->createSnapshot($order, WarehouseReviewSnapshot::TYPE_BEFORE, $userId, [
            'review_round' => $attempt,
            'entered_queue_at' => now()->toDateTimeString(),
        ]);

        $this->log($order, WarehouseReviewLog::ACTION_ENTERED_QUEUE, $userId, $statusFrom, $order->status, 'پیش‌فاکتور وارد صف تأیید انبار شد.', [
            'review_round' => $attempt,
        ]);

        return $snapshot;
    }

    public function createAfterSnapshot(PreinvoiceOrder $order, ?int $userId = null, ?string $note = null): WarehouseReviewSnapshot
    {
        return $this->createSnapshot($order, WarehouseReviewSnapshot::TYPE_AFTER, $userId, [
            'review_round' => $this->currentAttempt($order),
            'warehouse_reviewer_id' => $userId,
            'warehouse_reviewed_at' => now()->toDateTimeString(),
            'warehouse_note' => $note,
        ]);
    }


    public function refreshActivePendingSnapshot(PreinvoiceOrder $order, ?int $userId = null): WarehouseReviewSnapshot
    {
        $snapshot = $this->createSnapshot($order, WarehouseReviewSnapshot::TYPE_BEFORE, $userId, [
            'review_round' => $this->currentAttempt($order),
            'refreshed_active_pending_at' => now()->toDateTimeString(),
            'refresh_note' => self::ACTIVE_PENDING_REFRESH_NOTE,
        ]);

        $this->log($order, WarehouseReviewLog::ACTION_CHANGES_SAVED, $userId, $order->status, $order->status, self::ACTIVE_PENDING_REFRESH_NOTE, [
            'snapshot_id' => $snapshot->id,
            'active_pending_refreshed' => true,
        ]);

        return $snapshot;
    }

    public function log(PreinvoiceOrder $order, string $action, ?int $userId = null, ?string $statusFrom = null, ?string $statusTo = null, ?string $note = null, array $meta = []): WarehouseReviewLog
    {
        return WarehouseReviewLog::query()->create([
            'preinvoice_order_id' => $order->id,
            'user_id' => $userId,
            'action' => $action,
            'status_from' => $statusFrom,
            'status_to' => $statusTo,
            'note' => $note,
            'meta' => $meta ?: null,
        ]);
    }

    public function recordItemChanges(PreinvoiceOrder $order, array $beforeItems, array $afterItems, array $reasons, ?int $userId = null): void
    {
        $before = $this->keyItems($beforeItems);
        $after = $this->keyItems($afterItems);

        foreach ($before as $key => $old) {
            $new = $after[$key] ?? null;
            $oldQty = (int) ($old['quantity'] ?? 0);
            $newQty = (int) ($new['quantity'] ?? 0);

            if ($new && $newQty === $oldQty) {
                continue;
            }

            $reasonPayload = $reasons[$key] ?? [];
            $action = $new ? WarehouseReviewLog::ACTION_ITEM_QUANTITY_CHANGED : WarehouseReviewLog::ACTION_ITEM_REMOVED;

            WarehouseReviewItemLog::query()->create([
                'preinvoice_order_id' => $order->id,
                'preinvoice_order_item_id' => null,
                'product_id' => $old['product_id'] ?? null,
                'product_variant_id' => $old['variant_id'] ?? null,
                'product_name_snapshot' => $old['product_name'] ?? null,
                'variant_name_snapshot' => $old['variant_name'] ?? null,
                'product_code_snapshot' => $old['code'] ?? null,
                'old_quantity' => $oldQty,
                'new_quantity' => $new ? $newQty : 0,
                'approved_quantity' => $new ? $newQty : 0,
                'old_price' => $old['price'] ?? null,
                'new_price' => $new['price'] ?? ($old['price'] ?? null),
                'stock_at_review' => $old['stock_at_review'] ?? null,
                'available_stock_at_review' => $old['available_stock_at_review'] ?? null,
                'action' => $action,
                'reason' => $reasonPayload['reason'] ?? null,
                'note' => $reasonPayload['note'] ?? null,
                'user_id' => $userId,
            ]);

            $this->log($order, $action, $userId, $order->status, $order->status, $this->humanItemLogText($action, $old, $new, $reasonPayload), [
                'old_quantity' => $oldQty,
                'new_quantity' => $new ? $newQty : 0,
                'reason' => $reasonPayload['reason'] ?? null,
                'reason_label' => $this->reasonLabel($reasonPayload['reason'] ?? null),
            ]);
        }
    }

    public function reasonLabel(?string $reason): ?string
    {
        if (!$reason) {
            return null;
        }

        return self::REASONS[$reason] ?? $reason;
    }

    public function compareRows(PreinvoiceOrder $order): array
    {
        $before = $order->warehouseReviewSnapshots->where('type', WarehouseReviewSnapshot::TYPE_BEFORE)->sortByDesc('id')->first()?->payload['items'] ?? null;
        $after = $order->warehouseReviewSnapshots->where('type', WarehouseReviewSnapshot::TYPE_AFTER)->sortByDesc('id')->first()?->payload['items'] ?? null;

        if (!$before) {
            $before = $this->snapshotPayload($order)['items'];
        }

        if (!$after) {
            $after = $this->snapshotPayload($order)['items'];
        }

        $beforeByKey = $this->keyItems($before);
        $afterByKey = $this->keyItems($after);
        $itemLogs = $order->warehouseReviewItemLogs->sortByDesc('id')->keyBy(fn ($log) => $this->itemKey((int) $log->product_id, (int) $log->product_variant_id));

        return collect($beforeByKey)->map(function ($old, $key) use ($afterByKey, $itemLogs) {
            $new = $afterByKey[$key] ?? null;
            $oldQty = (int) ($old['quantity'] ?? 0);
            $newQty = (int) ($new['quantity'] ?? 0);
            $log = $itemLogs->get($key);

            return [
                'product_name' => $old['product_name'] ?? ($new['product_name'] ?? '—'),
                'variant_name' => $old['variant_name'] ?? ($new['variant_name'] ?? '—'),
                'code' => $old['code'] ?? ($new['code'] ?? '—'),
                'old_quantity' => $oldQty,
                'new_quantity' => $new ? $newQty : 0,
                'change_text' => ! $new ? 'حذف شد' : ($oldQty === $newQty ? 'بدون تغییر' : $oldQty . ' → ' . $newQty),
                'item_status' => ! $new ? 'حذف‌شده' : ($oldQty === $newQty ? 'بدون تغییر' : 'کاهش تعداد'),
                'old_price' => (int) ($old['price'] ?? 0),
                'new_price' => (int) ($new['price'] ?? ($old['price'] ?? 0)),
                'old_total' => $oldQty * (int) ($old['price'] ?? 0),
                'new_total' => ($new ? $newQty : 0) * (int) ($new['price'] ?? ($old['price'] ?? 0)),
                'reason' => $this->reasonLabel($log?->reason) ?? $log?->reason,
                'note' => $log?->note,
            ];
        })->values()->all();
    }

    public function timelineText(WarehouseReviewLog $log): string
    {
        if ($log->note) {
            return $log->note;
        }

        return match ($log->action) {
            WarehouseReviewLog::ACTION_ENTERED_QUEUE => 'پیش‌فاکتور وارد صف تأیید انبار شد.',
            WarehouseReviewLog::ACTION_APPROVED_TO_FINANCE => 'پیش‌فاکتور تأیید و به صف مالی ارسال شد.',
            WarehouseReviewLog::ACTION_REJECTED_TO_CREATOR => 'پیش‌فاکتور به ثبت‌کننده برگشت داده شد.',
            WarehouseReviewLog::ACTION_RESUBMITTED_TO_WAREHOUSE => 'پیش‌فاکتور بعد از اصلاح دوباره به صف انبار ارسال شد.',
            WarehouseReviewLog::ACTION_SNAPSHOT_CREATED => 'نسخه ثابت بازبینی انبار ثبت شد.',
            default => $log->action,
        };
    }

    public function snapshotPayload(PreinvoiceOrder $order, array $extra = []): array
    {
        $order->loadMissing(['items.product', 'items.variant', 'creator:id,name', 'customer']);

        $items = $order->items->map(fn (PreinvoiceOrderItem $item) => $this->snapshotItem($item))->values()->all();

        return array_merge([
            'preinvoice_order_id' => (int) $order->id,
            'uuid' => $order->uuid,
            'customer_id' => $order->customer_id,
            'customer_name' => $order->customer_name,
            'customer_code' => $order->customer?->code ?? null,
            'customer_mobile' => $order->customer_mobile,
            'creator_id' => $order->created_by,
            'creator_name' => $order->creator?->name,
            'created_at' => optional($order->created_at)->toDateTimeString(),
            'status' => $order->status,
            'total_price' => (int) $order->total_price,
            'shipping_price' => (int) $order->shipping_price,
            'discount_amount' => (int) $order->discount_amount,
            'items_count' => count($items),
            'items' => $items,
        ], $extra);
    }

    private function createSnapshot(PreinvoiceOrder $order, string $type, ?int $userId = null, array $extra = []): WarehouseReviewSnapshot
    {
        $snapshot = WarehouseReviewSnapshot::query()->create([
            'preinvoice_order_id' => $order->id,
            'type' => $type,
            'payload' => $this->snapshotPayload($order, $extra),
            'created_by' => $userId,
        ]);

        $this->log($order, WarehouseReviewLog::ACTION_SNAPSHOT_CREATED, $userId, $order->status, $order->status, $type === WarehouseReviewSnapshot::TYPE_BEFORE ? 'Snapshot اولیه بازبینی انبار ثبت شد.' : 'Snapshot نهایی بازبینی انبار ثبت شد.', [
            'snapshot_id' => $snapshot->id,
            'snapshot_type' => $type,
            'review_round' => $extra['review_round'] ?? null,
        ]);

        return $snapshot;
    }

    private function snapshotItem(PreinvoiceOrderItem $item): array
    {
        $variant = $item->variant;
        $stock = $variant ? max(0, (int) $variant->stock) : null;

        return [
            'item_id' => (int) $item->id,
            'product_id' => (int) $item->product_id,
            'variant_id' => (int) $item->variant_id,
            'product_name' => $item->product?->name,
            'variant_name' => $variant?->variant_name ?: $variant?->variety_name,
            'code' => $variant?->sku ?: ($variant?->variant_code ?: ($variant?->barcode ?: ($item->product?->sku ?: $item->product?->code))),
            'barcode' => $variant?->barcode ?: $item->product?->barcode,
            'quantity' => (int) $item->quantity,
            'price' => (int) $item->price,
            'line_total' => (int) $item->quantity * (int) $item->price,
            'stock_at_review' => $stock,
            'available_stock_at_review' => $stock !== null ? max(0, $stock - (int) ($variant?->reserved ?? 0)) : null,
            'reserved_at_review' => $variant ? (int) $variant->reserved : null,
        ];
    }

    private function keyItems(array|Collection $items): array
    {
        return collect($items)->keyBy(fn ($item) => $this->itemKey((int) ($item['product_id'] ?? 0), (int) ($item['variant_id'] ?? 0)))->all();
    }

    private function itemKey(int $productId, int $variantId): string
    {
        return $productId . ':' . $variantId;
    }

    private function nextAttempt(PreinvoiceOrder $order, string $type): int
    {
        return (int) $order->warehouseReviewSnapshots()->where('type', $type)->count() + 1;
    }

    private function currentAttempt(PreinvoiceOrder $order): int
    {
        return max(1, (int) $order->warehouseReviewSnapshots()->where('type', WarehouseReviewSnapshot::TYPE_BEFORE)->count());
    }

    private function humanItemLogText(string $action, array $old, ?array $new, array $reasonPayload): string
    {
        $name = $old['product_name'] ?? 'آیتم';
        $reason = $this->reasonLabel($reasonPayload['reason'] ?? null);
        $suffix = $reason ? " دلیل: {$reason}" : '';

        if ($action === WarehouseReviewLog::ACTION_ITEM_REMOVED) {
            return "آیتم «{$name}» از پیش‌فاکتور حذف شد.{$suffix}";
        }

        return "تعداد «{$name}» از " . (int) ($old['quantity'] ?? 0) . ' به ' . (int) ($new['quantity'] ?? 0) . " تغییر کرد.{$suffix}";
    }
}
