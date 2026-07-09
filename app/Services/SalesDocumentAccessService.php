<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\User;

class SalesDocumentAccessService
{
    public function isManager(?User $user): bool
    {
        if (! $user || ! method_exists($user, 'hasAnyRole')) {
            return false;
        }

        return $user->hasAnyRole(['admin', 'Admin', 'Manager', 'manager', 'super-admin']);
    }

    public function isWarehouse(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $hasRole = method_exists($user, 'hasAnyRole')
            ? $user->hasAnyRole(['warehouse', 'StorageManager'])
            : false;

        return $hasRole || (method_exists($user, 'can') && $user->can('warehouse.approve'));
    }

    public function isFinance(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $hasRole = method_exists($user, 'hasAnyRole')
            ? $user->hasAnyRole(['finance', 'Accountant'])
            : false;

        return $hasRole || (method_exists($user, 'can') && $user->can('finance.approve'));
    }

    public function isPreinvoiceOwner(PreinvoiceOrder $order, ?User $user): bool
    {
        return $user && (int) $order->created_by > 0 && (int) $order->created_by === (int) $user->id;
    }

    public function isInvoiceOwner(Invoice $invoice, ?User $user): bool
    {
        $invoice->loadMissing('preinvoiceOrder:id,created_by');

        return $invoice->preinvoiceOrder
            && $this->isPreinvoiceOwner($invoice->preinvoiceOrder, $user);
    }

    public function canSellerEditPreinvoiceItems(PreinvoiceOrder $order, ?User $user): bool
    {
        if (! in_array((string) $order->status, [
            PreinvoiceOrder::STATUS_DRAFT,
            PreinvoiceOrder::STATUS_RETURNED_TO_SALES,
            PreinvoiceOrder::STATUS_RESERVATION_EXPIRED,
        ], true)) {
            return false;
        }

        return $this->isPreinvoiceOwner($order, $user) || $this->isFinance($user) || $this->isManager($user);
    }

    public function isConvertedPreinvoice(PreinvoiceOrder $order): bool
    {
        return (bool) $order->invoice || (string) $order->status === PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE;
    }

    public function canViewInvoiceReadonly(Invoice $invoice, ?User $user): bool
    {
        return $this->isManager($user)
            || $this->isFinance($user)
            || $this->isWarehouse($user)
            || $this->isInvoiceOwner($invoice, $user);
    }

    public function canSellerEditInvoiceItems(Invoice $invoice, ?User $user): bool
    {
        return $this->isFinance($user) || $this->isManager($user);
    }
}
