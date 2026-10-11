<?php

namespace App\Services;

use App\Models\User;
use App\Support\PageAccessCatalog;
use Illuminate\Support\Facades\Route;

class DashboardWorkspaceService
{
    /** Every shortcut uses the same page authorization as the sidebar and routes. */
    public function cards(User $user): array
    {
        $definitions = [
            ['preinvoice.create', 'سفارش جدید', 'فروش', 'blue', 'sales', true],
            ['preinvoice.my.index', 'سفارش‌های من', 'فروش', 'mint', 'document', true],
            ['products.index', 'نمایش کالاها', 'کالاها', 'violet', 'products', true],
            ['vouchers.sales.queue', 'صف جمع‌آوری', 'انبارداری', 'orange', 'warehouse', true],
            ['warehouse.shipping.index', 'صف ارسال', 'انبارداری', 'teal', 'shipping', true],
            ['warehouse.daily-sales.index', 'گزارش فروش روز', 'انبارداری', 'rose', 'chart', true],
            ['preinvoice.draft.index', 'تأیید مالی', 'مالی', 'amber', 'document', true],
            ['account-statements.index', 'گردش حساب اشخاص', 'مالی', 'indigo', 'people', true],
            ['finance.reports.index', 'گزارش‌های مالی', 'مالی', 'green', 'chart', true],
            ['stock-thresholds.index', 'آستانه موجودی', 'انبارداری', 'amber', 'products', false],
            ['warehouse-reservations.index', 'رزروهای پیش‌فاکتور', 'انبارداری', 'violet', 'document', false],
            ['warehouse.inbound.index', 'صف ورودی موجودی', 'انبارداری', 'mint', 'warehouse', false],
            ['stocktake.index', 'انبارگردانی', 'انبارداری', 'orange', 'warehouse', false],
            ['customers.index', 'اشخاص و طرف‌حساب‌ها', 'فروش', 'indigo', 'people', false],
            ['invoices.index', 'فاکتورها', 'مالی', 'blue', 'document', false],
            ['purchases.index', 'خرید کالا', 'کالاها', 'green', 'sales', false],
            ['admin.product-exports.index', 'خروجی کالا', 'کالاها', 'teal', 'products', false],
            ['products.price-changes.index', 'تغییر قیمت کالا', 'کالاها', 'orange', 'products', false],
            ['vouchers.index', 'حواله‌های انبار', 'انبارداری', 'blue', 'warehouse', false],
            ['asset.hub', 'امین اموال', 'انبارداری', 'indigo', 'products', false],
            ['warehouse-map.index', 'نقشه انبار', 'انبارداری', 'mint', 'warehouse', false],
            ['vouchers.return-from-sale.index', 'برگشت از فروش', 'فروش', 'rose', 'sales', false],
            ['commercial.invoice-reassignments.index', 'انتقال فروشنده فاکتور', 'فروش', 'violet', 'people', false],
            ['categories.index', 'دسته‌بندی کالاها', 'پیکربندی', 'blue', 'products', false],
            ['model-lists.index', 'مدل لیست کالا', 'پیکربندی', 'mint', 'document', false],
            ['shipping-methods.index', 'روش‌های ارسال', 'پیکربندی', 'teal', 'shipping', false],
            ['users.index', 'کاربران و پرسنل', 'مدیریت', 'indigo', 'people', false],
            ['admin.permissions.index', 'دسترسی کاربران', 'مدیریت', 'amber', 'people', false],
            ['admin.roles.index', 'نقش‌ها', 'مدیریت', 'violet', 'people', false],
            ['activity-logs.index', 'لاگ فعالیت‌ها', 'مدیریت', 'rose', 'document', false],
            ['inventory-webhooks.index', 'همگام‌سازی موجودی', 'پیکربندی', 'green', 'products', false],
        ];
        $cards = [];
        foreach ($definitions as [$route, $title, $group, $tone, $icon, $primary]) {
            if (! Route::has($route) || ! PageAccessCatalog::userCanRoute($user, $route)) {
                continue;
            }
            $cards[] = compact('route', 'title', 'group', 'tone', 'icon', 'primary') + ['url' => route($route)];
        }

        // Small workspaces show all shortcuts immediately; larger ones stay compact.
        if (count($cards) <= 12) {
            foreach ($cards as &$card) {
                $card['primary'] = true;
            }
            unset($card);
        }

        return $cards;
    }

    public function attention(User $user): array
    {
        if (! Route::has('preinvoice.my.index') || ! PageAccessCatalog::userCanRoute($user, 'preinvoice.my.index')) {
            return [];
        }
        $documents = app(MySalesDocumentsService::class);
        $count = $documents->applyBucket($documents->baseQuery((int) $user->id), MySalesDocumentsService::BUCKET_NEEDS_CORRECTION)
            ->toBase()->getCountForPagination();

        return $count > 0 ? [[
            'title' => 'سفارش نیازمند بررسی',
            'count' => $count,
            'url' => route('preinvoice.my.index', ['tab' => MySalesDocumentsService::TAB_NEEDS_CORRECTION]),
        ]] : [];
    }
}
