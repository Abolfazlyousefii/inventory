<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\SellerReassignmentAudit;
use App\Models\User;
use App\Services\Finance\SellerSalesDocumentReassignmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesDocumentSellerReassignmentService
{
    public function __construct(
        private readonly SellerSalesDocumentReassignmentService $commissionDocumentReassignmentService,
    ) {}

    public function reassignInvoiceSeller(
        Invoice $invoice,
        User $newSeller,
        User $actor,
        string $reason,
        bool $syncLinkedPreinvoice = true,
        string $source = 'ui',
        ?string $operationKey = null,
    ): SellerReassignmentResult {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'دلیل تغییر فروشنده الزامی است.']);
        }
        if (! $newSeller->is_active || ! $newSeller->can_access_erp || ! $newSeller->is_seller) {
            throw ValidationException::withMessages(['seller_id' => 'کاربر مقصد فروشنده فعال و مجاز ERP نیست.']);
        }

        return DB::transaction(function () use ($invoice, $newSeller, $actor, $reason, $syncLinkedPreinvoice, $source, $operationKey): SellerReassignmentResult {
            $locked = Invoice::query()->with('preinvoiceOrder')->lockForUpdate()->findOrFail($invoice->id);
            $oldSellerId = $locked->seller_id ? (int) $locked->seller_id : null;
            if ($oldSellerId === (int) $newSeller->id
                && (! $syncLinkedPreinvoice || ! $locked->preinvoiceOrder || (int) $locked->preinvoiceOrder->seller_id === (int) $newSeller->id)) {
                return new SellerReassignmentResult($locked->id, $locked->preinvoice_order_id, $oldSellerId, $newSeller->id, false);
            }

            $locked->forceFill(['seller_id' => $newSeller->id])->save();
            if ($syncLinkedPreinvoice && $locked->preinvoiceOrder) {
                $locked->preinvoiceOrder()->lockForUpdate()->firstOrFail()->forceFill(['seller_id' => $newSeller->id])->save();
            }
            $audit = SellerReassignmentAudit::query()->create([
                'invoice_id' => $locked->id, 'preinvoice_id' => $locked->preinvoice_order_id,
                'old_seller_id' => $oldSellerId, 'new_seller_id' => $newSeller->id,
                'changed_by' => $actor->id, 'reason' => $reason, 'source' => $source,
                'changed_at' => now(), 'operation_key' => $operationKey,
            ]);

            $this->commissionDocumentReassignmentService->reconcile($locked, $newSeller, $audit);

            return new SellerReassignmentResult($locked->id, $locked->preinvoice_order_id, $oldSellerId, $newSeller->id, true);
        });
    }

    /** @return list<SellerReassignmentResult> */
    public function reassignMany(array $invoiceIds, User $seller, User $actor, string $reason, bool $sync = true, string $source = 'bulk', ?string $operationKey = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $invoiceIds)));
        if ($ids === [] || count($ids) > 100) {
            throw ValidationException::withMessages(['invoice_ids' => 'بین ۱ تا ۱۰۰ فاکتور انتخاب کنید.']);
        }

        return DB::transaction(function () use ($ids, $seller, $actor, $reason, $sync, $source, $operationKey): array {
            $invoices = Invoice::query()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            if ($invoices->count() !== count($ids)) {
                throw ValidationException::withMessages(['invoice_ids' => 'حداقل یکی از فاکتورهای انتخاب‌شده معتبر نیست.']);
            }

            return array_map(fn (int $id) => $this->reassignInvoiceSeller($invoices[$id], $seller, $actor, $reason, $sync, $source, $operationKey), $ids);
        });
    }
}
