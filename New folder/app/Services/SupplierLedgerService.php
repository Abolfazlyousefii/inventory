<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Purchase;
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
