<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;

class SalesHavalehStatusService
{
    public const PENDING_WAREHOUSE_APPROVAL = 'pending_warehouse_approval';
    public const PENDING_COLLECTION = 'pending_collection';
    public const WAREHOUSE_RECEIVED = 'warehouse_received';
    public const COLLECTING = 'collecting';
    public const PENDING_FINANCE_REAPPROVAL = 'pending_finance_reapproval';
    public const READY_TO_SHIP = 'ready_to_ship';
    public const RETURNED_TO_SALES_AFTER_COLLECTION = 'returned_to_sales_after_collection';
    public const CHECKING_DISCREPANCY = 'checking_discrepancy';
    public const FINAL_CHECK = 'final_check';
    public const PACKING = 'packing';
    public const SHIPPED = 'shipped';
    public const NOT_SHIPPED = 'not_shipped';

    public function all(): array
    {
        return [
            self::PENDING_WAREHOUSE_APPROVAL,
            self::PENDING_COLLECTION,
            self::WAREHOUSE_RECEIVED,
            self::COLLECTING,
            self::PENDING_FINANCE_REAPPROVAL,
            self::READY_TO_SHIP,
            self::RETURNED_TO_SALES_AFTER_COLLECTION,
            self::CHECKING_DISCREPANCY,
            self::FINAL_CHECK,
            self::PACKING,
            self::SHIPPED,
            self::NOT_SHIPPED,
        ];
    }

    public function labels(): array
    {
        return [
            self::PENDING_WAREHOUSE_APPROVAL => 'در انتظار تایید انبار',
            self::PENDING_COLLECTION => 'در صف جمع‌آوری',
            self::WAREHOUSE_RECEIVED => 'دریافت‌شده توسط انبار',
            self::COLLECTING => 'در حال جمع‌آوری',
            self::PENDING_FINANCE_REAPPROVAL => 'در انتظار تایید مجدد مالی',
            self::READY_TO_SHIP => 'آماده ارسال',
            self::RETURNED_TO_SALES_AFTER_COLLECTION => 'ارجاع‌شده به اپراتور پس از اصلاح انبار',
            self::CHECKING_DISCREPANCY => 'در حال مغایرت و بررسی',
            self::FINAL_CHECK => 'در حال چک نهایی',
            self::PACKING => 'در حال بسته‌بندی',
            self::SHIPPED => 'ارسال شده',
            self::NOT_SHIPPED => 'کنسل شده',
        ];
    }

    public function editableStatusesForRegularUsers(): array
    {
        return [
            self::PENDING_WAREHOUSE_APPROVAL,
            self::PENDING_COLLECTION,
            self::WAREHOUSE_RECEIVED,
            self::COLLECTING,
            self::CHECKING_DISCREPANCY,
            self::FINAL_CHECK,
        ];
    }

    public function isEditable(Invoice $invoice, ?User $user): bool
    {
        if ($invoice->status === self::SHIPPED) {
            return false;
        }

        if ($this->isAdmin($user)) {
            return true;
        }

        return in_array((string) $invoice->status, $this->editableStatusesForRegularUsers(), true);
    }

    public function assertValidTransition(Invoice $invoice, string $newStatus, ?User $user): void
    {
        if (!in_array($newStatus, $this->all(), true)) {
            abort(422, 'وضعیت انتخاب‌شده معتبر نیست.');
        }

        $current = (string) $invoice->status;

        if ($current === $newStatus) {
            return;
        }

        if ($current === self::NOT_SHIPPED) {
            abort(422, 'فاکتور کنسل‌شده قابل تغییر وضعیت نیست.');
        }

        if ($this->isAdmin($user)) {
            return;
        }

        $allowedNext = [
            self::PENDING_WAREHOUSE_APPROVAL => [self::WAREHOUSE_RECEIVED, self::COLLECTING, self::CHECKING_DISCREPANCY, self::NOT_SHIPPED],
            self::PENDING_COLLECTION => [self::WAREHOUSE_RECEIVED, self::COLLECTING],
            self::WAREHOUSE_RECEIVED => [self::COLLECTING, self::READY_TO_SHIP],
            self::COLLECTING => [self::READY_TO_SHIP, self::FINAL_CHECK, self::CHECKING_DISCREPANCY, self::NOT_SHIPPED],
            self::PENDING_FINANCE_REAPPROVAL => [],
            self::READY_TO_SHIP => [],
            self::RETURNED_TO_SALES_AFTER_COLLECTION => [],
            self::CHECKING_DISCREPANCY => [self::COLLECTING, self::FINAL_CHECK, self::NOT_SHIPPED],
            self::FINAL_CHECK => [self::PACKING, self::CHECKING_DISCREPANCY, self::NOT_SHIPPED],
            self::PACKING => [self::SHIPPED, self::CHECKING_DISCREPANCY, self::NOT_SHIPPED],
            self::SHIPPED => [],
            self::NOT_SHIPPED => [],
        ];

        if (! in_array($newStatus, $allowedNext[$current] ?? [], true)) {
            abort(422, 'تغییر وضعیت انتخاب‌شده با روند حواله فروش مجاز نیست.');
        }
    }

    private function isAdmin(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $hasRole = method_exists($user, 'hasAnyRole')
            ? $user->hasAnyRole(['admin', 'Admin', 'super-admin', 'warehouse', 'Warehouse', 'manager', 'Manager'])
            : false;

        $hasPermission = method_exists($user, 'can')
            ? $user->can('sales-havaleh.status.skip')
            : false;

        return $hasRole || $hasPermission;
    }
}
