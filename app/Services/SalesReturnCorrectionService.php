<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\ProductVariant;
use App\Models\SalesReturnCorrection;
use App\Models\SalesReturnDocument;
use App\Models\SalesReturnDocumentRevision;
use App\Models\StockMovement;
use App\Models\WarehouseStock;
use App\Services\Commissions\CommissionReturnSyncOutboxService;
use App\Support\InboundReviewVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesReturnCorrectionService
{
    public function __construct(private SalesReturnService $returns, private CommissionReturnSyncOutboxService $outbox) {}

    public function request(SalesReturnDocument $document, array $data, int $actor, string $reason): SalesReturnCorrection
    {
        return DB::transaction(function () use ($document, $data, $actor, $reason) {
            $doc = $this->lockDocument($document->id);
            $this->assertVersion($doc, (string) ($data['document_version'] ?? ''));
            if (SalesReturnCorrection::where('document_id', $doc->id)->whereIn('status', ['pending_warehouse', 'pending_finance'])->exists()) {
                $this->fail('correction', 'این سند یک درخواست اصلاح در انتظار بررسی دارد. ابتدا همان درخواست تعیین تکلیف شود.');
            }
            $this->assertReason($reason);
            $proposal = $this->prepare($doc, $data);
            $before = $this->snapshot($doc);
            if ($proposal['items'] === [] || $this->comparable($before) === $this->comparable($proposal)) {
                $this->fail('correction', 'درخواست باید حداقل یک قلم و یک تغییر واقعی داشته باشد.');
            }
            $request = SalesReturnCorrection::create(['document_id' => $doc->id, 'base_version' => InboundReviewVersion::token($doc), 'payload' => $data, 'before_snapshot' => $before, 'proposed_snapshot' => $proposal, 'reason' => trim($reason), 'requested_by' => $actor, 'status' => 'pending_warehouse']);
            $this->audit($request, $actor, 'requested', $reason);

            return $request;
        });
    }

    public function approve(SalesReturnCorrection $request, string $stage, int $actor, string $note): SalesReturnCorrection
    {
        return DB::transaction(function () use ($request, $stage, $actor, $note) {
            // Same lock order as inbound receiving and pending editing: document, customer, invoice.
            $doc = $this->lockDocument($request->document_id);
            $correction = SalesReturnCorrection::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($correction->status === 'applied') {
                return $correction;
            }
            if (! in_array($stage, ['warehouse', 'finance'], true) || (int) $correction->requested_by === $actor || ($stage === 'finance' && (int) $correction->warehouse_approved_by === $actor)) {
                $this->fail('correction', 'ثبت‌کننده درخواست نمی‌تواند آن را تأیید کند؛ تأیید انبار و مالی نیز باید توسط دو کاربر مستقل باشد.');
            }
            $expected = $stage === 'warehouse' ? 'pending_warehouse' : 'pending_finance';
            if ($correction->status !== $expected) {
                $this->fail('correction', 'مرحله درخواست تغییر کرده است؛ ابتدا تأیید انبار و سپس تأیید مالی لازم است.');
            }
            $this->assertReason($note);
            $this->assertVersion($doc, $correction->base_version);
            $proposal = $this->prepare($doc, $correction->payload);
            if ($this->comparable($proposal) !== $this->comparable($correction->proposed_snapshot)) {
                $this->fail('correction', 'سقف برگشت یا محاسبات مرجع تغییر کرده است؛ این درخواست را رد و درخواست تازه ثبت کنید.');
            }
            if ($stage === 'warehouse') {
                $correction->update(['status' => 'pending_finance', 'warehouse_approved_by' => $actor, 'warehouse_approved_at' => now(), 'warehouse_note' => trim($note)]);
            } else {
                $this->applyDelta($doc, $correction, $proposal, $actor);
                $correction->update(['status' => 'applied', 'finance_approved_by' => $actor, 'finance_approved_at' => now(), 'finance_note' => trim($note), 'after_snapshot' => $this->snapshot($doc->fresh('items'))]);
                $this->outbox->stage((int) $doc->id, $actor);
            }
            $this->audit($correction, $actor, $stage.'_approved', $note);

            return $correction->fresh();
        });
    }

    public function reject(SalesReturnCorrection $request, int $actor, string $reason, ?string $expectedStatus = null): SalesReturnCorrection
    {
        return DB::transaction(function () use ($request, $actor, $reason, $expectedStatus) {
            SalesReturnDocument::whereKey($request->document_id)->lockForUpdate()->firstOrFail();
            $correction = SalesReturnCorrection::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($expectedStatus !== null && $expectedStatus !== $correction->status) {
                $this->fail('correction', 'مرحله درخواست هم‌زمان تغییر کرده است؛ صفحه را تازه کنید و دوباره بررسی کنید.');
            }
            if (! in_array($correction->status, ['pending_warehouse', 'pending_finance'], true)) {
                $this->fail('correction', 'این درخواست قبلاً تعیین تکلیف شده است.');
            }
            $this->assertReason($reason);
            $correction->update(['status' => 'rejected', 'rejected_by' => $actor, 'rejected_at' => now(), 'rejection_reason' => trim($reason)]);
            $this->audit($correction, $actor, 'rejected', $reason);

            return $correction;
        });
    }

    private function lockDocument(int $id): SalesReturnDocument
    {
        $doc = SalesReturnDocument::whereKey($id)->lockForUpdate()->firstOrFail();
        if (! $doc->isApplied()) {
            $this->fail('document', 'درخواست اصلاح فقط برای سند دریافت‌شده است؛ سند در انتظار انبار از مسیر ویرایش معمول اصلاح می‌شود.');
        }
        Customer::whereKey($doc->customer_id)->lockForUpdate()->firstOrFail();
        if ($doc->isInternal()) {
            Invoice::whereKey($doc->invoice_id)->lockForUpdate()->firstOrFail();
        }

        return $doc->load('items');
    }

    private function prepare(SalesReturnDocument $doc, array $data): array
    {
        $data = array_replace($data, $doc->only(['source_type', 'customer_id', 'invoice_id', 'external_invoice_number', 'external_invoice_date']));
        unset($data['override_invoice_status']);
        $seen = [];
        $rows = $data['items'] ?? [];
        foreach ($rows as $index => &$row) {
            if (filter_var($row['return_quantity'] ?? null, FILTER_VALIDATE_INT) === false || (int) $row['return_quantity'] < 1) {
                $this->fail('items', 'تعداد باید صحیح و مثبت باشد.');
            }
            if ((int) $row['return_quantity'] > 1000000 || ! in_array($row['item_condition'] ?? '', ['healthy', 'damaged'], true) || (isset($row['refund_unit_price']) && (filter_var($row['refund_unit_price'], FILTER_VALIDATE_INT) === false || (int) $row['refund_unit_price'] < 0 || (int) $row['refund_unit_price'] > 1000000000000))) {
                $this->fail('items', 'تعداد، وضعیت کالا یا مبلغ اصلاح نامعتبر است.');
            }
            // Materialized legacy/new-product rows can be corrected using their existing variant, never create a new product during approval.
            if (($row['item_source'] ?? '') === 'new_product') {
                $original = $doc->items->firstWhere('product_variant_id', (int) ($row['product_variant_id'] ?? 0));
                if (! $original || ! $original->created_variant_id) {
                    $this->fail('items', 'برای کالای جدید یک سند برگشت جداگانه ثبت کنید؛ اصلاح دریافت فقط برای کالاهای موجود است.');
                }
                $row['item_source'] = 'existing_product';
            }
            $key = $doc->isInternal() ? 'invoice:'.($row['invoice_item_id'] ?? 0) : implode(':', ['variant', $row['product_variant_id'] ?? 0, $row['item_condition'], $row['destination_warehouse_id'] ?? 0]);
            if (isset($seen[$key])) {
                $this->fail('items', 'قلم مرجع با وضعیت و مقصد یکسان نباید تکراری باشد.');
            }
            $seen[$key] = true;
        }
        unset($row);
        $data['items'] = $rows;
        if (count($data['items'] ?? []) > 500) {
            $this->fail('items', 'هر درخواست اصلاح حداکثر ۵۰۰ قلم دارد.');
        }
        try {
            $items = $doc->isInternal() ? $this->returns->prepareInternalItems($doc, $data) : $this->returns->prepareSazehItems($doc, $data);
        } catch (ModelNotFoundException) {
            $this->fail('items', 'یکی از اقلام یا مرجع درخواست دیگر موجود نیست؛ درخواست را دوباره بررسی کنید.');
        }
        if (count($items) !== count($data['items'] ?? [])) {
            $this->fail('items', 'اقلام مرجع یا سقف برگشت تغییر کرده است.');
        }
        foreach ($items as $index => $item) {
            if ((int) $item['return_quantity'] !== (int) $data['items'][$index]['return_quantity']) {
                $this->fail('items', 'تعداد درخواستی از سقف قابل برگشت بیشتر است.');
            }
        }
        if (array_sum(array_column($items, 'refund_amount')) > 9000000000000000) {
            $this->fail('items', 'مبلغ کل از محدوده مجاز بیشتر است.');
        }
        // Return reason and commission policy stay fixed; changing these after settlement requires a separate finance policy.
        if (($data['return_reason'] ?? $doc->return_reason) !== $doc->return_reason || ($data['commission_effect_type'] ?? $doc->commission_effect_type) !== $doc->commission_effect_type) {
            $this->fail('return_reason', 'علت و سیاست پورسانت سند دریافت‌شده ثابت است؛ فقط اقلام، تعداد، وضعیت کالا و مبلغ قابل اصلاح است.');
        }

        $metadata = $doc->only(['id', 'document_number', 'customer_id', 'source_type', 'invoice_id', 'return_reason', 'commission_effect_type']);
        $metadata['description'] = array_key_exists('description', $data) ? $data['description'] : $doc->description;
        $metadata['reference_number'] = array_key_exists('reference_number', $data) ? $data['reference_number'] : $doc->reference_number;

        return ['document' => $metadata, 'items' => $items, 'total_quantity' => array_sum(array_column($items, 'return_quantity')), 'total_refund_amount' => array_sum(array_column($items, 'refund_amount'))];
    }

    private function applyDelta(SalesReturnDocument $doc, SalesReturnCorrection $correction, array $proposal, int $actor): void
    {
        $before = $this->snapshot($doc);
        $old = $this->groups($before['items']);
        $new = $this->groups($proposal['items']);
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        sort($keys);
        // Lock variants before stock, matching the reservation service; quantities in warehouse stock are already free stock.
        ProductVariant::whereIn('id', collect(array_merge($before['items'], $proposal['items']))->pluck('product_variant_id')->unique())->orderBy('id')->lockForUpdate()->get();
        foreach ($keys as $key) {
            $group = $new[$key] ?? $old[$key];
            $delta = (int) ($new[$key]['quantity'] ?? 0) - (int) ($old[$key]['quantity'] ?? 0);
            if ($delta === 0) {
                continue;
            }
            $stock = WarehouseStock::where('warehouse_id', $group['warehouse_id'])->where('product_variant_id', $group['variant_id'])->lockForUpdate()->first();
            $quantity = (int) ($stock?->quantity ?? 0);
            if ($quantity + $delta < 0) {
                $this->fail('stock', 'موجودی آزاد برای اعمال اختلاف اصلاح کافی نیست؛ هیچ تغییری ثبت نشد.');
            }
            $result = WarehouseStockService::change($group['warehouse_id'], $group['product_id'], $delta, $group['variant_id']);
            StockMovement::create(['product_id' => $group['product_id'], 'product_variant_id' => $group['variant_id'], 'warehouse_id' => $group['warehouse_id'], 'user_id' => $actor, 'type' => $delta > 0 ? 'in' : 'out', 'reason' => 'sales_return_correction', 'quantity' => abs($delta), 'stock_before' => $quantity, 'stock_after' => (int) $result->quantity, 'note' => 'اختلاف اصلاح برگشت '.$doc->document_number.' درخواست #'.$correction->id, 'reference' => $doc->document_number.'#correction-'.$correction->id, 'reference_type' => SalesReturnCorrection::class, 'reference_id' => $correction->id]);
        }
        $amount = (int) $proposal['total_refund_amount'] - (int) $doc->total_refund_amount;
        if ($amount !== 0) {
            CustomerLedger::create(['customer_id' => $doc->customer_id, 'reference_type' => SalesReturnCorrection::class, 'reference_id' => $correction->id, 'type' => $amount > 0 ? 'credit' : 'debit', 'amount' => abs($amount), 'note' => 'اختلاف اصلاح برگشت '.$doc->document_number.' درخواست #'.$correction->id]);
        }
        // Keep original item IDs for historical receipt links. Removed lines become zero, never deleted.
        $remaining = $doc->items->keyBy('id');
        foreach ($proposal['items'] as $index => $attrs) {
            $item = $remaining->first(fn ($item) => $doc->isInternal() ? (int) $item->invoice_item_id === (int) ($attrs['invoice_item_id'] ?? 0) : (int) $item->product_variant_id === (int) $attrs['product_variant_id']);
            $attrs['sort_order'] = $index + 1;
            if ($item) {
                $item->update($attrs);
                $remaining->forget($item->id);
            } else {
                $doc->items()->create($attrs);
            }
        }
        foreach ($remaining as $item) {
            $item->update(['return_quantity' => 0, 'refund_amount' => 0]);
        }
        $doc->update(['total_quantity' => $proposal['total_quantity'], 'total_refund_amount' => $proposal['total_refund_amount'], 'items_count' => count($proposal['items']), 'updated_by' => $actor, 'description' => $proposal['document']['description'], 'reference_number' => $proposal['document']['reference_number']]);
        SalesReturnDocumentRevision::create(['document_id' => $doc->id, 'action' => 'approved_correction', 'token' => (string) Str::uuid(), 'reason' => $correction->reason, 'before_snapshot' => $before, 'after_snapshot' => $this->snapshot($doc->fresh('items')), 'previous_total' => $before['total_refund_amount'], 'new_total' => $proposal['total_refund_amount'], 'created_by' => $actor, 'created_at' => now()]);
    }

    private function groups(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            if ((int) $item['return_quantity'] === 0) {
                continue;
            }
            $key = sprintf('%012d:%012d', (int) $item['destination_warehouse_id'], (int) $item['product_variant_id']);
            $groups[$key] ??= ['warehouse_id' => (int) $item['destination_warehouse_id'], 'product_id' => (int) $item['product_id'], 'variant_id' => (int) $item['product_variant_id'], 'quantity' => 0];
            $groups[$key]['quantity'] += (int) $item['return_quantity'];
        }

        return $groups;
    }

    private function snapshot(SalesReturnDocument $doc): array
    {
        return ['document' => $doc->getAttributes(), 'items' => $doc->items->map->getAttributes()->values()->all(), 'total_quantity' => (int) $doc->total_quantity, 'total_refund_amount' => (int) $doc->total_refund_amount];
    }

    private function comparable(array $snapshot): array
    {
        return ['items' => collect($snapshot['items'])->filter(fn ($i) => (int) $i['return_quantity'] > 0)->map(fn ($i) => [(int) ($i['invoice_item_id'] ?? 0), (int) ($i['product_variant_id'] ?? 0), (int) $i['destination_warehouse_id'], $i['item_condition'], (int) $i['return_quantity'], (int) $i['refund_amount']])->sort()->values()->all(), 'description' => $snapshot['document']['description'] ?? null, 'reference_number' => $snapshot['document']['reference_number'] ?? null];
    }

    private function assertVersion(SalesReturnDocument $doc, string $version): void
    {
        if (! hash_equals(InboundReviewVersion::token($doc), $version)) {
            $this->fail('document', 'سند هم‌زمان تغییر کرده است؛ صفحه را تازه کنید یا درخواست اصلاح تازه ثبت کنید.');
        }
    }

    private function assertReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3 || mb_strlen($reason) > 1000) {
            $this->fail('correction', 'دلیل یا یادداشت بررسی را وارد کنید (۳ تا ۱۰۰۰ نویسه).');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function audit(SalesReturnCorrection $request, int $actor, string $action, string $reason): void
    {
        ActivityLog::create(['user_id' => $actor, 'action' => 'sales_return.correction_'.$action, 'subject_type' => SalesReturnCorrection::class, 'subject_id' => $request->id, 'description' => 'اصلاح برگشت از فروش: '.$reason, 'properties' => ['document_id' => $request->document_id, 'correction_id' => $request->id, 'status' => $request->status], 'occurred_at' => now()]);
    }
}
