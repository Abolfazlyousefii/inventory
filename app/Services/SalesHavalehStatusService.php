<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;

class SalesHavalehStatusService
{
    public const PENDING_WAREHOUSE_APPROVAL = 'pending_warehouse_approval';
    public const COLLECTING = 'collecting';
    public const CHECKING_DISCREPANCY = 'checking_discrepancy';
    public const FINAL_CHECK = 'final_check';
    public const PACKING = 'packing';
    public const SHIPPED = 'shipped';
    public const NOT_SHIPPED = 'not_shipped';

    public function all(): array
    {
        return array_values(array_unique(array_merge($this->manualStatuses(), [
            self::PENDING_WAREHOUSE_APPROVAL,
            self::FINAL_CHECK,
            self::PACKING,
            self::NOT_SHIPPED,
            Invoice::STATUS_PENDING_FINANCE_REAPPROVAL,
            Invoice::STATUS_FINANCE_APPROVED,
        ])));
    }

    public function manualStatuses(): array
    {
        return [
            self::CHECKING_DISCREPANCY,
            self::COLLECTING,
            self::SHIPPED,
        ];
    }

    public function labels(): array
    {
        return [
            self::PENDING_WAREHOUSE_APPROVAL => 'در انتظار تایید انبار',
            self::COLLECTING => 'در حال جمع‌آوری',
            self::CHECKING_DISCREPANCY => 'در حال بررسی',
            self::FINAL_CHECK => 'در حال چک نهایی',
            self::PACKING => 'در حال بسته‌بندی',
            self::SHIPPED => 'ارسال شده',
            self::NOT_SHIPPED => 'کنسل شده',
            Invoice::STATUS_PENDING_FINANCE_REAPPROVAL => 'در انتظار تایید مالی مجدد',
            Invoice::STATUS_FINANCE_APPROVED => 'تایید مالی شده',
        ];
    }

    public function editableStatusesForRegularUsers(): array
    {
        return [
            self::COLLECTING,
            self::CHECKING_DISCREPANCY,
        ];
    }

    public function isEditable(Invoice $invoice, ?User $user): bool
    {
        if ($invoice->status === self::SHIPPED || $invoice->status === Invoice::STATUS_PENDING_FINANCE_REAPPROVAL) {
            return false;
        }

        if ($this->isAdmin($user)) {
            return true;
        }

        return in_array((string) $invoice->status, $this->editableStatusesForRegularUsers(), true);
    }

    public function assertValidTransition(Invoice $invoice, string $newStatus, ?User $user): void
    {
        if (!in_array($newStatus, $this->manualStatuses(), true)) {
            abort(422, 'فقط وضعیت‌های عملیاتی دستی قابل انتخاب هستند.');
        }

        $current = (string) $invoice->status;

        if ($current === $newStatus) {
            return;
        }

        if ($current === Invoice::STATUS_FINANCE_APPROVED && ! $this->isAdmin($user)) {
            abort(422, 'فاکتور تایید مالی‌شده فقط توسط مدیر قابل تغییر وضعیت عملیاتی است.');
        }

        if ($current === self::NOT_SHIPPED) {
            abort(422, 'فاکتور کنسل‌شده قابل تغییر وضعیت نیست.');
        }

        if ($this->isAdmin($user)) {
            return;
        }

        $allowedNext = [
            self::PENDING_WAREHOUSE_APPROVAL => [self::COLLECTING, self::CHECKING_DISCREPANCY],
            self::COLLECTING => [self::CHECKING_DISCREPANCY, self::SHIPPED],
            self::CHECKING_DISCREPANCY => [self::COLLECTING, self::SHIPPED],
            self::FINAL_CHECK => [self::COLLECTING, self::CHECKING_DISCREPANCY, self::SHIPPED],
            self::PACKING => [self::COLLECTING, self::CHECKING_DISCREPANCY, self::SHIPPED],
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
