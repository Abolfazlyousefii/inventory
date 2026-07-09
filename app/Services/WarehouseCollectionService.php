<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseCollectionService
{
    public function __construct(
        private readonly SalesHavalehHistoryService $historyService,
        private readonly CustomerLedgerService $customerLedgerService,
    ) {}

    public function receiveInvoice(Invoice $invoice, User $user): Invoice
    {
        return DB::transaction(function () use ($invoice, $user) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status === Invoice::STATUS_PENDING_WAREHOUSE_APPROVAL) {
                throw ValidationException::withMessages(['status' => 'این فاکتور مربوط به روند قدیمی است و از صف جمع‌آوری جدید قابل دریافت نیست.']);
            }

            $this->assertStatus($invoice, [Invoice::STATUS_PENDING_COLLECTION]);
            return $this->mark($invoice, Invoice::STATUS_WAREHOUSE_RECEIVED, [
                'warehouse_received_at' => now(),
                'warehouse_received_by' => $user->id,
            ], 'warehouse_received', $user->id, 'فاکتور توسط انبار دریافت شد.');
        });
    }

    public function startCollection(Invoice $invoice, User $user): Invoice
    {
        return DB::transaction(function () use ($invoice, $user) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($invoice, [Invoice::STATUS_PENDING_COLLECTION, Invoice::STATUS_WAREHOUSE_RECEIVED]);
            return $this->mark($invoice, Invoice::STATUS_COLLECTING, [
                'collection_started_at' => now(),
                'collection_started_by' => $user->id,
            ], 'collection_started', $user->id, 'جمع‌آوری فاکتور شروع شد.');
        });
    }

    public function completeWithoutChanges(Invoice $invoice, User $user, ?string $note = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $user, $note) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($invoice, [Invoice::STATUS_WAREHOUSE_RECEIVED, Invoice::STATUS_COLLECTING]);
            return $this->mark($invoice, Invoice::STATUS_READY_TO_SHIP, [
                'collected_at' => now(),
                'collected_by' => $user->id,
                'collection_note' => $note,
            ], 'collection_completed_without_changes', $user->id, $note ?: 'جمع‌آوری فاکتور نهایی شد و به صف ارسال بار منتقل شد.');
        });
    }

    public function completeCollection(Invoice $invoice, User $user, ?string $note = null): Invoice
    {
        return $this->completeWithoutChanges($invoice, $user, $note);
    }

    public function updateCollectedItems(Invoice $invoice, array $items, User $user, ?string $note = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $items, $user, $note) {
            $invoice = Invoice::query()->with('items')->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($invoice, [Invoice::STATUS_WAREHOUSE_RECEIVED, Invoice::STATUS_COLLECTING]);

            $oldByVariant = $invoice->items->groupBy('variant_id')->map(fn ($rows) => (int) $rows->sum('quantity'));
            $existingById = $invoice->items->keyBy('id');
            $normalized = [];

            foreach ($items as $row) {
                $qty = (int) $row['quantity'];
                $itemId = (int) ($row['invoice_item_id'] ?? $row['id'] ?? 0);
                $existing = $itemId > 0 ? $existingById->get($itemId) : null;

                if ($itemId > 0 && ! $existing) {
                    throw ValidationException::withMessages(['items' => 'آیتم انتخاب‌شده متعلق به این فاکتور نیست.']);
                }

                if (! $existing && $qty <= 0 && empty($row['variant_id'])) {
                    continue;
                }

                $variantId = $existing ? (int) $existing->variant_id : (int) $row['variant_id'];
                $productId = $existing ? (int) $existing->product_id : (int) $row['product_id'];
                $variant = ProductVariant::query()->whereKey($variantId)->where('product_id', $productId)->lockForUpdate()->first();
                if (! $variant) {
                    throw ValidationException::withMessages(['items' => 'تنوع انتخاب‌شده برای محصول معتبر نیست.']);
                }
                if (! $existing && ! $variant->is_active) {
                    throw ValidationException::withMessages(['items' => 'تنوع کالای انتخاب‌شده فعال نیست.']);
                }
                if (! $existing && $qty > 0 && (int) $variant->sell_price <= 0) {
                    $name = trim(($variant->product?->name ?? 'نامشخص') . ' / ' . ($variant->variant_name ?: $variant->variety_name ?: ('#' . $variant->id)));
                    throw ValidationException::withMessages(['price' => "قیمت کالا/تنوع {$name} صفر است و امکان ثبت فاکتور وجود ندارد."]);
                }

                $normalized[] = compact('itemId', 'existing', 'variant', 'variantId', 'productId', 'qty');
            }

            if (collect($normalized)->sum('qty') <= 0) {
                throw ValidationException::withMessages(['items' => 'فاکتور باید حداقل یک قلم کالا داشته باشد.']);
            }

            $newByVariant = collect($normalized)->groupBy('variantId')->map(fn ($rows) => (int) collect($rows)->sum('qty'));
            foreach ($oldByVariant->keys()->merge($newByVariant->keys())->unique() as $variantId) {
                $delta = (int) ($newByVariant[$variantId] ?? 0) - (int) ($oldByVariant[$variantId] ?? 0);
                if ($delta === 0) {
                    continue;
                }
                $variant = ProductVariant::query()->with('product')->whereKey((int) $variantId)->lockForUpdate()->firstOrFail();
                if ($delta > 0) {
                    $available = $this->centralAvailableStockForUpdate((int) $variant->product_id, (int) $variant->id);
                    if ($available < $delta) {
                        $name = trim(($variant->product?->name ?? 'کالا') . ' / ' . ($variant->variant_name ?: $variant->variety_name ?: ('#' . $variant->id)));
                        throw ValidationException::withMessages([
                            'items' => "موجودی کافی برای {$name} وجود ندارد. موجودی قابل فروش: {$available}، تعداد درخواستی: {$delta}",
                        ]);
                    }
                }
                WarehouseStockService::change(WarehouseStockService::centralWarehouseId(), (int) $variant->product_id, -$delta, (int) $variant->id);
            }

            $aggregated = collect($normalized)
                ->groupBy('variantId')
                ->map(function ($rows) {
                    $first = $rows->first();
                    $existing = $rows->firstWhere('existing', '!=', null)['existing'] ?? null;
                    return array_merge($first, [
                        'existing' => $existing,
                        'itemId' => $existing ? (int) $existing->id : 0,
                        'qty' => (int) $rows->sum('qty'),
                    ]);
                })
                ->values()
                ->all();

            $seen = [];
            foreach ($aggregated as $row) {
                /** @var InvoiceItem|null $existing */
                $existing = $row['existing'];
                $qty = (int) $row['qty'];
                if ($existing && $qty <= 0) {
                    $existing->delete();
                    continue;
                }
                if ($existing) {
                    $seen[] = (int) $existing->id;
                    $existing->update([
                        'quantity' => $qty,
                        'line_total' => max($qty * (int) $existing->price - (int) ($existing->line_discount_amount ?? 0), 0),
                    ]);
                    continue;
                }
                if ($qty <= 0) {
                    continue;
                }
                $created = InvoiceItem::query()->create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $row['productId'],
                    'variant_id' => $row['variantId'],
                    'quantity' => $qty,
                    'price' => (int) $row['variant']->sell_price,
                    'line_discount_amount' => 0,
                    'line_total' => $qty * (int) $row['variant']->sell_price,
                ]);
                $seen[] = (int) $created->id;
            }

            foreach ($invoice->items as $item) {
                if (! in_array((int) $item->id, $seen, true)) {
                    $item->delete();
                }
            }

            $invoice->refresh()->load('items');
            $subtotal = (int) $invoice->items->sum(fn (InvoiceItem $item) => (int) $item->quantity * (int) $item->price);
            $discount = max((int) $invoice->discount_amount, (int) $invoice->items->sum(fn (InvoiceItem $item) => (int) ($item->line_discount_amount ?? 0)));
            $oldStatus = (string) $invoice->status;
            $invoice->update([
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'total' => max($subtotal - $discount, 0),
                'status' => Invoice::STATUS_PENDING_FINANCE_REAPPROVAL,
                'status_changed_at' => now(),
                'status_changed_by' => $user->id,
                'items_updated_at' => now(),
                'items_updated_by' => $user->id,
                'collection_note' => $note,
            ]);
            $this->historyService->log($invoice, 'collection_items_updated', 'status', $oldStatus, Invoice::STATUS_PENDING_FINANCE_REAPPROVAL, $note ?: 'اقلام توسط انبار تغییر کرد و نیازمند تایید مجدد مالی شد.', $user->id);

            return $invoice->fresh(['items.product', 'items.variant']);
        });
    }

    public function updateInvoiceItemsInPlace(Invoice $invoice, array $items, User $user, bool $canEditPrices = false, ?string $reason = null, ?string $note = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $items, $user, $canEditPrices, $reason, $note) {
            $invoice = Invoice::query()->with(['items', 'payments'])->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ((string) $invoice->status === Invoice::STATUS_SHIPPED) {
                throw ValidationException::withMessages(['status' => 'فاکتور ارسال‌شده قابل تغییر اقلام نیست.']);
            }

            $oldByVariant = $invoice->items->groupBy('variant_id')->map(fn ($rows) => (int) $rows->sum('quantity'));
            $existingById = $invoice->items->keyBy('id');
            $normalized = [];

            foreach ($items as $row) {
                $qty = (int) $row['quantity'];
                $itemId = (int) ($row['invoice_item_id'] ?? $row['id'] ?? 0);
                $existing = $itemId > 0 ? $existingById->get($itemId) : null;

                if ($itemId > 0 && ! $existing) {
                    throw ValidationException::withMessages(['items' => 'آیتم انتخاب‌شده متعلق به این فاکتور نیست.']);
                }

                if (! $existing && $qty <= 0 && empty($row['variant_id'])) {
                    continue;
                }

                $variantId = $existing ? (int) $existing->variant_id : (int) $row['variant_id'];
                $productId = $existing ? (int) $existing->product_id : (int) $row['product_id'];
                $variant = ProductVariant::query()->whereKey($variantId)->where('product_id', $productId)->lockForUpdate()->first();
                if (! $variant) {
                    throw ValidationException::withMessages(['items' => 'تنوع انتخاب‌شده برای محصول معتبر نیست.']);
                }
                if (! $existing && ! $variant->is_active) {
                    throw ValidationException::withMessages(['items' => 'تنوع کالای انتخاب‌شده فعال نیست.']);
                }
                if (! $existing && $qty > 0 && (int) $variant->sell_price <= 0) {
                    $name = trim(($variant->product?->name ?? 'نامشخص') . ' / ' . ($variant->variant_name ?: $variant->variety_name ?: ('#' . $variant->id)));
                    throw ValidationException::withMessages(['price' => "قیمت کالا/تنوع {$name} صفر است و امکان ثبت فاکتور وجود ندارد."]);
                }

                $requestedPrice = array_key_exists('price', $row) ? (int) $row['price'] : null;
                $requestedDiscount = array_key_exists('line_discount_amount', $row) ? (int) $row['line_discount_amount'] : null;

                if (! $canEditPrices && (($existing && ($requestedPrice !== null && $requestedPrice !== (int) $existing->price)) || ($existing && ($requestedDiscount !== null && $requestedDiscount !== (int) ($existing->line_discount_amount ?? 0))))) {
                    throw ValidationException::withMessages(['price' => 'شما مجاز به تغییر قیمت یا تخفیف فاکتور نیستید.']);
                }

                $price = $canEditPrices && $requestedPrice !== null ? $requestedPrice : ($existing ? (int) $existing->price : (int) $variant->sell_price);
                $discount = $canEditPrices && $requestedDiscount !== null ? $requestedDiscount : ($existing ? (int) ($existing->line_discount_amount ?? 0) : 0);

                if ($qty > 0 && $price <= 0) {
                    throw ValidationException::withMessages(['price' => 'قیمت واحد باید بزرگ‌تر از صفر باشد.']);
                }
                if ($discount < 0) {
                    throw ValidationException::withMessages(['line_discount_amount' => 'تخفیف ردیف نمی‌تواند منفی باشد.']);
                }
                if ($discount > ($qty * $price)) {
                    throw ValidationException::withMessages(['line_discount_amount' => 'تخفیف ردیف نباید بیشتر از جمع ردیف باشد.']);
                }

                $priceChanged = $existing && ((int) $existing->price !== $price || (int) ($existing->line_discount_amount ?? 0) !== $discount);
                if ($priceChanged && trim((string) ($reason ?: $note)) === '') {
                    throw ValidationException::withMessages(['change_note' => 'برای تغییر قیمت یا تخفیف، توضیح تغییر الزامی است.']);
                }

                $normalized[] = compact('itemId', 'existing', 'variant', 'variantId', 'productId', 'qty', 'price', 'discount', 'priceChanged');
            }

            if (collect($normalized)->sum('qty') <= 0) {
                throw ValidationException::withMessages(['items' => 'فاکتور باید حداقل یک قلم کالا داشته باشد.']);
            }

            $newByVariant = collect($normalized)->groupBy('variantId')->map(fn ($rows) => (int) collect($rows)->sum('qty'));
            foreach ($oldByVariant->keys()->merge($newByVariant->keys())->unique() as $variantId) {
                $delta = (int) ($newByVariant[$variantId] ?? 0) - (int) ($oldByVariant[$variantId] ?? 0);
                if ($delta === 0) {
                    continue;
                }
                $variant = ProductVariant::query()->with('product')->whereKey((int) $variantId)->lockForUpdate()->firstOrFail();
                if ($delta > 0) {
                    $available = $this->centralAvailableStockForUpdate((int) $variant->product_id, (int) $variant->id);
                    if ($available < $delta) {
                        $name = trim(($variant->product?->name ?? 'کالا') . ' / ' . ($variant->variant_name ?: $variant->variety_name ?: ('#' . $variant->id)));
                        throw ValidationException::withMessages(['items' => "موجودی کافی برای {$name} وجود ندارد. موجودی قابل فروش: {$available}، تعداد درخواستی: {$delta}"]);
                    }
                }
                WarehouseStockService::change(WarehouseStockService::centralWarehouseId(), (int) $variant->product_id, -$delta, (int) $variant->id);
            }

            $aggregated = collect($normalized)->groupBy('variantId')->map(function ($rows) {
                $first = $rows->first();
                $existing = $rows->firstWhere('existing', '!=', null)['existing'] ?? null;
                $qty = (int) $rows->sum('qty');
                $subtotal = (int) $rows->sum(fn ($row) => (int) $row['qty'] * (int) $row['price']);
                $discount = (int) $rows->sum('discount');
                $price = $qty > 0 ? intdiv($subtotal, $qty) : (int) $first['price'];
                return array_merge($first, ['existing' => $existing, 'itemId' => $existing ? (int) $existing->id : 0, 'qty' => $qty, 'price' => $price, 'discount' => min($discount, max($qty * $price, 0))]);
            })->values()->all();

            $seen = [];
            foreach ($aggregated as $row) {
                $existing = $row['existing'];
                $qty = (int) $row['qty'];
                if ($existing && $qty <= 0) { $existing->delete(); continue; }
                if ($existing) {
                    $seen[] = (int) $existing->id;
                    if (((int) $existing->price !== (int) $row['price'] || (int) ($existing->line_discount_amount ?? 0) !== (int) $row['discount']) && trim((string) ($reason ?: $note)) === '') {
                        throw ValidationException::withMessages(['change_note' => 'برای تغییر قیمت یا تخفیف، توضیح تغییر الزامی است.']);
                    }
                    if ((int) $row['discount'] > $qty * (int) $row['price']) {
                        throw ValidationException::withMessages(['line_discount_amount' => 'تخفیف ردیف نباید بیشتر از جمع ردیف باشد.']);
                    }
                    $existing->update(['quantity' => $qty, 'price' => (int) $row['price'], 'line_discount_amount' => (int) $row['discount'], 'line_total' => max($qty * (int) $row['price'] - (int) $row['discount'], 0)]);
                    continue;
                }
                if ($qty <= 0) { continue; }
                $created = InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'product_id' => $row['productId'], 'variant_id' => $row['variantId'], 'quantity' => $qty, 'price' => (int) $row['price'], 'line_discount_amount' => (int) $row['discount'], 'line_total' => max($qty * (int) $row['price'] - (int) $row['discount'], 0)]);
                $seen[] = (int) $created->id;
            }

            foreach ($invoice->items as $item) {
                if (! in_array((int) $item->id, $seen, true)) { $item->delete(); }
            }

            $invoice->refresh()->load('items');
            $subtotal = (int) $invoice->items->sum(fn (InvoiceItem $item) => (int) $item->quantity * (int) $item->price);
            $discount = (int) $invoice->items->sum(fn (InvoiceItem $item) => (int) ($item->line_discount_amount ?? 0));
            $total = max($subtotal - $discount, 0);
            if ($total < (int) $invoice->payments->sum('amount')) {
                throw ValidationException::withMessages(['total' => 'مبلغ جدید فاکتور کمتر از مبلغ پرداخت‌شده است. ابتدا پرداخت‌ها را اصلاح کنید یا مبلغ فاکتور را بررسی کنید.']);
            }
            $invoice->update(['subtotal' => $subtotal, 'discount_amount' => $discount, 'total' => $total, 'items_updated_at' => now(), 'items_updated_by' => $user->id, 'collection_note' => $note]);
            $this->customerLedgerService->syncInvoiceDebit($invoice->fresh());
            $description = trim(($reason ? 'دلیل: ' . $reason . ' - ' : '') . ($note ?: 'تغییر اقلام فاکتور ثبت شد.'));
            $this->historyService->log($invoice, 'invoice_items_updated', 'items', null, null, $description, $user->id);

            return $invoice->fresh(['items.product', 'items.variant']);
        });
    }

    private function centralAvailableStockForUpdate(int $productId, int $variantId): int
    {
        $stock = WarehouseStock::query()
            ->where('warehouse_id', WarehouseStockService::centralWarehouseId())
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->first();

        return max(0, (int) ($stock?->quantity ?? 0));
    }

    private function assertStatus(Invoice $invoice, array $allowed): void
    {
        if (! in_array((string) $invoice->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'وضعیت فعلی فاکتور برای این عملیات مجاز نیست.']);
        }
    }

    private function mark(Invoice $invoice, string $status, array $extra, string $action, int $userId, string $description): Invoice
    {
        $oldStatus = (string) $invoice->status;
        $invoice->update(array_merge($extra, [
            'status' => $status,
            'status_changed_at' => now(),
            'status_changed_by' => $userId,
        ]));
        $this->historyService->log($invoice, $action, 'status', $oldStatus, $status, $description, $userId);
        return $invoice->fresh();
    }
}
