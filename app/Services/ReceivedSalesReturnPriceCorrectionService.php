<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\SalesReturnCorrection;
use App\Models\SalesReturnDocument;
use App\Models\SalesReturnDocumentItem;
use App\Models\SalesReturnDocumentRevision;
use App\Models\User;
use App\Models\WarehouseInboundReceipt;
use App\Models\WarehouseInboundReceiptItem;
use App\Support\InboundReviewVersion;
use App\Support\PageAccessCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Only the monetary price of a received central-warehouse return item can change.
 * Never call WarehouseStockService or modify receipt/stock/quantity fields here.
 */
final class ReceivedSalesReturnPriceCorrectionService
{
    public const PERMISSION = 'sales_returns.correct_received_price';

    /** Price-only corrections use the same access as editing applied returns. */
    public static function allowed(?User $user): bool
    {
        return $user !== null
            && PageAccessCatalog::userCanRoute($user, 'vouchers.return-from-sale.applied.update');
    }

    /** @return array<int, int> IDs of items physically accepted in central warehouse. */
    public function eligibleItemIds(SalesReturnDocument $document): array
    {
        if (! $document->isApplied() || ! $document->isSazehHesab()) {
            // Internal invoice returns derive their refund from historical invoice discounts.
            return [];
        }

        return WarehouseInboundReceiptItem::query()
            ->join('warehouse_inbound_receipts as receipts', 'receipts.id', '=', 'warehouse_inbound_receipt_items.receipt_id')
            ->join('warehouses as received_warehouse', 'received_warehouse.id', '=', 'warehouse_inbound_receipt_items.received_warehouse_id')
            ->where('receipts.source_type', WarehouseInboundReceipt::SOURCE_SALES_RETURN)
            ->where('receipts.source_id', $document->id)
            ->whereIn('receipts.status', [
                WarehouseInboundReceipt::STATUS_RECEIVED,
                WarehouseInboundReceipt::STATUS_DISCREPANCY,
            ])
            ->where('warehouse_inbound_receipt_items.source_item_type', SalesReturnDocumentItem::class)
            ->where('warehouse_inbound_receipt_items.accepted_quantity', '>', 0)
            ->where('received_warehouse.type', 'central')
            ->distinct()
            ->pluck('warehouse_inbound_receipt_items.source_item_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function correct(
        SalesReturnDocument $document,
        int $itemId,
        int $unitPrice,
        string $expectedVersion,
        string $reason,
        int $actorId
    ): SalesReturnDocument {
        return DB::transaction(function () use ($document, $itemId, $unitPrice, $expectedVersion, $reason, $actorId) {
            $document = SalesReturnDocument::query()->lockForUpdate()->findOrFail($document->id);
            $document->load('items');

            if (! $document->isApplied() || ! $document->isSazehHesab()) {
                $this->fail('document', 'اصلاح قیمت فقط برای سند برگشت سازه‌حساب ثبت‌نهایی‌شده مجاز است.');
            }

            if (! hash_equals(InboundReviewVersion::token($document), $expectedVersion)) {
                $this->fail('document_version', 'سند پس از بازشدن صفحه تغییر کرده است؛ آن را دوباره بارگذاری کنید.');
            }

            if (SalesReturnCorrection::query()->where('document_id', $document->id)
                ->whereIn('status', ['pending_warehouse', 'pending_finance'])->exists()) {
                $this->fail('document', 'این سند درخواست اصلاح در انتظار بررسی دارد.');
            }

            if (! in_array($itemId, $this->eligibleItemIds($document), true)) {
                $this->fail('item_id', 'این قلم دریافت تأییدشده در انبار مرکزی ندارد.');
            }

            $item = SalesReturnDocumentItem::query()
                ->where('document_id', $document->id)->whereKey($itemId)->lockForUpdate()->first();
            if (! $item || (int) $item->return_quantity <= 0) {
                $this->fail('item_id', 'قلم انتخاب‌شده در این سند معتبر نیست.');
            }

            if ($unitPrice < 1 || $unitPrice > 1_000_000_000_000) {
                $this->fail('refund_unit_price', 'مبلغ واحد معتبر نیست.');
            }
            $reason = trim($reason);
            if (mb_strlen($reason) < 3 || mb_strlen($reason) > 1000) {
                $this->fail('reason', 'دلیل اصلاح باید بین ۳ تا ۱۰۰۰ نویسه باشد.');
            }

            Customer::query()->whereKey($document->customer_id)->lockForUpdate()->firstOrFail();
            $currentLedger = app(SalesReturnQueryService::class)->appliedHealthCheck($document)['ledger_amount'];
            if ((int) $currentLedger !== (int) $document->total_refund_amount
                || (int) $document->items->sum('refund_amount') !== (int) $document->total_refund_amount) {
                $this->fail('document', 'مبلغ سند با سوابق مالی آن همخوانی ندارد؛ ابتدا مغایرت بررسی شود.');
            }

            $quantity = (int) $item->return_quantity;
            $oldPrice = (int) $item->refund_unit_price;
            $oldLine = (int) $item->refund_amount;
            if ($oldLine !== $quantity * $oldPrice) {
                $this->fail('document', 'مبلغ ردیف با تعداد و قیمت قبلی سازگار نیست.');
            }
            if ($oldPrice === $unitPrice) {
                $this->fail('refund_unit_price', 'قیمت جدید باید با قیمت فعلی متفاوت باشد.');
            }
            if ($quantity > intdiv(PHP_INT_MAX, $unitPrice)) {
                $this->fail('refund_unit_price', 'مبلغ محاسبه‌شده از محدوده مجاز بیشتر است.');
            }

            $newLine = $quantity * $unitPrice;
            $delta = $newLine - $oldLine;
            $previousTotal = (int) $document->total_refund_amount;
            $newTotal = $previousTotal + $delta;
            if ($newTotal < 0 || $newTotal > 9_000_000_000_000_000) {
                $this->fail('refund_unit_price', 'مبلغ کل سند از محدوده مجاز خارج می‌شود.');
            }

            $before = $this->snapshot($document);
            // New commercial amounts are prepared without changing any warehouse data.
            $proposed = $before;
            $proposed['document']['total_refund_amount'] = $newTotal;
            $proposed['total_refund_amount'] = $newTotal;
            foreach ($proposed['items'] as &$row) {
                if ((int) $row['id'] === $itemId) {
                    $row['refund_unit_price'] = $unitPrice;
                    $row['refund_amount'] = $newLine;
                }
            }
            unset($row);

            $correction = SalesReturnCorrection::query()->create([
                'document_id' => $document->id,
                'base_version' => $expectedVersion,
                'payload' => [
                    'type' => 'received_central_price_only',
                    'item_id' => $itemId,
                    'old_unit_price' => $oldPrice,
                    'new_unit_price' => $unitPrice,
                    'quantity_unchanged' => $quantity,
                ],
                'before_snapshot' => $before,
                'proposed_snapshot' => $proposed,
                'reason' => $reason,
                'requested_by' => $actorId,
                'status' => 'applied',
                'finance_approved_by' => $actorId,
                'finance_approved_at' => now(),
            ]);

            $item->update(['refund_unit_price' => $unitPrice, 'refund_amount' => $newLine]);
            $document->update(['total_refund_amount' => $newTotal, 'updated_by' => $actorId]);
            CustomerLedger::query()->create([
                'customer_id' => $document->customer_id,
                'type' => $delta > 0 ? 'credit' : 'debit',
                'amount' => abs($delta),
                'reference_type' => SalesReturnCorrection::class,
                'reference_id' => $correction->id,
                'note' => 'اصلاح صرفاً قیمت برگشت '.$document->document_number.'؛ '.$reason,
            ]);

            $after = $this->snapshot($document->fresh('items'));
            $correction->update(['after_snapshot' => $after]);
            SalesReturnDocumentRevision::query()->create([
                'document_id' => $document->id,
                'action' => 'received_price_corrected',
                'token' => (string) Str::uuid(),
                'reason' => $reason,
                'before_snapshot' => $before,
                'after_snapshot' => $after,
                'previous_total' => $previousTotal,
                'new_total' => $newTotal,
                'created_by' => $actorId,
                'created_at' => now(),
            ]);
            ActivityLog::query()->create([
                'user_id' => $actorId,
                'action' => 'sales_return.received_price_corrected',
                'subject_type' => SalesReturnCorrection::class,
                'subject_id' => $correction->id,
                'description' => 'اصلاح قیمت مالی برگشت از فروش: '.$reason,
                'properties' => [
                    'document_id' => $document->id,
                    'item_id' => $itemId,
                    'old_unit_price' => $oldPrice,
                    'new_unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'ledger_delta' => $delta,
                    'stock_change' => 0,
                ],
                'occurred_at' => now(),
            ]);

            return $document->fresh('items');
        }, 3);
    }

    private function snapshot(SalesReturnDocument $document): array
    {
        return [
            'document' => $document->getAttributes(),
            'items' => $document->items->map(fn ($item) => $item->getAttributes())->values()->all(),
            'total_quantity' => (int) $document->total_quantity,
            'total_refund_amount' => (int) $document->total_refund_amount,
        ];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
