<?php

namespace App\Services;

use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SalesReturnDocument;
use App\Models\SalesReturnDocumentItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseInboundReceipt;
use App\Models\WarehouseInboundReceiptItem;
use App\Services\Commissions\CommissionReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseInboundService
{
    public const TRANSACTION_TYPE = 'warehouse_inbound_receipt';

    public function __construct(
        private readonly SalesReturnCalculationService $salesReturnCalculator,
        private readonly CommissionReconciliationService $commissions,
    ) {
    }

    public function queueSalesReturn(SalesReturnDocument $document, ?int $actorId, string $operationKey = 'initial'): WarehouseInboundReceipt
    {
        $existing = WarehouseInboundReceipt::query()
            ->where('source_type', WarehouseInboundReceipt::SOURCE_SALES_RETURN)
            ->where('source_id', $document->id)
            ->where('operation_key', $operationKey)
            ->lockForUpdate()
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $document->loadMissing(['items.product', 'items.variant', 'invoice:id,customer_name']);
        $lines = $document->items->map(function (SalesReturnDocumentItem $item) {
            return [
                'source_item_type' => SalesReturnDocumentItem::class,
                'source_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'product_name_snapshot' => $item->product_name_snapshot ?: $item->product?->name,
                'variant_name_snapshot' => $item->variant_name_snapshot ?: $item->variant?->variant_name,
                'sku_snapshot' => $item->sku_snapshot ?: $item->variant?->variant_code,
                'expected_quantity' => (int) $item->return_quantity,
                'suggested_warehouse_id' => (int) $item->destination_warehouse_id,
                'condition' => $item->item_condition,
                'reason' => 'sales_return',
                'source_meta' => [
                    'invoice_item_id' => $item->invoice_item_id,
                    'sold_quantity' => $item->sold_quantity_snapshot,
                    'previously_finalized_returned_quantity' => $item->previously_returned_quantity_snapshot,
                    'historical_unit_price' => $item->unit_price_snapshot,
                    'refund_unit_price' => $item->refund_unit_price,
                    'refund_amount' => $item->refund_amount,
                ],
                'note' => null,
            ];
        })->all();

        return $this->createReceipt(
            WarehouseInboundReceipt::SOURCE_SALES_RETURN,
            (int) $document->id,
            $operationKey,
            (string) $document->document_number,
            $lines,
            $actorId,
            $document->description,
            [
                'customer_id' => $document->customer_id,
                'customer_name' => $document->invoice?->customer_name,
                'invoice_id' => $document->invoice_id,
                'return_reason' => $document->return_reason,
            ]
        );
    }

    public function queueInvoiceCancellation(Invoice $invoice, ?int $actorId, ?string $note = null): WarehouseInboundReceipt
    {
        $existing = WarehouseInboundReceipt::query()
            ->where('source_type', WarehouseInboundReceipt::SOURCE_INVOICE_CANCEL)
            ->where('source_id', $invoice->id)
            ->where('operation_key', 'cancel')
            ->lockForUpdate()
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $invoice->loadMissing(['items.product', 'items.variant']);
        $centralWarehouseId = WarehouseStockService::centralWarehouseId();
        $lines = $invoice->items->filter(fn (InvoiceItem $item) => (int) $item->quantity > 0)->map(function (InvoiceItem $item) use ($centralWarehouseId) {
            return $this->invoiceItemLine($item, (int) $item->quantity, $centralWarehouseId, 'برگشت موجودی بابت لغو فاکتور', 'invoice_cancelled');
        })->values()->all();

        return $this->createReceipt(
            WarehouseInboundReceipt::SOURCE_INVOICE_CANCEL,
            (int) $invoice->id,
            'cancel',
            (string) $invoice->uuid,
            $lines,
            $actorId,
            $note,
            [
                'customer_id' => $invoice->customer_id,
                'customer_name' => $invoice->customer_name,
                'cancellation_reason' => $invoice->cancellation_reason,
            ]
        );
    }

    public function queueInvoiceAdjustment(
        Invoice $invoice,
        array $lines,
        ?int $actorId,
        string $reason,
        ?string $operationKey = null
    ): ?WarehouseInboundReceipt
    {
        $lines = collect($lines)
            ->filter(fn (array $line) => (int) ($line['expected_quantity'] ?? 0) > 0)
            ->values()
            ->all();

        if ($lines === []) {
            return null;
        }

        $operationKey ??= $this->adjustmentOperationKey($invoice, $lines, $reason);
        $existing = WarehouseInboundReceipt::query()
            ->where('source_type', WarehouseInboundReceipt::SOURCE_INVOICE_ADJUSTMENT)
            ->where('source_id', $invoice->id)
            ->where('operation_key', $operationKey)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            return $existing;
        }

        return $this->createReceipt(
            WarehouseInboundReceipt::SOURCE_INVOICE_ADJUSTMENT,
            (int) $invoice->id,
            $operationKey,
            (string) $invoice->uuid,
            $lines,
            $actorId,
            $reason,
            [
                'customer_id' => $invoice->customer_id,
                'customer_name' => $invoice->customer_name,
                'edit_reason' => $reason,
            ]
        );
    }

    /** @deprecated Kept for callers deployed before the source rename. */
    public function queueFinanceAdjustment(Invoice $invoice, array $lines, ?int $actorId, string $reason): ?WarehouseInboundReceipt
    {
        return $this->queueInvoiceAdjustment($invoice, $lines, $actorId, $reason);
    }

    public function invoiceItemLine(
        InvoiceItem $item,
        int $quantity,
        ?int $warehouseId = null,
        ?string $note = null,
        string $reason = 'invoice_correction',
        array $sourceMeta = []
    ): array
    {
        $item->loadMissing(['product', 'variant']);

        return [
            'source_item_type' => InvoiceItem::class,
            'source_item_id' => $item->id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->variant_id,
            'product_name_snapshot' => $item->product?->name,
            'variant_name_snapshot' => $item->variant?->variant_name,
            'sku_snapshot' => $item->variant?->variant_code,
            'expected_quantity' => max(0, $quantity),
            'suggested_warehouse_id' => $warehouseId ?: WarehouseStockService::centralWarehouseId(),
            'condition' => SalesReturnDocumentItem::CONDITION_HEALTHY,
            'reason' => $reason,
            'source_meta' => $sourceMeta + [
                'invoice_id' => $item->invoice_id,
                'invoice_item_id' => $item->id,
                'historical_unit_price' => $item->price,
                'line_discount' => $item->line_discount_amount ?? 0,
                'original_quantity' => $item->quantity,
            ],
            'note' => $note,
        ];
    }

    public function receive(WarehouseInboundReceipt $receipt, array $submittedItems, int $actorId, ?string $reviewNote = null): WarehouseInboundReceipt
    {
        return DB::transaction(function () use ($receipt, $submittedItems, $actorId, $reviewNote) {
            $locked = WarehouseInboundReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'receipt' => 'این رسید قبلاً بررسی شده و امکان ثبت مجدد آن وجود ندارد.',
                ]);
            }

            $items = $locked->items()->with(['product', 'variant', 'suggestedWarehouse'])->lockForUpdate()->get();
            $submitted = collect($submittedItems)->keyBy(fn (array $row) => (int) ($row['id'] ?? 0));

            if ($submitted->count() !== $items->count() || $items->contains(fn ($item) => ! $submitted->has((int) $item->id))) {
                throw ValidationException::withMessages([
                    'items' => 'فهرست اقلام رسید تغییر کرده است. صفحه را بروزرسانی و دوباره بررسی کنید.',
                ]);
            }

            $warehouseIds = $submitted->pluck('received_warehouse_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $warehouses = Warehouse::query()
                ->whereIn('id', $warehouseIds)
                ->where('is_active', true)
                ->whereIn('type', ['central', 'return'])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($warehouses->count() !== $warehouseIds->count()) {
                throw ValidationException::withMessages(['items' => 'یکی از انبارهای مقصد نامعتبر یا غیرفعال است.']);
            }

            $normalized = [];
            $hasDiscrepancy = false;
            $hasUnexplainedDiscrepancy = false;
            foreach ($items as $item) {
                $row = $submitted->get((int) $item->id);
                $accepted = (int) ($row['accepted_quantity'] ?? 0);
                if ($accepted < 0) {
                    throw ValidationException::withMessages([
                        'items' => 'تعداد دریافت‌شده نمی‌تواند منفی باشد.',
                    ]);
                }

                $warehouseId = (int) ($row['received_warehouse_id'] ?? 0);
                $warehouse = $warehouses->get($warehouseId);
                if (! $warehouse) {
                    throw ValidationException::withMessages(['items' => 'انبار مقصد انتخاب‌شده معتبر نیست.']);
                }

                $itemNote = trim((string) ($row['note'] ?? '')) ?: null;
                $itemHasDiscrepancy = $accepted !== (int) $item->expected_quantity
                    || $warehouseId !== (int) $item->suggested_warehouse_id;
                if ($itemHasDiscrepancy) {
                    $hasDiscrepancy = true;
                    $hasUnexplainedDiscrepancy = $hasUnexplainedDiscrepancy || ! $itemNote;
                }

                $normalized[(int) $item->id] = [
                    'accepted_quantity' => $accepted,
                    'received_warehouse_id' => $warehouseId,
                    'condition' => $warehouse->type === 'return'
                        ? SalesReturnDocumentItem::CONDITION_DAMAGED
                        : SalesReturnDocumentItem::CONDITION_HEALTHY,
                    'note' => $itemNote,
                ];
            }

            $reviewNote = trim((string) $reviewNote) ?: null;
            if ($hasDiscrepancy && $hasUnexplainedDiscrepancy && ! $reviewNote) {
                throw ValidationException::withMessages([
                    'review_note' => 'برای ثبت دریافت با مغایرت، توضیح مدیر انبار الزامی است.',
                ]);
            }

            if ($locked->source_type === WarehouseInboundReceipt::SOURCE_SALES_RETURN) {
                $this->assertSalesReturnQuantitiesAreReturnable($locked, $items, $normalized);
                $this->finalizeSalesReturnFromReceipt($locked, $items, $normalized, $actorId);
                $items->each->refresh();
            }

            $itemsForStock = $items->sortBy(fn ($item) => sprintf('%012d:%012d:%012d', (int) $normalized[(int) $item->id]['received_warehouse_id'], (int) $item->product_variant_id, (int) $item->id));
            foreach ($itemsForStock as $item) {
                $row = $normalized[(int) $item->id];
                $accepted = (int) $row['accepted_quantity'];
                $movementId = null;

                if ($accepted > 0) {
                    if ((int) $item->product_id <= 0 || (int) $item->product_variant_id <= 0) {
                        throw ValidationException::withMessages([
                            'items' => 'ارتباط کالا/تنوع یکی از اقلام برای ثبت موجودی معتبر نیست.',
                        ]);
                    }

                    $warehouseId = (int) $row['received_warehouse_id'];
                    $stock = WarehouseStockService::change(
                        $warehouseId,
                        (int) $item->product_id,
                        $accepted,
                        (int) $item->product_variant_id
                    );
                    $after = (int) $stock->quantity;
                    $before = max($after - $accepted, 0);

                    $movement = StockMovement::create([
                        'product_id' => $item->product_id,
                        'product_variant_id' => $item->product_variant_id,
                        'warehouse_id' => $warehouseId,
                        'user_id' => $actorId,
                        'type' => StockMovement::TYPE_IN,
                        'reason' => $this->movementReason($locked->source_type),
                        'transaction_type' => self::TRANSACTION_TYPE,
                        'quantity' => $accepted,
                        'stock_before' => $before,
                        'stock_after' => $after,
                        'note' => $this->movementNote($locked, $item),
                        'reference' => $locked->receipt_number,
                        'reference_type' => WarehouseInboundReceiptItem::class,
                        'reference_id' => (int) $item->id,
                    ]);
                    $movementId = (int) $movement->id;
                }

                $item->update([
                    'accepted_quantity' => $accepted,
                    'received_warehouse_id' => $row['received_warehouse_id'],
                    'condition' => $row['condition'],
                    'note' => $row['note'] ?: $item->note,
                    'stock_movement_id' => $movementId,
                ]);
            }

            $acceptedTotal = (int) collect($normalized)->sum('accepted_quantity');
            $locked->update([
                'accepted_quantity' => $acceptedTotal,
                'status' => $hasDiscrepancy
                    ? WarehouseInboundReceipt::STATUS_DISCREPANCY
                    : WarehouseInboundReceipt::STATUS_RECEIVED,
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
                'review_note' => $reviewNote,
            ]);

            return $locked->fresh(['items.product', 'items.variant', 'items.receivedWarehouse', 'requester', 'reviewer']);
        });
    }

    public function prepareInvoiceCancellationUndo(Invoice $invoice, int $actorId, ?string $note = null): string
    {
        $receipt = WarehouseInboundReceipt::query()
            ->where('source_type', WarehouseInboundReceipt::SOURCE_INVOICE_CANCEL)
            ->where('source_id', $invoice->id)
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if (! $receipt) {
            return 'legacy';
        }

        if ($receipt->isFinalized()) {
            throw ValidationException::withMessages([
                'invoice' => 'امکان لغو کنسلی وجود ندارد؛ کالای این فاکتور قبلاً توسط انبار دریافت شده است. ابتدا اصلاح انباری رسمی انجام شود.',
            ]);
        }

        if ($receipt->isPending()) {
            $receipt->update([
                'status' => WarehouseInboundReceipt::STATUS_CANCELLED,
                'cancelled_by' => $actorId,
                'cancelled_at' => now(),
                'review_note' => trim(($receipt->review_note ? $receipt->review_note."\n" : '').'لغو صف به علت لغو کنسلی فاکتور. '.($note ?: '')),
            ]);
        }

        return 'queued_without_stock';
    }

    private function createReceipt(
        string $sourceType,
        int $sourceId,
        string $operationKey,
        string $sourceNumber,
        array $lines,
        ?int $actorId,
        ?string $note,
        array $sourceMeta = []
    ): WarehouseInboundReceipt {
        $lines = collect($lines)->filter(fn (array $line) => (int) ($line['expected_quantity'] ?? 0) > 0)->values();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'هیچ قلمی برای ورود به صف انبار وجود ندارد.']);
        }

        $receipt = WarehouseInboundReceipt::create([
            'receipt_number' => $this->nextReceiptNumber(),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'operation_key' => $operationKey,
            'source_number_snapshot' => $sourceNumber,
            'customer_name_snapshot' => $sourceMeta['customer_name'] ?? null,
            'source_meta' => $sourceMeta,
            'status' => WarehouseInboundReceipt::STATUS_PENDING,
            'expected_quantity' => (int) $lines->sum('expected_quantity'),
            'accepted_quantity' => 0,
            'requested_by' => $actorId,
            'request_note' => $note,
        ]);

        foreach ($lines as $line) {
            $receipt->items()->create([
                'source_item_type' => $line['source_item_type'] ?? null,
                'source_item_id' => $line['source_item_id'] ?? null,
                'product_id' => $line['product_id'] ?? null,
                'product_variant_id' => $line['product_variant_id'] ?? null,
                'product_name_snapshot' => $line['product_name_snapshot'] ?? null,
                'variant_name_snapshot' => $line['variant_name_snapshot'] ?? null,
                'sku_snapshot' => $line['sku_snapshot'] ?? null,
                'expected_quantity' => (int) $line['expected_quantity'],
                'accepted_quantity' => 0,
                'suggested_warehouse_id' => $line['suggested_warehouse_id'] ?? null,
                'condition' => $line['condition'] ?? null,
                'reason' => $line['reason'] ?? null,
                'source_meta' => $line['source_meta'] ?? null,
                'note' => $line['note'] ?? null,
            ]);
        }

        return $receipt->fresh('items');
    }

    private function finalizeSalesReturnFromReceipt(
        WarehouseInboundReceipt $receipt,
        $receiptItems,
        array $normalized,
        int $actorId
    ): void {
        $document = SalesReturnDocument::query()
            ->whereKey($receipt->source_id)
            ->lockForUpdate()
            ->firstOrFail();

        if (! $document->isPendingWarehouse()) {
            throw ValidationException::withMessages([
                'receipt' => 'وضعیت سند برگشت از فروش با صف انبار سازگار نیست.',
            ]);
        }

        $documentItems = $document->items()->with(['product', 'variant'])->lockForUpdate()->get()->keyBy('id');
        $acceptedRows = [];
        foreach ($receiptItems as $receiptItem) {
            $sourceItem = $documentItems->get((int) $receiptItem->source_item_id);
            if (! $sourceItem) {
                throw ValidationException::withMessages(['items' => 'یکی از اقلام سند برگشت از فروش تغییر کرده است.']);
            }
            $accepted = (int) $normalized[(int) $receiptItem->id]['accepted_quantity'];
            if ($document->isInternal() && $accepted > 0) {
                $acceptedRows[] = [
                    'invoice_item_id' => $sourceItem->invoice_item_id,
                    'return_quantity' => $accepted,
                ];
            }
        }

        $previewByInvoiceItem = collect();
        if ($document->isInternal()) {
            $invoice = Invoice::query()->whereKey($document->invoice_id)->lockForUpdate()->firstOrFail();
            $lockedInvoiceItems = $invoice->items()->with(['product', 'variant'])->orderBy('id')->lockForUpdate()->get();
            $invoice->setRelation('items', $lockedInvoiceItems);
            $previewByInvoiceItem = collect($this->salesReturnCalculator->calculateInternalPreview(
                $invoice,
                $acceptedRows,
                (int) $document->id
            ))->keyBy(fn (array $row) => (int) $row['invoice_item']->id);
        }

        foreach ($receiptItems as $receiptItem) {
            $sourceItem = $documentItems->get((int) $receiptItem->source_item_id);
            $row = $normalized[(int) $receiptItem->id];
            $accepted = (int) $row['accepted_quantity'];
            $updates = [
                'return_quantity' => $accepted,
                'destination_warehouse_id' => (int) $row['received_warehouse_id'],
                'item_condition' => $row['condition'],
            ];

            if ($document->isInternal()) {
                $preview = $accepted > 0 ? $previewByInvoiceItem->get((int) $sourceItem->invoice_item_id) : null;
                $updates += [
                    'previously_returned_quantity_snapshot' => (int) ($preview['previous_quantity'] ?? $sourceItem->previously_returned_quantity_snapshot),
                    'allocated_invoice_discount_snapshot' => (int) ($preview['allocated_discount'] ?? $sourceItem->allocated_invoice_discount_snapshot),
                    'refund_unit_price' => (int) ($preview['refund_unit_price'] ?? 0),
                    'refund_amount' => (int) ($preview['refund_amount'] ?? 0),
                ];
            } else {
                $updates['refund_amount'] = $accepted * (int) $sourceItem->refund_unit_price;
            }

            $sourceItem->update($updates);
        }

        $document->load('items');
        $acceptedItems = $document->items->where('return_quantity', '>', 0);
        $totalQuantity = (int) $acceptedItems->sum('return_quantity');
        $totalAmount = (int) $acceptedItems->sum('refund_amount');

        $document->update([
            'items_count' => $acceptedItems->count(),
            'total_quantity' => $totalQuantity,
            'total_refund_amount' => $totalAmount,
            'updated_by' => $actorId,
        ]);

        if ($totalQuantity <= 0) {
            $document->update([
                'status' => SalesReturnDocument::STATUS_CANCELLED,
                'cancelled_by' => $actorId,
                'cancelled_at' => now(),
                'cancel_reason' => 'هیچ کالایی توسط انبار دریافت نشد.',
            ]);
            return;
        }

        if ($totalAmount > 0) {
            $ledgerReferenceType = str_starts_with((string) $receipt->operation_key, 'revision-')
                ? self::TRANSACTION_TYPE
                : SalesReturnDocument::class;
            $ledgerReferenceId = $ledgerReferenceType === self::TRANSACTION_TYPE
                ? (int) $receipt->id
                : (int) $document->id;
            CustomerLedger::updateOrCreate(
                [
                    'customer_id' => $document->customer_id,
                    'reference_type' => $ledgerReferenceType,
                    'reference_id' => $ledgerReferenceId,
                    'type' => 'credit',
                ],
                [
                    'amount' => $totalAmount,
                    'note' => 'بستانکاری بابت برگشت از فروش شماره '.$document->document_number,
                ]
            );
        }

        $document->update([
            'status' => SalesReturnDocument::STATUS_APPLIED,
            'applied_by' => $actorId,
            'applied_at' => now(),
        ]);

        $this->commissions->reconcileReturn($document->fresh('items'), $actorId);
    }

    private function movementReason(string $sourceType): string
    {
        return match ($sourceType) {
            WarehouseInboundReceipt::SOURCE_SALES_RETURN => 'sales_return',
            WarehouseInboundReceipt::SOURCE_INVOICE_CANCEL => 'invoice_cancel_return',
            WarehouseInboundReceipt::SOURCE_INVOICE_ADJUSTMENT,
            WarehouseInboundReceipt::SOURCE_FINANCE_ADJUSTMENT_LEGACY => 'invoice_adjustment_return',
            default => StockMovement::REASON_RETURN,
        };
    }

    private function movementNote(WarehouseInboundReceipt $receipt, WarehouseInboundReceiptItem $item): string
    {
        $source = WarehouseInboundReceipt::sourceLabels()[$receipt->source_type] ?? $receipt->source_type;
        return $source.' '.$receipt->source_number_snapshot.' | رسید '.$receipt->receipt_number;
    }

    private function nextReceiptNumber(): string
    {
        $now = now();
        DB::table('document_sequences')->insertOrIgnore([
            'type' => 'warehouse_inbound',
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sequence = DB::table('document_sequences')->where('type', 'warehouse_inbound')->lockForUpdate()->first();
        if (! $sequence) {
            throw new \RuntimeException('Warehouse inbound sequence could not be initialized.');
        }

        $next = (int) $sequence->last_number + 1;
        DB::table('document_sequences')->where('type', 'warehouse_inbound')->update([
            'last_number' => $next,
            'updated_at' => $now,
        ]);

        return 'WI-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function adjustmentOperationKey(Invoice $invoice, array $lines, string $reason): string
    {
        $canonical = collect($lines)->map(fn (array $line) => [
            'source_item_id' => (int) ($line['source_item_id'] ?? 0),
            'product_id' => (int) ($line['product_id'] ?? 0),
            'variant_id' => (int) ($line['product_variant_id'] ?? 0),
            'quantity' => (int) ($line['expected_quantity'] ?? 0),
            'reason' => (string) ($line['reason'] ?? $reason),
        ])->sortBy(fn (array $line) => implode(':', $line))->values()->all();
        $version = optional($invoice->items_updated_at ?: $invoice->updated_at)->format('Y-m-d H:i:s.u') ?: 'initial';

        return 'adjustment-'.substr(hash('sha256', json_encode([$invoice->id, $version, $reason, $canonical], JSON_UNESCAPED_UNICODE)), 0, 48);
    }

    private function assertSalesReturnQuantitiesAreReturnable(
        WarehouseInboundReceipt $receipt,
        $receiptItems,
        array $normalized
    ): void {
        $document = SalesReturnDocument::query()->whereKey($receipt->source_id)->lockForUpdate()->firstOrFail();
        $documentItems = $document->items()->lockForUpdate()->get()->keyBy('id');

        if ($document->isInternal()) {
            $invoice = Invoice::query()->whereKey($document->invoice_id)->lockForUpdate()->firstOrFail();
            $invoiceItems = $invoice->items()->lockForUpdate()->get()->keyBy('id');
            $previous = $this->salesReturnCalculator->previouslyReturnedQuantities(
                $invoiceItems->keys()->all(),
                (int) $document->id
            );

            foreach ($receiptItems as $receiptItem) {
                $sourceItem = $documentItems->get((int) $receiptItem->source_item_id);
                $invoiceItem = $sourceItem ? $invoiceItems->get((int) $sourceItem->invoice_item_id) : null;
                if (! $sourceItem || ! $invoiceItem) {
                    throw ValidationException::withMessages(['items' => 'ارتباط یکی از اقلام برگشت با فاکتور مبدا معتبر نیست.']);
                }
                $returnable = max((int) $invoiceItem->quantity - (int) ($previous[$invoiceItem->id] ?? 0), 0);
                $accepted = (int) $normalized[(int) $receiptItem->id]['accepted_quantity'];
                if ($accepted > $returnable) {
                    throw ValidationException::withMessages([
                        'items' => "تعداد تأییدشده برای «{$receiptItem->product_name_snapshot}» بیشتر از مقدار واقعی قابل برگشت مشتری است. حداکثر مجاز: {$returnable}",
                    ]);
                }
            }

            return;
        }

        foreach ($receiptItems as $receiptItem) {
            $sourceItem = $documentItems->get((int) $receiptItem->source_item_id);
            $sold = (int) ($sourceItem?->sold_quantity_snapshot ?? 0);
            $accepted = (int) $normalized[(int) $receiptItem->id]['accepted_quantity'];
            if ($sold > 0 && $accepted > $sold) {
                throw ValidationException::withMessages([
                    'items' => "تعداد تأییدشده برای «{$receiptItem->product_name_snapshot}» از تعداد فروخته‌شده بیشتر است. حداکثر مجاز: {$sold}",
                ]);
            }
        }
    }
}
