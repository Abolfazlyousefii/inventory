<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnRevision;
use App\Models\Supplier;
use App\Models\SupplierLedger;

class SupplierLedgerService
{
    public function syncPurchaseCredit(Purchase $purchase): void
    {
        if (empty($purchase->supplier_id)) {
            return;
        }

        SupplierLedger::query()
            ->where('reference_type', Purchase::class)
            ->where('reference_id', (int) $purchase->id)
            ->where('type', 'credit')
            ->where('supplier_id', '!=', (int) $purchase->supplier_id)
            ->delete();

        $amount = max((int) $purchase->total_amount, 0);
        $note = 'بستانکاری تامین‌کننده بابت خرید کالا شماره PUR-' . $purchase->id;

        SupplierLedger::query()->updateOrCreate(
            [
                'supplier_id' => (int) $purchase->supplier_id,
                'reference_type' => Purchase::class,
                'reference_id' => (int) $purchase->id,
                'type' => 'credit',
            ],
            [
                'amount' => $amount,
                'note' => $note,
            ]
        );

        $this->syncCustomerCreditForAutoSupplierPurchase($purchase, $amount, $note);
    }

    public function syncPurchaseReturnDebit(PurchaseReturn $return, ?PurchaseReturnRevision $revision = null): void
    {
        if (empty($return->supplier_id)) {
            return;
        }

        // Callers hold the source purchase/document lock. Never rewrite a posted entry.
        $reference = [
                'supplier_id' => (int) $return->supplier_id,
                'reference_type' => PurchaseReturn::class,
                'reference_id' => (int) $return->id,
                'type' => 'debit',
        ];
        $original = SupplierLedger::where($reference)->first();
        if (! $original) {
            // Unedited historical returns remain read-only fallbacks. A newly
            // edited historical document gets its first actual posting now.
            if (! $return->trashed()) SupplierLedger::create($reference + [
                'amount' => max(0, (int) $return->total_amount),
                'note' => 'بدهکاری تأمین‌کننده بابت برگشت از خرید شماره PR-'.$return->document_number,
            ]);
            return;
        }
        $revisionIds = $return->revisions()->pluck('id');
        $net = (int) SupplierLedger::where('supplier_id', $return->supplier_id)
            ->where(function ($q) use ($return, $revisionIds) {
                $q->where(fn ($r) => $r->where('reference_type', PurchaseReturn::class)->where('reference_id', $return->id))
                    ->orWhere(fn ($r) => $r->where('reference_type', PurchaseReturnRevision::class)->whereIn('reference_id', $revisionIds));
            })->selectRaw("COALESCE(SUM(CASE WHEN type='debit' THEN amount ELSE -amount END), 0) AS net")->value('net');
        $delta = ($return->trashed() ? 0 : (int) $return->total_amount) - $net;
        if ($delta === 0) return;
        if (! $revision || (int) $revision->purchase_return_id !== (int) $return->id) {
            throw \Illuminate\Validation\ValidationException::withMessages(['items' => 'اصلاح مالی برگشت به سابقهٔ معتبر سند نیاز دارد.']);
        }
        SupplierLedger::firstOrCreate([
            'supplier_id' => (int) $return->supplier_id,
            'reference_type' => PurchaseReturnRevision::class,
            'reference_id' => $revision->id,
            'type' => $delta > 0 ? 'debit' : 'credit',
        ], [
            'amount' => abs($delta),
            'note' => ($return->trashed() ? 'برگشت ثبت مالی پس از ابطال ' : 'مابه‌التفاوت اصلاح ').'PR-'.$return->document_number,
        ]);
    }

    public function voidPurchaseCredit(Purchase $purchase): void
    {
        SupplierLedger::query()
            ->where('reference_type', Purchase::class)
            ->where('reference_id', (int) $purchase->id)
            ->where('type', 'credit')
            ->delete();

        $this->deleteCustomerPurchaseCredit($purchase);
    }

    private function syncCustomerCreditForAutoSupplierPurchase(Purchase $purchase, int $amount, string $note): void
    {
        $customer = $this->customerForAutoCreatedSupplier((int) $purchase->supplier_id);

        CustomerLedger::query()
            ->where('reference_type', Purchase::class)
            ->where('reference_id', (int) $purchase->id)
            ->where('type', 'credit')
            ->when($customer, fn ($query) => $query->where('customer_id', '!=', (int) $customer->id))
            ->delete();

        if (! $customer) {
            return;
        }

        CustomerLedger::query()->updateOrCreate(
            [
                'customer_id' => (int) $customer->id,
                'reference_type' => Purchase::class,
                'reference_id' => (int) $purchase->id,
                'type' => 'credit',
            ],
            [
                'amount' => $amount,
                'note' => $note,
            ]
        );
    }

    private function deleteCustomerPurchaseCredit(Purchase $purchase): void
    {
        CustomerLedger::query()
            ->where('reference_type', Purchase::class)
            ->where('reference_id', (int) $purchase->id)
            ->where('type', 'credit')
            ->delete();
    }

    private function customerForAutoCreatedSupplier(int $supplierId): ?Customer
    {
        $supplier = Supplier::query()->find($supplierId);

        if (! $supplier || ! str_contains((string) $supplier->additional_notes, 'ایجاد خودکار از مشتری')) {
            return null;
        }

        $supplierPhone = $this->normalizePhone($supplier->phone);

        if ($supplierPhone !== '') {
            return Customer::query()
                ->get(['id', 'first_name', 'last_name', 'mobile'])
                ->first(fn (Customer $customer) => $this->normalizePhone($customer->mobile) === $supplierPhone);
        }

        return Customer::query()
            ->get(['id', 'first_name', 'last_name', 'mobile'])
            ->first(fn (Customer $customer) => $customer->display_name === $supplier->name);
    }

    private function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }
}
