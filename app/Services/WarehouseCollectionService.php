<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Models\User;
use App\Support\SalesDocumentTotals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
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

    public function updateCollectedItems(Invoice $invoice, array $items, User $user, ?string $note = null, bool $canEditPrices = false, ?string $reason = null, ?string $openedAt = null): Invoice
    {
        $updatedInvoice = DB::transaction(function () use ($invoice, $items, $user, $note, $canEditPrices, $reason, $openedAt) {
            $invoice = Invoice::query()->with(['payments'])->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $lockedItems = InvoiceItem::query()->with(['product', 'variant'])->where('invoice_id', $invoice->id)->orderBy('id')->lockForUpdate()->get();
            $invoice->setRelation('items', $lockedItems);
            $this->assertStatus($invoice, [Invoice::STATUS_WAREHOUSE_RECEIVED, Invoice::STATUS_COLLECTING]);

            $currentStamp = optional($invoice->items_updated_at ?: $invoice->updated_at)->toJSON();
            if ($openedAt && $currentStamp && Carbon::parse($openedAt)->ne(Carbon::parse($currentStamp))) {
                throw ValidationException::withMessages(['opened_at' => 'این فاکتور توسط کاربر دیگری تغییر کرده است. صفحه را مجدداً بارگذاری و تغییرات را بررسی کنید.']);
            }

            $oldTotal = (int) $invoice->total;
            $oldByVariant = $invoice->items->groupBy('variant_id')->map(fn ($rows) => (int) $rows->sum('quantity'));
            $existingById = $invoice->items->keyBy('id');
            $normalized = [];
            $revisionRows = [];

            foreach ($items as $row) {
                $deleteRequested = filter_var($row['_delete'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $qty = $deleteRequested ? 0 : (int) $row['quantity'];
                $itemId = (int) ($row['invoice_item_id'] ?? $row['id'] ?? 0);
                $existing = $itemId > 0 ? $existingById->get($itemId) : null;
                if (! $existing && $qty <= 0) {
                    continue;
                }
                if ($itemId > 0 && ! $existing) {
                    throw ValidationException::withMessages(['items' => 'آیتم انتخاب‌شده متعلق به این فاکتور نیست.']);
                }
                if ($existing && $qty <= 0) {
                    continue;
                }

                $variantId = $existing ? (int) $existing->variant_id : (int) $row['variant_id'];
                $productId = $existing ? (int) $existing->product_id : (int) $row['product_id'];
                $variant = ProductVariant::query()->with('product')->whereKey($variantId)->where('product_id', $productId)->lockForUpdate()->first();
                if (! $variant) {
                    throw ValidationException::withMessages(['items' => 'تنوع انتخاب‌شده برای محصول معتبر نیست.']);
                }
                if (! $existing && ! $variant->is_active) {
                    throw ValidationException::withMessages(['items' => 'تنوع کالای انتخاب‌شده فعال نیست.']);
                }

                $requestedPrice = array_key_exists('price', $row) ? (int) $row['price'] : null;
                $requestedDiscount = array_key_exists('line_discount_amount', $row) ? (int) $row['line_discount_amount'] : null;
                if (! $canEditPrices && $existing && (($requestedPrice !== null && $requestedPrice !== (int) $existing->price) || ($requestedDiscount !== null && $requestedDiscount !== (int) ($existing->line_discount_amount ?? 0)))) {
                    throw ValidationException::withMessages(['price' => 'شما مجاز به تغییر قیمت یا تخفیف فاکتور نیستید.']);
                }
                if (! $canEditPrices && ! $existing && (($requestedPrice !== null && $requestedPrice !== (int) $variant->sell_price) || ($requestedDiscount !== null && $requestedDiscount !== 0))) {
                    throw ValidationException::withMessages(['price' => 'شما مجاز به تغییر قیمت یا تخفیف فاکتور نیستید.']);
                }

                $price = $canEditPrices && $requestedPrice !== null ? $requestedPrice : ($existing ? (int) $existing->price : (int) $variant->sell_price);
                $discount = $canEditPrices && $requestedDiscount !== null ? $requestedDiscount : ($existing ? (int) ($existing->line_discount_amount ?? 0) : 0);
                if ($qty > 0 && $price <= 0) {
                    throw ValidationException::withMessages(['price' => 'قیمت واحد باید بزرگ‌تر از صفر باشد.']);
                }
                if ($discount < 0 || $discount > ($qty * $price)) {
                    throw ValidationException::withMessages(['line_discount_amount' => 'تخفیف ردیف معتبر نیست.']);
                }

                $normalized[] = compact('itemId', 'existing', 'variant', 'variantId', 'productId', 'qty', 'price', 'discount');
            }

            if (collect($normalized)->sum('qty') <= 0) {
                throw ValidationException::withMessages(['items' => 'فاکتور باید حداقل یک قلم کالا داشته باشد.']);
            }

            $newByVariant = collect($normalized)->groupBy('variantId')->map(fn ($rows) => (int) collect($rows)->sum('qty'));
            $variantIds = $oldByVariant->keys()->merge($newByVariant->keys())->unique()->values();
            $stocks = WarehouseStock::query()
                ->where('warehouse_id', WarehouseStockService::centralWarehouseId())
                ->whereIn('product_variant_id', $variantIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_variant_id');

            foreach ($variantIds as $variantId) {
                $delta = (int) ($newByVariant[$variantId] ?? 0) - (int) ($oldByVariant[$variantId] ?? 0);
                if ($delta <= 0) {
                    continue;
                }
                $stock = $stocks->get((int) $variantId);
                $available = (int) ($stock?->quantity ?? 0);
                if ($available < $delta) {
                    $variant = ProductVariant::query()->with('product')->findOrFail((int) $variantId);
                    $name = trim(($variant->product?->name ?? 'کالا') . ' / ' . ($variant->variant_name ?: $variant->variety_name ?: ('#' . $variant->id)));
                    throw ValidationException::withMessages(['items' => "موجودی کافی برای {$name} وجود ندارد. موجودی قابل فروش: {$available}، تعداد اضافه‌شده: {$delta}"]);
                }
            }

            foreach ($variantIds as $variantId) {
                $delta = (int) ($newByVariant[$variantId] ?? 0) - (int) ($oldByVariant[$variantId] ?? 0);
                if ($delta === 0) { continue; }
                $variant = ProductVariant::query()->whereKey((int) $variantId)->lockForUpdate()->firstOrFail();
                WarehouseStockService::change(WarehouseStockService::centralWarehouseId(), (int) $variant->product_id, -$delta, (int) $variant->id);
            }

            $seen = [];
            foreach ($normalized as $row) {
                $existing = $row['existing'];
                $old = $existing ? [
                    'quantity' => (int) $existing->quantity,
                    'price' => (int) $existing->price,
                    'discount' => (int) ($existing->line_discount_amount ?? 0),
                    'line_total' => (int) $existing->line_total,
                ] : null;

                if ($existing) {
                    $seen[] = (int) $existing->id;
                    $existing->update(['quantity' => (int) $row['qty'], 'price' => (int) $row['price'], 'line_discount_amount' => (int) $row['discount'], 'line_total' => max(((int) $row['qty'] * (int) $row['price']) - (int) $row['discount'], 0)]);
                    $changed = $old['quantity'] !== (int) $row['qty'] || $old['price'] !== (int) $row['price'] || $old['discount'] !== (int) $row['discount'];
                    if ($changed) {
                        $revisionRows[] = $this->revisionItemPayload($existing->fresh(['product','variant']), $old, 'multiple_changes');
                    }
                    continue;
                }

                $created = InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'product_id' => $row['productId'], 'variant_id' => $row['variantId'], 'quantity' => (int) $row['qty'], 'price' => (int) $row['price'], 'line_discount_amount' => (int) $row['discount'], 'line_total' => max(((int) $row['qty'] * (int) $row['price']) - (int) $row['discount'], 0)]);
                $seen[] = (int) $created->id;
                $revisionRows[] = $this->revisionItemPayload($created->fresh(['product','variant']), null, 'added');
            }

            foreach ($invoice->items as $item) {
                if (! in_array((int) $item->id, $seen, true)) {
                    $revisionRows[] = $this->revisionItemPayload($item, ['quantity' => (int) $item->quantity, 'price' => (int) $item->price, 'discount' => (int) ($item->line_discount_amount ?? 0), 'line_total' => (int) $item->line_total], 'removed', true);
                    $item->delete();
                }
            }

            $invoice->refresh()->load('items');
            $documentDiscount = (int) ($invoice->invoice_discount_amount ?? 0);
            if ($documentDiscount <= 0 && (int) ($invoice->product_discount_amount ?? 0) <= 0) {
                $documentDiscount = max((int) ($invoice->discount_amount ?? 0) - (int) $invoice->items->sum(fn (InvoiceItem $item) => SalesDocumentTotals::lineDiscount($item)), 0);
            }
            $totals = SalesDocumentTotals::fromDocument($invoice);
            $subtotal = (int) $totals['subtotal_before_discount'];
            $discount = (int) $totals['total_discount'];
            $total = (int) $totals['grand_total'];
            if ($total < (int) $invoice->payments->sum('amount')) {
                throw ValidationException::withMessages(['total' => 'مبلغ جدید فاکتور کمتر از مبلغ پرداخت‌شده است.']);
            }

            $oldStatus = (string) $invoice->status;
            $invoice->update(['subtotal' => $subtotal, 'product_discount_amount' => (int) $totals['items_discount'], 'invoice_discount_amount' => (int) $totals['invoice_discount'], 'discount_amount' => $discount, 'total' => $total, 'status' => Invoice::STATUS_PENDING_FINANCE_REAPPROVAL, 'status_changed_at' => now(), 'status_changed_by' => $user->id, 'items_updated_at' => now(), 'items_updated_by' => $user->id, 'collection_note' => trim((string) ($reason ? $reason . ' - ' : '') . (string) $note)]);
            $this->storeCollectionRevision($invoice, $oldTotal, $total, (string) $reason, $note, $user->id, $revisionRows);
            $this->historyService->log($invoice, 'collection_items_updated', 'status', $oldStatus, Invoice::STATUS_PENDING_FINANCE_REAPPROVAL, $note ?: 'اقلام توسط انبار تغییر کرد و نیازمند تایید مجدد مالی شد.', $user->id);

            return $invoice->fresh(['items.product', 'items.variant']);
        });

        return $updatedInvoice;
    }

    private function revisionItemPayload(InvoiceItem $item, ?array $old, string $type, bool $removed = false): array
    {
        return [
            'invoice_item_id' => $removed ? null : $item->id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->variant_id,
            'change_type' => $type,
            'product_name_snapshot' => $item->product?->name,
            'variant_name_snapshot' => $item->variant?->variant_name ?: $item->variant?->variety_name,
            'sku_snapshot' => $item->variant?->variant_code ?: $item->variant?->variety_code,
            'old_quantity' => $old['quantity'] ?? null,
            'new_quantity' => $removed ? null : (int) $item->quantity,
            'old_price' => $old['price'] ?? null,
            'new_price' => $removed ? null : (int) $item->price,
            'old_discount' => $old['discount'] ?? null,
            'new_discount' => $removed ? null : (int) ($item->line_discount_amount ?? 0),
            'old_line_total' => $old['line_total'] ?? null,
            'new_line_total' => $removed ? null : (int) $item->line_total,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function storeCollectionRevision(Invoice $invoice, int $oldTotal, int $newTotal, string $reason, ?string $note, int $userId, array $items): void
    {
        if (! DB::getSchemaBuilder()->hasTable('invoice_collection_revisions')) {
            return;
        }
        $revisionNumber = ((int) DB::table('invoice_collection_revisions')->where('invoice_id', $invoice->id)->max('revision_number')) + 1;
        $revisionId = DB::table('invoice_collection_revisions')->insertGetId(['invoice_id' => $invoice->id, 'revision_number' => $revisionNumber, 'old_total' => $oldTotal, 'new_total' => $newTotal, 'reason_type' => $reason, 'reason_note' => $note, 'changed_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($items as $item) {
            $item['invoice_collection_revision_id'] = $revisionId;
            DB::table('invoice_collection_revision_items')->insert($item);
        }
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
                        throw ValidationException::withMessages(['items' => "موجودی کافی برای {$name} وجود ندارد. موجودی قابل فروش: {$available}، تعداد اضافه‌شده: {$delta}"]);
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

            $priceChangeLogs = [];
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
                    if ((int) $existing->price !== (int) $row['price'] || (int) ($existing->line_discount_amount ?? 0) !== (int) $row['discount']) {
                        $priceChangeLogs[] = [
                            'product' => $existing->product?->name ?? ('#' . $existing->product_id),
                            'variant' => $existing->variant?->variant_name ?: $existing->variant?->variety_name ?: ($existing->variant_id ? ('#' . $existing->variant_id) : '—'),
                            'old_price' => (int) $existing->price,
                            'new_price' => (int) $row['price'],
                            'old_discount' => (int) ($existing->line_discount_amount ?? 0),
                            'new_discount' => (int) $row['discount'],
                        ];
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
            $documentDiscount = (int) ($invoice->invoice_discount_amount ?? 0);
            if ($documentDiscount <= 0 && (int) ($invoice->product_discount_amount ?? 0) <= 0) {
                $documentDiscount = max((int) ($invoice->discount_amount ?? 0) - (int) $invoice->items->sum(fn (InvoiceItem $item) => SalesDocumentTotals::lineDiscount($item)), 0);
            }
            $totals = SalesDocumentTotals::fromDocument($invoice);
            $subtotal = (int) $totals['subtotal_before_discount'];
            $discount = (int) $totals['total_discount'];
            $total = (int) $totals['grand_total'];
            if ($total < (int) $invoice->payments->sum('amount')) {
                throw ValidationException::withMessages(['total' => 'مبلغ جدید فاکتور کمتر از مبلغ پرداخت‌شده است. ابتدا پرداخت‌ها را اصلاح کنید یا مبلغ فاکتور را بررسی کنید.']);
            }
            $invoice->update(['subtotal' => $subtotal, 'discount_amount' => $discount, 'total' => $total, 'items_updated_at' => now(), 'items_updated_by' => $user->id, 'collection_note' => $note]);
            $this->customerLedgerService->syncInvoiceDebit($invoice->fresh());
            $description = trim(($reason ? 'دلیل: ' . $reason . ' - ' : '') . ($note ?: 'تغییر اقلام فاکتور ثبت شد.'));
            $this->historyService->log($invoice, 'invoice_items_updated', 'items', null, null, $description, $user->id);
            foreach ($priceChangeLogs as $logRow) {
                $this->historyService->log(
                    $invoice,
                    'invoice_price_discount_changed',
                    'price_discount',
                    json_encode(['price' => $logRow['old_price'], 'discount' => $logRow['old_discount']], JSON_UNESCAPED_UNICODE),
                    json_encode(['price' => $logRow['new_price'], 'discount' => $logRow['new_discount']], JSON_UNESCAPED_UNICODE),
                    trim('کالا: ' . $logRow['product'] . ' / تنوع: ' . $logRow['variant'] . ' / قیمت قبلی: ' . $logRow['old_price'] . ' / قیمت جدید: ' . $logRow['new_price'] . ' / تخفیف قبلی: ' . $logRow['old_discount'] . ' / تخفیف جدید: ' . $logRow['new_discount'] . ' / کاربر: ' . $user->id . ' / زمان: ' . now()->toDateTimeString() . ($reason ? ' / دلیل: ' . $reason : '') . ($note ? ' / توضیح: ' . $note : '')),
                    $user->id
                );
            }

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
