<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Support\SalesDocumentTotals;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceSalesCorrectionService
{
    public function __construct(
        private readonly SalesDocumentAccessService $access,
        private readonly WarehouseCollectionService $collection,
        private readonly InvoiceReapprovalRevisionRecorder $revisions,
        private readonly SalesHavalehHistoryService $history,
        private readonly CustomerLedgerService $ledger,
    ) {}

    public function canEdit(Invoice $invoice, ?User $user): bool
    {
        return $user !== null
            && (string) $invoice->status === Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION
            && ((int) $invoice->effective_seller_id === (int) $user->id || $this->access->isManager($user));
    }

    public function fingerprint(Invoice $invoice): string
    {
        $invoice->loadMissing('items');

        return hash('sha256', json_encode([
            $invoice->id, $invoice->status, (string) $invoice->updated_at,
            $invoice->customer_id, $invoice->customer_name, $invoice->customer_address,
            $invoice->shipping_id, $invoice->shipping_method_id, $invoice->shipping_price,
            $invoice->invoice_discount_type, $invoice->invoice_discount_value,
            $invoice->invoice_discount_amount, $invoice->total,
            $invoice->items->sortBy('id')->values()->map(fn (InvoiceItem $item) => [
                $item->id, $item->product_id, $item->variant_id, $item->quantity,
                $item->price, $item->line_discount_amount,
            ])->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function submit(Invoice $invoice, User $user, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $user, $data) {
            $invoice = Invoice::query()->with(['items.product', 'items.variant', 'payments', 'preinvoiceOrder'])
                ->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canEdit($invoice, $user), 403);
            if (! hash_equals($this->fingerprint($invoice), (string) $data['opened_fingerprint'])) {
                throw ValidationException::withMessages(['invoice' => 'این فاکتور بعد از باز شدن صفحه تغییر کرده است. صفحه را تازه‌سازی و دوباره بررسی کنید.']);
            }

            // Seller corrections only change items and discounts. Customer, sale mode, shipping,
            // address and payment terms always keep their current values (shipping is handled in
            // the dispatch queue), whatever the request contains.
            $oldTotal = (int) $invoice->total;
            $before = $invoice->items->map(fn (InvoiceItem $item) => clone $item);
            $revisionTableExists = DB::getSchemaBuilder()->hasTable('invoice_collection_revisions');
            $previousRevisionId = $revisionTableExists
                ? (int) DB::table('invoice_collection_revisions')->where('invoice_id', $invoice->id)->max('id')
                : 0;
            $oldHeader = [
                'discount_type' => (string) ($invoice->invoice_discount_type ?: 'amount'),
                'discount_value' => (string) (int) ($invoice->invoice_discount_value ?? $invoice->invoice_discount_amount ?? 0),
            ];
            $note = trim((string) $data['change_note']);
            $items = array_values($data['items']);
            $activeItems = collect($items)->filter(fn (array $row) => (int) $row['quantity'] > 0);
            if ($activeItems->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'فاکتور باید حداقل یک قلم کالا داشته باشد.']);
            }
            $base = $activeItems->sum(fn (array $row) => (int) $row['quantity'] * (int) $row['price'] - (int) $row['line_discount_amount']);
            $discountValue = (int) $data['invoice_discount_value'];
            $discountAmount = $data['invoice_discount_type'] === 'percent'
                ? intdiv($base * min($discountValue, 100), 100)
                : min($discountValue, $base);

            $invoice->update([
                'invoice_discount_type' => $data['invoice_discount_type'],
                'invoice_discount_value' => $discountValue,
                'invoice_discount_amount' => $discountAmount,
                'discount_allocation_mode' => 'product_lines',
            ]);

            $invoice = $this->collection->updateInvoiceItemsInPlace(
                $invoice, $items, $user, true, 'seller_finance_correction', $note, true
            );
            $invoice->refresh()->load(['items.product', 'items.variant', 'preinvoiceOrder']);
            if (SalesDocumentTotals::integrityIssues($invoice) !== []) {
                throw ValidationException::withMessages(['invoice' => 'مبلغ فاکتور با اقلام و تخفیف‌ها همخوانی ندارد. هیچ تغییری ثبت نشد.']);
            }

            $newHeader = [
                'discount_type' => (string) $invoice->invoice_discount_type,
                'discount_value' => (string) (int) $invoice->invoice_discount_value,
            ];
            $labels = [
                'discount_type' => 'نوع تخفیف کلی', 'discount_value' => 'مقدار تخفیف کلی',
            ];
            foreach ($oldHeader as $field => $oldValue) {
                if ($oldValue === $newHeader[$field]) {
                    continue;
                }
                $this->history->log(
                    $invoice, 'seller_invoice_header_changed', $field,
                    $oldValue, $newHeader[$field], $labels[$field], $user->id,
                );
            }
            $collectionRecordedRevision = $revisionTableExists
                && (int) DB::table('invoice_collection_revisions')->where('invoice_id', $invoice->id)->max('id') > $previousRevisionId;
            if (! $collectionRecordedRevision && ($oldHeader !== $newHeader || $oldTotal !== (int) $invoice->total)) {
                $this->revisions->record(
                    $invoice, $before, $invoice->items, $oldTotal, (int) $invoice->total,
                    'seller_finance_correction', $note, (int) $user->id,
                );
            }
            $invoice->update([
                'status' => Invoice::STATUS_PENDING_FINANCE_REAPPROVAL,
                'status_changed_at' => now(),
                'status_changed_by' => $user->id,
            ]);
            $this->history->log(
                $invoice, 'seller_correction_submitted', 'status',
                Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION,
                Invoice::STATUS_PENDING_FINANCE_REAPPROVAL,
                $note !== '' ? $note : 'فروشنده فاکتور را بررسی و برای تأیید مجدد مالی ارسال کرد.',
                $user->id,
            );

            return $invoice->fresh();
        });
    }
}
