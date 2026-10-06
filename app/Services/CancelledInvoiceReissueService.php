<?php

namespace App\Services;

use App\Models\CancelledInvoiceReissue;
use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use App\Models\WarehouseInboundReceipt;
use App\Support\ActivityLogger;
use App\Support\DocumentCodeGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class CancelledInvoiceReissueService
{
    /**
     * Purely read-only validation and preview. No stock, financial or document rows are changed.
     *
     * @return array{invoice: Invoice, old_order: PreinvoiceOrder, receipt: WarehouseInboundReceipt, seller: User, quantity: int}
     */
    public function inspect(string $invoiceNumber, int $sellerId, int $expectedInvoiceId, int $expectedReceiptId): array
    {
        if (! Schema::hasTable('cancelled_invoice_reissues')) {
            throw new RuntimeException('Migration مربوط به بازصدور فاکتور هنوز اجرا نشده است.');
        }

        $invoice = Invoice::query()
            ->with(['items', 'preinvoiceOrder'])
            ->where('uuid', $invoiceNumber)
            ->first();

        if (! $invoice || (int) $invoice->id !== $expectedInvoiceId) {
            throw new RuntimeException('شماره فاکتور و شناسه مورد انتظار با هم تطابق ندارند.');
        }

        return $this->validate($invoice, $sellerId, $expectedReceiptId);
    }

    public function createDraft(
        string $invoiceNumber,
        int $sellerId,
        int $expectedInvoiceId,
        int $expectedReceiptId,
        ?string $note = null
    ): PreinvoiceOrder {
        return DB::transaction(function () use ($invoiceNumber, $sellerId, $expectedInvoiceId, $expectedReceiptId, $note): PreinvoiceOrder {
            $invoice = Invoice::query()
                ->where('uuid', $invoiceNumber)
                ->lockForUpdate()
                ->first();

            if (! $invoice || (int) $invoice->id !== $expectedInvoiceId) {
                throw new RuntimeException('فاکتور مورد انتظار یافت نشد؛ هیچ تغییری انجام نشد.');
            }

            $invoice->load('preinvoiceOrder');
            $invoice->setRelation('items', $invoice->items()->lockForUpdate()->get());

            $context = $this->validate($invoice, $sellerId, $expectedReceiptId);
            $oldOrder = $context['old_order'];

            $draft = PreinvoiceOrder::query()->create([
                'uuid' => DocumentCodeGenerator::generateUnique5DigitCode(PreinvoiceOrder::class),
                'external_order_id' => null,
                'created_by' => $sellerId,
                'seller_id' => $sellerId,
                'document_date' => now(),
                'status' => PreinvoiceOrder::STATUS_DRAFT,
                'customer_id' => $invoice->customer_id ?? $oldOrder->customer_id,
                'is_in_person' => (bool) $oldOrder->is_in_person,
                'customer_name' => (string) ($invoice->customer_name ?: $oldOrder->customer_name),
                'customer_mobile' => (string) ($invoice->customer_mobile ?: $oldOrder->customer_mobile),
                'customer_address' => $invoice->customer_address ?? $oldOrder->customer_address,
                'description' => $oldOrder->description,
                'payment_terms_note' => $oldOrder->payment_terms_note,
                'province_id' => $invoice->province_id ?? $oldOrder->province_id,
                'city_id' => $invoice->city_id ?? $oldOrder->city_id,
                'shipping_id' => $invoice->shipping_id ?? $oldOrder->shipping_id,
                'shipping_price' => (int) $invoice->shipping_price,
                'discount_amount' => (int) $invoice->discount_amount,
                'discount_breakdown' => $invoice->discount_breakdown,
                'invoice_discount_type' => $invoice->invoice_discount_type,
                'invoice_discount_value' => (int) $invoice->invoice_discount_value,
                'invoice_discount_amount' => (int) $invoice->invoice_discount_amount,
                'product_discount_amount' => (int) $invoice->product_discount_amount,
                'discount_allocation_mode' => $invoice->discount_allocation_mode,
                'total_price' => (int) $invoice->total,
                'stock_frozen_until' => null,
                'stock_released_at' => null,
                'is_auto_draft' => false,
                'auto_saved_at' => null,
                'draft_token' => null,
            ]);

            foreach ($invoice->items as $item) {
                $draft->items()->create([
                    'product_id' => (int) $item->product_id,
                    'variant_id' => $item->variant_id,
                    'quantity' => (int) $item->quantity,
                    'price' => (int) $item->price,
                    'line_total' => (int) $item->line_total,
                    'sort_order' => (int) $item->sort_order,
                    'line_discount_amount' => (int) $item->line_discount_amount,
                ]);
            }

            CancelledInvoiceReissue::query()->create([
                'original_invoice_id' => $invoice->id,
                'replacement_preinvoice_order_id' => $draft->id,
                'note' => $note ?: 'ایجاد پیش‌نویس جدید از فاکتور لغوشده پس از دریافت کالا توسط انبار',
            ]);

            ActivityLogger::logForActor(null, 'cancelled_invoice_reissued_as_draft', $invoice,
                "فاکتور لغوشده {$invoice->uuid} با حفظ سوابق به پیش‌نویس جدید {$draft->uuid} ارجاع شد.",
                ['replacement_preinvoice_order_id' => $draft->id, 'replacement_uuid' => $draft->uuid, 'seller_id' => $sellerId]
            );
            ActivityLogger::logForActor(null, 'draft_created_from_cancelled_invoice', $draft,
                "پیش‌نویس {$draft->uuid} از فاکتور لغوشده {$invoice->uuid} ایجاد شد؛ هیچ موجودی رزرو نشد.",
                ['original_invoice_id' => $invoice->id, 'original_invoice_uuid' => $invoice->uuid, 'seller_id' => $sellerId]
            );

            return $draft;
        }, 3);
    }

    /**
     * @return array{invoice: Invoice, old_order: PreinvoiceOrder, receipt: WarehouseInboundReceipt, seller: User, quantity: int}
     */
    private function validate(Invoice $invoice, int $sellerId, int $expectedReceiptId): array
    {
        if (! Schema::hasTable('cancelled_invoice_reissues')) {
            throw new RuntimeException('جدول ثبت بازصدور وجود ندارد؛ ابتدا migration اجرا شود.');
        }

        if (! $invoice->isCancelled() || ! $invoice->cancelled_at) {
            throw new RuntimeException('فاکتور باید لغوشده و دارای تاریخ کنسلی باشد.');
        }

        if (CancelledInvoiceReissue::query()->where('original_invoice_id', $invoice->id)->exists()) {
            throw new RuntimeException('برای این فاکتور قبلاً پیش‌نویس جایگزین ایجاد شده است؛ بازصدور تکراری ممنوع است.');
        }

        $oldOrder = $invoice->preinvoiceOrder;
        if (! $oldOrder
            || (string) $oldOrder->status !== PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE
            || (int) $oldOrder->created_by !== $sellerId
            || (int) $oldOrder->seller_id !== $sellerId
            || (int) $invoice->seller_id !== $sellerId
        ) {
            throw new RuntimeException('مالکیت یا وضعیت پیش‌فاکتور قدیمی با فروشنده انتخابی مطابقت ندارد.');
        }

        $seller = User::query()->find($sellerId);
        if (! $seller || ! $seller->is_active || ! $seller->can_access_erp) {
            throw new RuntimeException('حساب فروشنده وجود ندارد یا برای ورود به ERP فعال نیست.');
        }

        if ($invoice->payments()->exists()) {
            throw new RuntimeException('فاکتور دارای پرداخت است؛ بازصدور خودکار مجاز نیست.');
        }

        $quantity = (int) $invoice->items->sum('quantity');
        if ($quantity <= 0 || $invoice->items->contains(fn ($item) => (int) $item->quantity <= 0)) {
            throw new RuntimeException('اقلام فاکتور برای کپی در پیش‌نویس معتبر نیستند.');
        }

        $receipt = WarehouseInboundReceipt::query()
            ->where('source_type', WarehouseInboundReceipt::SOURCE_INVOICE_CANCEL)
            ->where('source_id', $invoice->id)
            ->where('operation_key', 'cancel')
            ->latest('id')
            ->first();

        if (! $receipt || (int) $receipt->id !== $expectedReceiptId
            || (string) $receipt->status !== WarehouseInboundReceipt::STATUS_RECEIVED
            || (int) $receipt->expected_quantity !== $quantity
            || (int) $receipt->accepted_quantity !== $quantity
        ) {
            throw new RuntimeException('دریافت انبار باید نهایی، بدون مغایرت و برابر تعداد اقلام فاکتور باشد.');
        }

        return compact('invoice', 'oldOrder', 'receipt', 'seller', 'quantity') + ['old_order' => $oldOrder];
    }
}
