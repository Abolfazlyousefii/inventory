<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\SalesReturnDocument;
use App\Models\SalesReturnDocumentItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesReturnService
{
    public function __construct(private SalesReturnCalculationService $calculator) {}

    public function createDraft(array $data, User $actor): SalesReturnDocument
    {
        return $this->createDraftWithRetry($data, $actor);
    }

    public function updateDraft(SalesReturnDocument $document, array $data, User $actor): SalesReturnDocument
    {
        return DB::transaction(function () use ($document, $data, $actor) {
            $locked = SalesReturnDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($locked);
            $prepared = $this->prepareDraftPayload($data, $actor);

            $locked->update($prepared['document'] + ['updated_by' => $actor->id]);
            $locked->items()->delete();
            $this->persistItems($locked, $prepared['items']);
            $this->recalculateDraftTotals($locked);

            return $locked->fresh(['items', 'customer', 'invoice']);
        });
    }

    public function cancelDraft(SalesReturnDocument $document, User $actor, ?string $reason): SalesReturnDocument
    {
        return DB::transaction(function () use ($document, $actor, $reason) {
            $locked = SalesReturnDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($locked);
            $locked->update([
                'status' => SalesReturnDocument::STATUS_CANCELLED,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'updated_by' => $actor->id,
            ]);

            return $locked->fresh(['items', 'customer', 'invoice']);
        });
    }

    public function prepareInternalItems(Invoice $invoice, array $items, User $actor): array
    {
        $items = $this->positiveQuantityItems($items);
        $items = $this->applyDestinationWarehouses($items, $actor);
        return $this->calculator->calculateInternalInvoicePreview($invoice, $items);
    }

    public function prepareSazehItems(array $items, User $actor): array
    {
        $items = $this->positiveQuantityItems($items);
        $items = $this->applyDestinationWarehouses($items, $actor);
        return $this->calculator->calculateSazehPreview($items);
    }

    public function recalculateDraftTotals(SalesReturnDocument $document): void
    {
        $document->load('items');
        $total = (int) $document->items->sum('refund_amount');
        $document->update([
            'refund_subtotal' => $total,
            'refund_total' => $total,
            'items_count' => $document->items->count(),
        ]);
    }

    private function createDraftWithRetry(array $data, User $actor, int $attempts = 3): SalesReturnDocument
    {
        for ($i = 0; $i < $attempts; $i++) {
            try {
                return DB::transaction(function () use ($data, $actor) {
                    $prepared = $this->prepareDraftPayload($data, $actor);
                    $document = SalesReturnDocument::create($prepared['document'] + [
                        'document_number' => $this->nextDocumentNumber(),
                        'status' => SalesReturnDocument::STATUS_DRAFT,
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]);
                    $this->persistItems($document, $prepared['items']);
                    $this->recalculateDraftTotals($document);

                    return $document->fresh(['items', 'customer', 'invoice']);
                });
            } catch (QueryException $e) {
                if ($i === $attempts - 1 || !str_contains($e->getMessage(), 'document_number')) {
                    throw $e;
                }
            }
        }

        throw ValidationException::withMessages(['document_number' => 'امکان تولید شماره سند یکتا وجود ندارد.']);
    }

    private function prepareDraftPayload(array $data, User $actor): array
    {
        if (($data['source_type'] ?? null) === SalesReturnDocument::SOURCE_INTERNAL_INVOICE) {
            $invoice = Invoice::query()->with(['items.product', 'items.variant'])->whereKey((int) $data['invoice_id'])->firstOrFail();
            $preview = $this->prepareInternalItems($invoice, $data['items'] ?? [], $actor);
            return [
                'document' => [
                    'source_type' => SalesReturnDocument::SOURCE_INTERNAL_INVOICE,
                    'customer_id' => (int) $data['customer_id'],
                    'invoice_id' => (int) $data['invoice_id'],
                    'external_invoice_number' => null,
                    'external_invoice_date' => null,
                    'return_reason' => $data['return_reason'] ?? null,
                    'description' => $data['description'] ?? null,
                    'refund_subtotal' => $preview['refund_subtotal'],
                    'refund_total' => $preview['refund_total'],
                    'items_count' => count($preview['items']),
                ],
                'items' => $preview['items'],
            ];
        }

        $preview = $this->prepareSazehItems($data['items'] ?? [], $actor);
        return [
            'document' => [
                'source_type' => SalesReturnDocument::SOURCE_SAZEH_HESAB,
                'customer_id' => (int) $data['customer_id'],
                'invoice_id' => null,
                'external_invoice_number' => $data['external_invoice_number'] ?? null,
                'external_invoice_date' => $data['external_invoice_date'] ?? null,
                'return_reason' => $data['return_reason'] ?? null,
                'description' => $data['description'] ?? null,
                'refund_subtotal' => $preview['refund_subtotal'],
                'refund_total' => $preview['refund_total'],
                'items_count' => count($preview['items']),
            ],
            'items' => $preview['items'],
        ];
    }

    private function nextDocumentNumber(): string
    {
        $last = SalesReturnDocument::query()
            ->where('document_number', 'like', 'SR-%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('document_number');

        $next = 1;
        if (is_string($last) && preg_match('/^SR-(\d{5,})$/', $last, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return 'SR-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function positiveQuantityItems(array $items): array
    {
        return array_values(array_filter($items, fn (array $item) => (int) ($item['return_quantity'] ?? 0) > 0));
    }

    private function applyDestinationWarehouses(array $items, User $actor): array
    {
        $central = Warehouse::query()->where('type', 'central')->where('is_active', true)->orderBy('id')->first();
        $return = Warehouse::query()->where('type', 'return')->where('is_active', true)->orderBy('id')->first();
        if (!$central) {
            throw ValidationException::withMessages(['destination_warehouse_id' => 'انبار مرکزی در تنظیمات سیستم تعریف نشده است.']);
        }
        if (!$return) {
            throw ValidationException::withMessages(['destination_warehouse_id' => 'انبار مرجوعی در تنظیمات سیستم تعریف نشده است.']);
        }

        $canOverride = $actor->hasPermission('sales_returns.override_destination');
        $allowed = [(int) $central->id, (int) $return->id];
        foreach ($items as $index => &$item) {
            $default = ($item['item_condition'] ?? SalesReturnDocumentItem::CONDITION_HEALTHY) === SalesReturnDocumentItem::CONDITION_DAMAGED
                ? (int) $return->id
                : (int) $central->id;

            if (!$canOverride) {
                $item['destination_warehouse_id'] = $default;
                continue;
            }

            $requested = (int) ($item['destination_warehouse_id'] ?? $default);
            if (!in_array($requested, $allowed, true)) {
                throw ValidationException::withMessages(["items.{$index}.destination_warehouse_id" => 'فقط انبار مرکزی یا انبار مرجوعی برای برگشت مجاز است.']);
            }
            $item['destination_warehouse_id'] = $requested;
        }
        unset($item);

        return $items;
    }

    private function persistItems(SalesReturnDocument $document, array $items): void
    {
        foreach (array_values($items) as $index => $item) {
            $document->items()->create($item + ['sort_order' => (int) ($item['sort_order'] ?? $index)]);
        }
    }

    private function assertDraft(SalesReturnDocument $document): void
    {
        if (!$document->isDraft()) {
            throw ValidationException::withMessages(['status' => 'فقط سند پیش‌نویس قابل ویرایش یا لغو است.']);
        }
    }
}
