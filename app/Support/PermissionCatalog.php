<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

class PermissionCatalog
{
    public static function superAdminRoles(): array
    {
        return ['super_admin', 'super-admin', 'Super Admin', 'مدیرکل', 'Owner'];
    }

    public static function administratorRoles(): array
    {
        return array_merge(self::superAdminRoles(), ['admin', 'Admin', 'ادمین']);
    }

    public static function protectedSystemRoles(): array
    {
        return array_values(array_unique(array_merge(
            self::superAdminRoles(),
            ['admin', 'Admin', 'ادمین', 'staff', 'editor', 'union_expert', 'user', 'employee']
        )));
    }

    public static function priceChangeRoles(): array
    {
        return array_merge(self::administratorRoles(), ['manager', 'Manager', 'مدیر', 'finance', 'Finance', 'مالی']);
    }

    public static function groups(): array
    {
        return [
            'پورسانت فروشندگان' => [
                'commissions.manage_rates' => 'مدیریت نرخ‌های پورسانت',
                'commissions.manage_campaigns' => 'مدیریت کمپین‌های پورسانت',
                'commissions.manage_periods' => 'مدیریت دوره‌های پورسانت',
                'commissions.manage_targets' => 'مدیریت تارگت‌های پورسانت',
                'commissions.recalculate' => 'به‌روزرسانی محاسبات پورسانت',
                'commissions.view_seller_details' => 'مشاهده جزئیات پورسانت فروشنده',
                'commissions.manage_documents' => 'مدیریت اسناد پورسانت',
                'commissions.review_documents' => 'بررسی مالی اسناد پورسانت',
                'commissions.print_documents' => 'چاپ اسناد پورسانت',
                'commissions.finalize_documents' => 'نهایی‌سازی اسناد پورسانت',
                'commissions.close_periods' => 'بستن دوره‌های پورسانت',
                'commissions.manage_adjustments' => 'ثبت تعدیلات پورسانت',
                'commissions.review_adjustments' => 'بررسی تعدیلات پورسانت',
                'commissions.record_payments' => 'ثبت پرداخت پورسانت',
                'commissions.void_payments' => 'ابطال پرداخت پورسانت',
                'commissions.mark_period_paid' => 'پرداخت‌شده کردن دوره پورسانت',
                'commissions.view_settlements' => 'مشاهده تسویه‌های پورسانت',
            ],
            'داشبورد' => [
                'dashboard.view' => 'مشاهده داشبورد',
                'dashboard.search' => 'جستجوی سراسری',
                'notifications.view' => 'مشاهده اعلان‌ها',
                'notifications.manage' => 'مدیریت اعلان‌ها',
            ],
            'کالاها' => [
                'products.view' => 'مشاهده کالاها',
                'products.show' => 'مشاهده جزئیات کالا',
                'products.create' => 'ایجاد کالا',
                'products.edit' => 'ویرایش کالا',
                'products.delete' => 'حذف کالا',
                'products.print' => 'چاپ کالا/لیست قیمت',
                'products.export' => 'خروجی کالاها',
                'products.import' => 'ورود/همگام‌سازی کالاها',
                'products.change_status' => 'تغییر وضعیت کالا',
                'products.ledger' => 'مشاهده گردش خرید و فروش کالا',
                'products.price_changes.view' => 'مشاهده تغییر قیمت کالا',
                'products.price_changes.create' => 'ایجاد سند تغییر قیمت کالا',
                'products.price_changes.apply' => 'اعمال تغییر قیمت کالا',
                'products.price_changes.cancel' => 'لغو تغییر قیمت کالا',
            ],
            'دسته‌بندی کالا' => [
                'categories.view' => 'مشاهده دسته‌بندی‌ها',
                'categories.create' => 'ایجاد دسته‌بندی',
                'categories.edit' => 'ویرایش دسته‌بندی',
                'categories.delete' => 'حذف دسته‌بندی',
            ],
            'برندها و مدل‌ها' => [
                'brands.view' => 'مشاهده برندها',
                'brands.create' => 'ایجاد برند',
                'brands.edit' => 'ویرایش برند',
                'brands.delete' => 'حذف برند',
                'model_lists.view' => 'مشاهده مدل لیست',
                'model_lists.create' => 'ایجاد مدل',
                'model_lists.edit' => 'ویرایش مدل',
                'model_lists.delete' => 'حذف مدل',
                'model_lists.import' => 'ورود/بارگذاری مدل‌ها',
                'model_lists.assign_codes' => 'اختصاص کد مدل‌ها',
            ],
            'واحد کالا' => [
                'units.view' => 'مشاهده واحدها',
                'units.create' => 'ایجاد واحد',
                'units.edit' => 'ویرایش واحد',
                'units.delete' => 'حذف واحد',
            ],
            'انبارها' => [
                'warehouses.view' => 'مشاهده انبارها',
                'warehouses.create' => 'ایجاد انبار',
                'warehouses.edit' => 'ویرایش انبار',
                'warehouses.delete' => 'حذف انبار',
                'warehouses.personnel.view' => 'مشاهده پرسنل انبار',
                'warehouses.personnel.manage' => 'مدیریت پرسنل انبار',
            ],
            'موجودی' => [
                'inventory.view' => 'مشاهده موجودی',
                'inventory.adjust' => 'اصلاح موجودی',
                'inventory.print' => 'چاپ موجودی',
                'inventory.export' => 'خروجی موجودی',
                'inventory.count.view' => 'مشاهده انبارگردانی',
                'inventory.count.create' => 'ایجاد سند انبارگردانی',
                'inventory.count.edit' => 'ویرایش سند انبارگردانی',
                'inventory.count.confirm' => 'نهایی‌سازی انبارگردانی',
                'inventory.count.cancel' => 'لغو انبارگردانی',
            ],
            'خرید کالا' => [
                'stock_in.view' => 'مشاهده خرید کالا',
                'stock_in.create' => 'ثبت خرید کالا',
                'stock_in.edit' => 'ویرایش خرید کالا',
                'stock_in.delete' => 'حذف خرید کالا',
                'stock_in.confirm' => 'تأیید خرید کالا',
                'stock_in.cancel' => 'لغو خرید کالا',
                'stock_in.print' => 'چاپ خرید کالا',
                'stock_in.export' => 'خروجی خرید کالا',
            ],
            'خروج کالا و حواله انبار' => [
                'stock_out.view' => 'مشاهده خروج کالا',
                'stock_out.create' => 'ثبت خروج کالا',
                'stock_out.edit' => 'ویرایش خروج کالا',
                'stock_out.delete' => 'حذف خروج کالا',
                'stock_out.confirm' => 'تأیید خروج کالا',
                'stock_out.cancel' => 'لغو خروج کالا',
                'stock_out.print' => 'چاپ خروج کالا',
                'issues.view' => 'مشاهده حواله انبار',
                'issues.create' => 'ایجاد حواله انبار',
                'issues.edit' => 'ویرایش حواله انبار',
                'issues.delete' => 'حذف حواله انبار',
                'issues.confirm' => 'تأیید حواله انبار',
                'issues.cancel' => 'لغو حواله انبار',
                'issues.print' => 'چاپ حواله انبار',
                'warehouse.collection.view' => 'مشاهده صفحه جمع‌آوری انبار',
                'warehouse.collection.queue.view' => 'مشاهده صف جمع‌آوری فاکتور',
                'warehouse.collection.receive' => 'تحویل گرفتن فاکتور برای جمع‌آوری',
                'warehouse.collection.start' => 'شروع جمع‌آوری فاکتور',
                'warehouse.collection.edit' => 'ویرایش اقلام جمع‌آوری انبار',
                'warehouse.collection.adjust_price' => 'تغییر قیمت و تخفیف در جمع‌آوری انبار',
                'warehouse.collection.submit_reapproval' => 'ثبت نهایی جمع‌آوری و ارجاع به مالی',
                'warehouse.shipping.queue.view' => 'مشاهده صف ارسال فاکتور',
                'warehouse.shipping.view' => 'مشاهده جزئیات ارسال',
                'warehouse.shipping.ship' => 'ثبت ارسال فاکتور',
            ],
            'انتقال بین انبار' => [
                'transfers.view' => 'مشاهده انتقال‌ها',
                'transfers.create' => 'ایجاد انتقال',
                'transfers.edit' => 'ویرایش انتقال',
                'transfers.delete' => 'حذف انتقال',
                'transfers.confirm' => 'تأیید انتقال',
                'transfers.cancel' => 'لغو انتقال',
                'transfers.print' => 'چاپ انتقال',
            ],
            'رسید انبار' => [
                'receipts.view' => 'مشاهده رسیدها',
                'receipts.create' => 'ایجاد رسید',
                'receipts.edit' => 'ویرایش رسید',
                'receipts.delete' => 'حذف رسید',
                'receipts.confirm' => 'تأیید رسید',
                'receipts.cancel' => 'لغو رسید',
                'receipts.print' => 'چاپ رسید',
            ],
            'نقشه انبار' => [
                'warehouse_map.view' => 'مشاهده نقشه انبار',
                'warehouse_map.locations.manage' => 'مدیریت مکان‌ها',
                'warehouse_map.assign' => 'جانمایی کالا',
                'warehouse_map.transfer' => 'جابه‌جایی مکانی',
                'warehouse_map.history' => 'مشاهده تاریخچه نقشه انبار',
            ],
            'امین اموال' => [
                'assets.view' => 'مشاهده امین اموال',
                'assets.personnel.view' => 'مشاهده پرسنل اموال',
                'assets.personnel.create' => 'ایجاد پرسنل اموال',
                'assets.personnel.edit' => 'ویرایش پرسنل اموال',
                'assets.personnel.change_status' => 'تغییر وضعیت پرسنل اموال',
                'assets.documents.view' => 'مشاهده اسناد اموال',
                'assets.documents.create' => 'ایجاد سند اموال',
                'assets.documents.edit' => 'ویرایش سند اموال',
                'assets.documents.confirm' => 'نهایی‌سازی سند اموال',
                'assets.documents.cancel' => 'لغو سند اموال',
                'assets.documents.print' => 'چاپ/دانلود سند اموال',
                'assets.codes.search' => 'جستجوی کد اموال',
            ],
            'پیش‌فاکتور و فروش' => [
                'preinvoices.create' => 'ثبت پیش‌فاکتور',
                'preinvoices.drafts.view' => 'مشاهده پیش‌نویس‌ها',
                'preinvoices.drafts.edit' => 'ویرایش پیش‌نویس',
                'preinvoices.finance.view' => 'مشاهده بررسی مالی',
                'preinvoices.finance.confirm' => 'تأیید مالی',
                'preinvoices.finance.cancel' => 'لغو مالی',
                'preinvoices.warehouse.view' => 'مشاهده صف انبار',
                'preinvoices.warehouse.edit' => 'بررسی/ویرایش انبار',
                'preinvoices.warehouse.confirm' => 'تأیید انبار',
                'preinvoices.warehouse.cancel' => 'رد انبار',
                'preinvoices.warehouse.reviews.view' => 'مشاهده سوابق تأیید انبار',
                'preinvoices.all.view' => 'مشاهده همه پیش‌فاکتورها',
                'preinvoices.own.view' => 'مشاهده پیش‌فاکتورهای خود',
                'preinvoices.print' => 'چاپ پیش‌فاکتور',
            ],
            'فاکتورها و مالی' => [
                'invoices.view' => 'مشاهده فاکتورها',
                'invoices.show' => 'مشاهده جزئیات فاکتور',
                'invoices.edit' => 'ویرایش فاکتور',
                'invoices.cancel' => 'لغو فاکتور',
                'invoices.change_status' => 'تغییر وضعیت فاکتور',
                'invoices.print' => 'چاپ فاکتور',
                'payments.view' => 'مشاهده پرداخت‌ها',
                'payments.create' => 'ثبت پرداخت',
                'notes.create' => 'ثبت یادداشت فاکتور',
                'cheques.view' => 'مشاهده چک‌ها',
                'cheques.create' => 'ثبت چک',
                'account_statements.view' => 'مشاهده گردش حساب',
                'account_statements.payments.create' => 'ثبت پرداخت در گردش حساب',
                'finance.reports.view' => 'مشاهده گزارش مالی',
            ],
            'مشتریان' => [
                'customers.view' => 'مشاهده مشتریان',
                'customers.create' => 'ایجاد مشتری',
                'customers.edit' => 'ویرایش مشتری',
                'customers.delete' => 'حذف مشتری',
                'customers.import' => 'ورود مشتریان',
                'customers.export' => 'خروجی مشتریان',
            ],
            'تأمین‌کنندگان' => [
                'suppliers.view' => 'مشاهده تأمین‌کنندگان',
                'suppliers.create' => 'ایجاد تأمین‌کننده',
                'suppliers.edit' => 'ویرایش تأمین‌کننده',
                'suppliers.delete' => 'حذف تأمین‌کننده',
                'suppliers.export' => 'خروجی تأمین‌کنندگان',
            ],
            'کاربران' => [
                'users.view' => 'مشاهده کاربران',
                'users.create' => 'ایجاد کاربر',
                'users.edit' => 'ویرایش کاربر',
                'users.delete' => 'حذف کاربر',
                'users.change_password' => 'تغییر رمز کاربر',
                'users.change_status' => 'تغییر وضعیت کاربر',
                'users.sync' => 'همگام‌سازی کاربران',
            ],
            'برگشت از فروش' => [
                'sales_returns.view' => 'مشاهده برگشت از فروش',
                'sales_returns.create' => 'ثبت برگشت از فروش',
                'sales_returns.edit_draft' => 'ویرایش پیش‌نویس برگشت از فروش',
                'sales_returns.edit_applied' => 'ویرایش سند ثبت‌نهایی‌شده برگشت از فروش',
                'sales_returns.apply' => 'ثبت نهایی برگشت از فروش',
                'sales_returns.cancel_draft' => 'لغو پیش‌نویس برگشت از فروش',
                'sales_returns.void_applied' => 'ابطال سند ثبت‌نهایی‌شده برگشت از فروش',
                'sales_returns.print' => 'چاپ سند برگشت از فروش',
                'sales_returns.export' => 'خروجی برگشت از فروش',
                'sales_returns.create_product' => 'تعریف کالا در برگشت سازه‌حساب',
                'sales_returns.override_destination' => 'تغییر انبار مقصد برگشت',
                'sales_returns.override_invoice_status' => 'انتخاب وضعیت‌های بیشتر فاکتور',
            ],
            'نقش‌ها' => [
                'roles.view' => 'مشاهده نقش‌ها',
                'roles.create' => 'ایجاد نقش',
                'roles.edit' => 'ویرایش نقش',
                'roles.delete' => 'حذف نقش',
                'roles.assign_permissions' => 'اختصاص دسترسی به نقش',
            ],
            'دسترسی‌ها' => [
                'permissions.view' => 'مشاهده دسترسی‌ها',
                'permissions.edit' => 'ویرایش دسترسی‌های کاربر',
                'permissions.sync' => 'ذخیره/همگام‌سازی دسترسی‌ها',
                'permissions.assign_roles' => 'اختصاص نقش به کاربر',
            ],
            'محتوا و تیکت‌ها' => [
                'posts.view' => 'مشاهده مطالب',
                'posts.create' => 'ایجاد مطلب',
                'posts.edit' => 'ویرایش مطلب',
                'posts.delete' => 'حذف مطلب',
                'unions.view' => 'مشاهده اتحادیه‌ها',
                'unions.create' => 'ایجاد اتحادیه',
                'unions.edit' => 'ویرایش اتحادیه',
                'unions.delete' => 'حذف اتحادیه',
                'tickets.view' => 'مشاهده تیکت‌ها',
                'tickets.reply' => 'پاسخ به تیکت',
                'tickets.close' => 'بستن تیکت',
            ],
            'گزارشات' => [
                'reports.inventory' => 'گزارش موجودی',
                'reports.stock_movement' => 'گزارش گردش کالا',
                'reports.low_stock' => 'گزارش کمبود موجودی',
                'reports.products' => 'گزارش کالاها',
                'reports.customers' => 'گزارش مشتریان',
                'reports.suppliers' => 'گزارش تأمین‌کنندگان',
                'reports.warehouse' => 'گزارش انبار',
                'reports.profit' => 'گزارش سود',
                'reports.export' => 'خروجی گزارشات',
                'reports.print' => 'چاپ گزارشات',
            ],
            'تنظیمات' => [
                'settings.view' => 'مشاهده تنظیمات',
                'settings.edit' => 'ویرایش تنظیمات',
                'settings.backup' => 'پشتیبان‌گیری',
                'settings.restore' => 'بازیابی پشتیبان',
                'shipping_methods.view' => 'مشاهده روش‌های ارسال',
                'shipping_methods.create' => 'ایجاد روش ارسال',
                'shipping_methods.edit' => 'ویرایش روش ارسال',
                'shipping_methods.delete' => 'حذف روش ارسال',
                'inventory_webhooks.view' => 'مشاهده تنظیمات API موجودی',
                'inventory_webhooks.edit' => 'ویرایش تنظیمات API موجودی',
            ],
            'لاگ‌ها' => [
                'logs.view' => 'مشاهده لاگ‌ها',
                'logs.delete' => 'حذف لاگ‌ها',
                'logs.export' => 'خروجی لاگ‌ها',
            ],
        ];
    }

    public static function sidebarPages(): array
    {
        return [
            'داشبورد' => [
                ['permission' => 'dashboard.view', 'label' => 'داشبورد'],
            ],
            'کالاهای آریا' => [
                ['permission' => 'products.create', 'label' => 'تعریف کالا'],
                ['permission' => 'stock_in.view', 'label' => 'خرید کالا'],
                ['permission' => 'products.price_changes.view', 'label' => 'تغییر قیمت کالا'],
                ['permission' => 'products.export', 'label' => 'خروجی کالا'],
                ['permission' => 'products.change_status', 'label' => 'مدیریت وضعیت فروش'],
            ],
            'خرید کالا' => [
                ['permission' => 'stock_in.view', 'label' => 'لیست خرید کالاها'],
                ['permission' => 'stock_in.create', 'label' => 'ثبت خرید کالا'],
                ['permission' => 'stock_in.edit', 'label' => 'ویرایش خرید کالا'],
                ['permission' => 'stock_in.delete', 'label' => 'حذف خرید کالا'],
                ['permission' => 'stock_in.confirm', 'label' => 'تأیید خرید کالا'],
                ['permission' => 'stock_in.cancel', 'label' => 'لغو خرید کالا'],
                ['permission' => 'stock_in.print', 'label' => 'چاپ خرید کالا'],
                ['permission' => 'stock_in.export', 'label' => 'خروجی خرید کالا'],
            ],
            'انبارداری' => [
                ['permission' => 'issues.view', 'label' => 'حواله‌های انبار'],
                ['permission' => 'warehouse.collection.queue.view', 'label' => 'صف جمع‌آوری فاکتور'],
                ['permission' => 'warehouse.shipping.queue.view', 'label' => 'صف ارسال فاکتور'],
                ['permission' => 'assets.view', 'label' => 'امین اموال'],
                ['permission' => 'warehouse_map.view', 'label' => 'نقشه انبار'],
                ['permission' => 'inventory.count.view', 'label' => 'انبارگردانی'],
            ],
            'بازرگانی و فروش' => [
                ['permission' => 'preinvoices.create', 'label' => 'ثبت پیش‌فاکتور'],
                ['permission' => 'preinvoices.own.view', 'label' => 'پیش‌فاکتورهای من'],
                ['permission' => 'customers.view', 'label' => 'اشخاص و طرف‌حساب‌ها'],
            ],
            'مالی' => [
                ['permission' => 'preinvoices.finance.view', 'label' => 'در انتظار تایید مالی'],
                ['permission' => 'account_statements.view', 'label' => 'گردش حساب اشخاص'],
                ['permission' => 'invoices.view', 'label' => 'فاکتورها'],
                ['permission' => 'cheques.view', 'label' => 'چک‌های ثبت‌شده'],
                ['permission' => 'finance.reports.view', 'label' => 'گزارش مالی'],
            ],
            'پیکربندی' => [
                ['permission' => 'categories.view', 'label' => 'دسته‌بندی کالاها'],
                ['permission' => 'model_lists.view', 'label' => 'مدل لیست کالا'],
                ['permission' => 'shipping_methods.view', 'label' => 'روش‌های ارسال بار'],
                ['permission' => 'users.view', 'label' => 'کاربران و پرسنل'],
                ['permission' => 'permissions.view', 'label' => 'مدیریت دسترسی کاربران'],
                ['permission' => 'roles.view', 'label' => 'مدیریت نقش‌ها'],
                ['permission' => 'logs.view', 'label' => 'لاگ فعالیت کاربران'],
                ['permission' => 'inventory_webhooks.view', 'label' => 'مدیریت API موجودی/قیمت'],
            ],
        ];
    }

    public static function all(): array
    {
        $permissions = [];
        foreach (self::groups() as $group => $items) {
            foreach ($items as $key => $name) {
                $permissions[] = compact('group', 'key', 'name');
            }
        }

        return $permissions;
    }

    /** Metadata used by the UI, authorization audit and role presets. */
    public static function registry(): array
    {
        $legacy = [
            'preinvoices.warehouse.view', 'preinvoices.warehouse.edit',
            'preinvoices.warehouse.confirm', 'preinvoices.warehouse.cancel',
            'preinvoices.warehouse.reviews.view',
        ];
        $critical = [
            'products.delete', 'products.price_changes.apply', 'invoices.cancel',
            'inventory.adjust', 'stock_in.delete', 'settings.restore',
            'users.change_password', 'permissions.edit', 'permissions.sync',
            'warehouse.collection.adjust_price',
        ];
        $dependencies = [
            'products.show' => ['products.view'],
            'products.edit' => ['products.view', 'products.show'],
            'products.delete' => ['products.view', 'products.show'],
            'invoices.show' => ['invoices.view'],
            'invoices.edit' => ['invoices.view', 'invoices.show'],
            'invoices.cancel' => ['invoices.view', 'invoices.show'],
            'payments.create' => ['payments.view', 'invoices.show'],
            'stock_in.edit' => ['stock_in.view'],
            'stock_in.confirm' => ['stock_in.view'],
            'sales_returns.apply' => ['sales_returns.view'],
            'inventory.count.confirm' => ['inventory.count.view'],
            'warehouse.collection.view' => ['warehouse.collection.queue.view'],
            'warehouse.collection.receive' => ['warehouse.collection.queue.view'],
            'warehouse.collection.start' => ['warehouse.collection.queue.view'],
            'warehouse.collection.edit' => ['warehouse.collection.view'],
            'warehouse.collection.submit_reapproval' => ['warehouse.collection.view'],
            'warehouse.collection.adjust_price' => ['warehouse.collection.view', 'warehouse.collection.edit'],
            'warehouse.shipping.view' => ['warehouse.shipping.queue.view'],
            'warehouse.shipping.ship' => ['warehouse.shipping.queue.view', 'warehouse.shipping.view'],
        ];
        $sidebar = collect(self::sidebarPages())->flatten(1)->pluck('permission')->all();

        return collect(self::all())->mapWithKeys(function (array $permission, int $index) use ($legacy, $critical, $dependencies, $sidebar): array {
            $key = $permission['key'];
            $action = str($key)->afterLast('.')->toString();
            $risk = in_array($key, $critical, true) ? 'critical'
                : (in_array($action, ['edit', 'create', 'confirm', 'cancel', 'delete', 'sync', 'apply'], true) ? 'sensitive' : 'normal');

            return [$key => [
                'key' => $key,
                'label' => $permission['name'],
                'description' => 'دسترسی کنترل‌شده برای '.$permission['name'],
                'module' => self::moduleFor($key),
                'module_label' => $permission['group'],
                'action' => $action,
                'risk' => $risk,
                'depends_on' => $dependencies[$key] ?? [],
                'deprecated' => in_array($key, $legacy, true),
                'active' => ! in_array($key, $legacy, true),
                'sort_order' => ($index + 1) * 10,
                'page_permission' => str_ends_with($key, '.view'),
                'sidebar' => in_array($key, $sidebar, true),
                'routes' => array_keys(self::routePermissions(), $key, true),
            ]];
        })->all();
    }

    private static function moduleFor(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'warehouse.collection') => 'warehouse_collection',
            str_starts_with($key, 'warehouse.shipping') => 'warehouse_shipping',
            str_starts_with($key, 'stock_in') => 'purchases',
            str_starts_with($key, 'inventory.count') => 'stocktake',
            str_starts_with($key, 'model_lists'), str_starts_with($key, 'brands') => 'brands_models',
            str_starts_with($key, 'invoices'), str_starts_with($key, 'payments'), str_starts_with($key, 'cheques'), str_starts_with($key, 'account_statements'), str_starts_with($key, 'finance') => 'finance',
            str_starts_with($key, 'preinvoices') => 'sales',
            str_starts_with($key, 'inventory_webhooks') => 'api_webhooks',
            default => str($key)->before('.')->toString(),
        };
    }

    public static function activeKeys(): array
    {
        return collect(self::registry())
            ->filter(fn (array $permission): bool => ($permission['active'] ?? false) === true
                                                     && ($permission['deprecated'] ?? false) === false)
            ->pluck('key')
            ->values()
            ->all();
    }

    public static function versionHash(): string
    {
        $keys = self::activeKeys();
        sort($keys, SORT_STRING);

        return hash('sha256', implode("\n", $keys));
    }

    /** @return array<int, string> */
    public static function missingActiveKeys(): array
    {
        $activeKeys = self::activeKeys();
        if ($activeKeys === []) {
            return [];
        }

        if (! Schema::hasTable('permissions')
            || ! Schema::hasColumn('permissions', 'key')
            || ! Schema::hasColumn('permissions', 'guard_name')) {
            return $activeKeys;
        }

        $existingKeys = DB::table('permissions')
            ->where('guard_name', self::guardName())
            ->whereNotNull('key')
            ->whereIn('key', $activeKeys)
            ->pluck('key')
            ->filter(fn ($key): bool => is_string($key) && $key !== '')
            ->unique()
            ->all();

        return array_values(array_diff($activeKeys, $existingKeys));
    }

    public static function activePermissionsAreSynced(): bool
    {
        return self::missingActiveKeys() === [];
    }

    public static function roleAliases(): array
    {
        return [
            'super_admin' => ['super_admin', 'super-admin', 'Super Admin', 'مدیرکل', 'Owner'],
            'system_admin' => ['admin', 'Admin', 'ادمین', 'ITManager', 'ITUser'],
            'sales_manager' => ['SaleManager', 'Manager'],
            'sales_user' => ['Sales', 'SaleUser'],
            'accountant' => ['Accountant', 'finance', 'Finance'],
            'warehouse_manager' => ['StorageManager'],
            'warehouse_operator' => ['StorageUser', 'warehouse', 'Warehouse'],
            'auditor' => ['Guest'],
        ];
    }

    public static function roleLabels(): array
    {
        return ['super_admin' => 'مدیر کل', 'system_admin' => 'مدیر سیستم', 'sales_manager' => 'مدیر فروش', 'sales_user' => 'فروشنده', 'finance_manager' => 'مدیر مالی', 'accountant' => 'حسابدار', 'warehouse_manager' => 'مدیر انبار', 'warehouse_operator' => 'کارشناس انبار', 'purchasing_user' => 'کارشناس خرید', 'auditor' => 'مشاهده‌گر / حسابرس'];
    }

    public static function canonicalRoleKey(string $roleName): ?string
    {
        if (array_key_exists($roleName, self::roleLabels())) {
            return $roleName;
        }

        $canonical = collect(self::roleAliases())->search(
            fn ($aliases): bool => in_array($roleName, (array) $aliases, true)
        );

        return is_string($canonical) ? $canonical : null;
    }

    public static function roleLabel(string $roleName): string
    {
        $canonical = self::canonicalRoleKey($roleName);

        return self::roleLabels()[$roleName]
               ?? ($canonical !== null ? (self::roleLabels()[$canonical] ?? $roleName) : $roleName);
    }

    public static function isLegacyRole(string $roleName): bool
    {
        return self::canonicalRoleKey($roleName) !== $roleName;
    }

    public static function guardName(): string
    {
        return 'web';
    }

    /** @return array{created: int, updated: int, unchanged: int} */
    public static function syncToDatabase(): array
    {
        $existing = DB::table('permissions')
            ->whereNotNull('key')
            ->get(['key', 'name', 'group', 'guard_name'])
            ->keyBy('key');
        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach (self::all() as $permission) {
            $current = $existing->get($permission['key']);
            $values = [
                'name' => $permission['name'],
                'group' => $permission['group'],
                'guard_name' => self::guardName(),
            ];

            if ($current === null) {
                DB::table('permissions')->insert($values + [
                    'key' => $permission['key'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $result['created']++;

                continue;
            }

            $changed = collect($values)->contains(
                fn ($value, string $column): bool => (string) $current->{$column} !== (string) $value
            );

            if (! $changed) {
                $result['unchanged']++;

                continue;
            }

            DB::table('permissions')->where('key', $permission['key'])->update($values + ['updated_at' => now()]);
            $result['updated']++;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $result;
    }

    public static function permissionAliases(): array
    {
        return [
            'inventory.view' => ['warehouses.view', 'inventory.count.view'],
            'stock.in' => ['stock_in.view', 'stock_in.create'],
            'stock.out' => ['stock_out.view'],
            'customers.manage' => ['customers.view', 'customers.create', 'customers.edit'],
            'suppliers.manage' => ['suppliers.view', 'suppliers.create', 'suppliers.edit'],
            'export_products' => ['products.export'],
        ];
    }

    public static function userHasPermission($user, string $permission): bool
    {
        if ($user === null) {
            return false;
        }

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(self::superAdminRoles())) {
            return true;
        }

        if (str_starts_with($permission, 'page.')) {
            return $user instanceof User
                   && PageAccessCatalog::userCan($user, $permission);
        }

        $pagePermission = PageAccessCatalog::pagePermissionForLegacy($permission);
        if ($pagePermission && $user instanceof User) {
            return PageAccessCatalog::userCan($user, $pagePermission);
        }

        if ($permission === '*') {
            return method_exists($user, 'hasAnyRole') && $user->hasAnyRole(self::administratorRoles());
        }

        if (str_starts_with($permission, 'products.price_changes.')
            && method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(self::priceChangeRoles())) {
            return true;
        }

        if (method_exists($user, 'hasPermission') && $user->hasPermission($permission)) {
            return true;
        }

        foreach (self::permissionAliases()[$permission] ?? [] as $alias) {
            if (method_exists($user, 'hasPermission') && $user->hasPermission($alias)) {
                return true;
            }
        }

        foreach (self::permissionAliases() as $aliasPermission => $aliases) {
            if (in_array($permission, $aliases, true)
                && method_exists($user, 'hasPermission')
                && $user->hasPermission($aliasPermission)) {
                return true;
            }
        }

        return false;
    }

    public static function routePermissions(): array
    {
        return [
            'admin.product-exports.print' => 'products.export',
            'admin.product-exports.download' => 'products.export',
            'admin.product-exports.model-lists' => 'products.export',
            'admin.product-exports.products.search' => 'products.export',
            'admin.product-exports.categories.children' => 'products.export',
            'vouchers.sales.products.categories' => 'warehouse.collection.view',
            'vouchers.sales.products.by-category' => 'warehouse.collection.view',
            'vouchers.sales.products.variants' => 'warehouse.collection.view',
            'finance.invoices.reapprove' => 'invoices.change_status',
            'finance.invoices.return-to-sales' => 'invoices.change_status',
            'vouchers.return-from-sale.categories.index' => 'sales_returns.create',
            'vouchers.return-from-sale.categories.products' => 'sales_returns.create',
            'vouchers.return-from-sale.legacy.print' => 'sales_returns.print',
            'stock-count-documents.subcategories' => 'inventory.count.view',
            'stock-count-documents.products' => 'inventory.count.view',
            'stock-count-documents.variants' => 'inventory.count.view',
            'preinvoice.autosave' => 'preinvoices.drafts.edit',
            'preinvoice.autosave.latest' => 'preinvoices.drafts.edit',
            'preinvoice.autosave.discard' => 'preinvoices.drafts.edit',
            'preinvoice.reservations.heartbeat' => 'preinvoices.create',
            'preinvoice.reservations.release-token' => 'preinvoices.create',
            'preinvoice.draft.return' => 'preinvoices.finance.confirm',
            'preinvoice.api.products' => 'preinvoices.create',
            'preinvoice.api.product' => 'preinvoices.create',
            'preinvoice.api.area' => 'preinvoices.create',
            'archive.index' => 'invoices.view',
            'invoices.cancelled' => 'invoices.view',
            'invoices.history' => 'invoices.view',
            'invoices.cancel.undo' => 'invoices.change_status',
            'admin.bug-investigator.index' => 'logs.view',
            'admin.bug-investigator.create' => 'logs.view',
            'admin.bug-investigator.store' => 'logs.view',
            'admin.bug-investigator.rerun' => 'logs.view',
            'admin.bug-investigator.show' => 'logs.view',
            'admin.permissions.index' => 'permissions.view',
            'admin.permissions.update' => 'permissions.edit',
            'vouchers.sales.queue' => 'warehouse.collection.queue.view',
            'vouchers.sales.queue.data' => 'warehouse.collection.queue.view',
            'vouchers.sales.queue.receive' => 'warehouse.collection.receive',
            'vouchers.sales.queue.start-collection' => 'warehouse.collection.start',
            'vouchers.sales.queue.complete-collection' => 'warehouse.collection.submit_reapproval',
            'vouchers.sales.queue.items' => 'warehouse.collection.edit',
            'vouchers.sales.collection.edit' => 'warehouse.collection.edit',
            'vouchers.sales.collection.update' => 'warehouse.collection.edit',
            'vouchers.sales.shipped' => 'warehouse.shipping.queue.view',
            'warehouse.shipping.index' => 'warehouse.shipping.queue.view',
            'warehouse.shipping.ship' => 'warehouse.shipping.ship',
            'sales-returns.index' => 'sales_returns.view',
            'sales-returns.create' => 'sales_returns.create',
            'sales-returns.store' => 'sales_returns.create',
            'sales-returns.customers.search' => 'sales_returns.create',
            'sales-returns.customers.invoices' => 'sales_returns.create',
            'sales-returns.invoices.items' => 'sales_returns.create',
            'sales-returns.products.search' => 'sales_returns.create',
            'sales-returns.products.variants' => 'sales_returns.create',
            'sales-returns.preview' => 'sales_returns.create',
            'sales-returns.export.excel' => 'sales_returns.export',
            'sales-returns.export.pdf' => 'sales_returns.export',
            'sales-returns.print-report' => 'sales_returns.print',
            'sales-returns.show' => 'sales_returns.view',
            'sales-returns.edit' => 'sales_returns.edit_draft',
            'sales-returns.update' => 'sales_returns.edit_draft',
            'sales-returns.apply' => 'sales_returns.apply',
            'sales-returns.cancel' => 'sales_returns.cancel_draft',
            'sales-returns.print' => 'sales_returns.print',
            'sales-returns.pdf' => 'sales_returns.print',
            'vouchers.return-from-sale.index' => 'sales_returns.view',
            'vouchers.return-from-sale.data' => 'sales_returns.view',
            'vouchers.return-from-sale.create' => 'sales_returns.create',
            'vouchers.return-from-sale.store' => 'sales_returns.create',
            'vouchers.return-from-sale.customers.search' => 'sales_returns.create',
            'vouchers.return-from-sale.customers.invoices' => 'sales_returns.create',
            'vouchers.return-from-sale.invoices.items' => 'sales_returns.create',
            'vouchers.return-from-sale.products.search' => 'sales_returns.create',
            'vouchers.return-from-sale.products.variants' => 'sales_returns.create',
            'vouchers.return-from-sale.preview' => 'sales_returns.create',
            'vouchers.return-from-sale.export.excel' => 'sales_returns.export',
            'vouchers.return-from-sale.export.pdf' => 'sales_returns.export',
            'vouchers.return-from-sale.print-report' => 'sales_returns.print',
            'vouchers.return-from-sale.print.customers' => 'sales_returns.print',
            'vouchers.return-from-sale.print.products' => 'sales_returns.print',
            'vouchers.return-from-sale.show' => 'sales_returns.view',
            'vouchers.return-from-sale.edit' => 'sales_returns.edit_draft',
            'vouchers.return-from-sale.update' => 'sales_returns.edit_draft',
            'vouchers.return-from-sale.applied.edit' => 'sales_returns.edit_applied',
            'vouchers.return-from-sale.applied.update' => 'sales_returns.edit_applied',
            'vouchers.return-from-sale.applied.void' => 'sales_returns.void_applied',
            'vouchers.return-from-sale.apply' => 'sales_returns.apply',
            'vouchers.return-from-sale.cancel' => 'sales_returns.cancel_draft',
            'vouchers.return-from-sale.print' => 'sales_returns.print',
            'vouchers.return-from-sale.pdf' => 'sales_returns.print',
            'dashboard' => 'dashboard.view',
            'dashboard.monthly-report' => 'dashboard.view',
            'global-search' => 'dashboard.search',
            'notifications.index' => 'notifications.view',
            'notifications.latest' => 'notifications.view',
            'notifications.unread-count' => 'notifications.view',
            'notifications.open' => 'notifications.view',
            'notifications.read' => 'notifications.manage',
            'notifications.read-all' => 'notifications.manage',
            'products.index' => 'products.view',
            'products.data' => 'products.view',
            'products.variants' => 'products.view',
            'products.create' => 'products.create',
            'products.store' => 'products.create',
            'products.edit' => 'products.edit',
            'products.update' => 'products.edit',
            'products.destroy' => 'products.delete',
            'products.warehouse-stock' => 'inventory.view',
            'products.image' => 'products.view',
            'products.sales-ledger' => 'products.ledger',
            'products.purchase-ledger' => 'products.ledger',
            'products.pricelist' => 'products.print',
            'products.sync.crm' => 'products.import',
            'products.price-changes.index' => 'products.price_changes.view',
            'products.price-changes.create' => 'products.price_changes.create',
            'products.price-changes.products.search' => 'products.price_changes.create',
            'products.price-changes.products.variants' => 'products.price_changes.create',
            'products.price-changes.categories.root' => 'products.price_changes.create',
            'products.price-changes.categories.children' => 'products.price_changes.create',
            'products.price-changes.scope-summary' => 'products.price_changes.create',
            'products.price-changes.preview' => 'products.price_changes.create',
            'products.price-changes.store' => 'products.price_changes.create',
            'products.price-changes.show' => 'products.price_changes.view',
            'products.price-changes.apply' => 'products.price_changes.apply',
            'products.price-changes.cancel' => 'products.price_changes.cancel',
            'admin.product-exports.index' => 'products.export',
            'admin.product-exports.data' => 'products.export',
            'admin.product-exports.export' => 'products.export',
            'categories.index' => 'categories.view',
            'categories.create' => 'categories.create',
            'categories.store' => 'categories.create',
            'categories.edit' => 'categories.edit',
            'categories.update' => 'categories.edit',
            'categories.destroy' => 'categories.delete',
            'categories.fixCodes' => 'categories.edit',
            'categories.quickStore' => 'categories.create',
            'product-deactivation-documents.index' => 'products.change_status',
            'product-deactivation-documents.create' => 'products.change_status',
            'product-deactivation-documents.products.search' => 'products.change_status',
            'product-deactivation-documents.products.variants' => 'products.change_status',
            'product-deactivation-documents.bulk.create' => 'products.change_status',
            'product-deactivation-documents.bulk.categories.children' => 'products.change_status',
            'product-deactivation-documents.bulk.preview' => 'products.change_status',
            'product-deactivation-documents.bulk.store' => 'products.change_status',
            'product-deactivation-documents.store' => 'products.change_status',
            'product-deactivation-documents.show' => 'products.change_status',
            'model-lists.index' => 'model_lists.view',
            'model-lists.store' => 'model_lists.create',
            'model-lists.update' => 'model_lists.edit',
            'model-lists.destroy' => 'model_lists.delete',
            'model-lists.assign-codes' => 'model_lists.assign_codes',
            'model-lists.import-from-products' => 'model_lists.import',
            'model-lists.import-phone-catalog' => 'model_lists.import',
            'model-lists.quick-store' => 'model_lists.create',
            'shipping-methods.index' => 'shipping_methods.view',
            'shipping-methods.store' => 'shipping_methods.create',
            'shipping-methods.update' => 'shipping_methods.edit',
            'shipping-methods.destroy' => 'shipping_methods.delete',
            'inventory-webhooks.index' => 'inventory_webhooks.view',
            'inventory-webhooks.update' => 'inventory_webhooks.edit',
            'movements.create' => 'inventory.adjust',
            'movements.store' => 'inventory.adjust',
            'movements.index' => 'reports.stock_movement',
            'vouchers.index' => 'issues.view',
            'vouchers.sales.index' => 'issues.view',
            'vouchers.sales.edit' => 'warehouse.collection.view',
            'vouchers.sales.show' => 'issues.view',
            'vouchers.sales.history' => 'issues.view',
            'vouchers.sales.update' => 'issues.edit',
            'vouchers.sales.status' => 'issues.confirm',
            'vouchers.sales.print' => 'issues.print',
            'vouchers.section.index' => 'issues.view',
            'vouchers.section.create' => 'issues.create',
            'vouchers.section.store' => 'issues.create',
            'vouchers.create' => 'issues.create',
            'vouchers.store' => 'issues.create',
            'vouchers.invoice.products' => 'issues.view',
            'vouchers.sale-delivery.index' => 'stock_out.view',
            'vouchers.sale-delivery.edit' => 'stock_out.edit',
            'vouchers.sale-delivery.update' => 'stock_out.edit',
            'vouchers.return.customer.invoices' => 'issues.view',
            'vouchers.return.customer-invoices' => 'issues.view',
            'vouchers.show' => 'issues.view',
            'vouchers.edit' => 'issues.edit',
            'vouchers.update' => 'issues.edit',
            'vouchers.destroy' => 'issues.delete',
            'warehouse.outputs' => 'stock_out.view',
            'asset.hub' => 'assets.view',
            'asset.personnel.index' => 'assets.personnel.view',
            'asset.personnel.create' => 'assets.personnel.create',
            'asset.personnel.store' => 'assets.personnel.create',
            'asset.personnel.show' => 'assets.personnel.view',
            'asset.personnel.edit' => 'assets.personnel.edit',
            'asset.personnel.update' => 'assets.personnel.edit',
            'asset.personnel.toggle-status' => 'assets.personnel.change_status',
            'asset.documents.index' => 'assets.documents.view',
            'asset.documents.create' => 'assets.documents.create',
            'asset.documents.store' => 'assets.documents.create',
            'asset.documents.show' => 'assets.documents.view',
            'asset.documents.view' => 'assets.documents.view',
            'asset.documents.print' => 'assets.documents.print',
            'asset.documents.signed-form.view' => 'assets.documents.print',
            'asset.documents.signed-form.download' => 'assets.documents.print',
            'asset.documents.edit' => 'assets.documents.edit',
            'asset.documents.update' => 'assets.documents.edit',
            'asset.documents.finalize' => 'assets.documents.confirm',
            'asset.documents.cancel' => 'assets.documents.cancel',
            'asset.codes.search' => 'assets.codes.search',
            'asset.codes.find' => 'assets.codes.search',
            'sales-havaleh.create-from-financial' => 'issues.create',
            'sales-havaleh.show' => 'issues.view',
            'sales-havaleh.view' => 'issues.view',
            'sales-havaleh.update' => 'issues.edit',
            'sales-havaleh.status' => 'issues.confirm',
            'sales-havaleh.history' => 'issues.view',
            'warehouse-map.index' => 'warehouse_map.view',
            'warehouse-map.locations.show' => 'warehouse_map.view',
            'warehouse-map.categories.children' => 'warehouse_map.view',
            'warehouse-map.categories.products' => 'warehouse_map.view',
            'warehouse-map.products.variants' => 'warehouse_map.view',
            'warehouse-map.history' => 'warehouse_map.history',
            'warehouse-map.locations.store' => 'warehouse_map.locations.manage',
            'warehouse-map.locations.update' => 'warehouse_map.locations.manage',
            'warehouse-map.assign' => 'warehouse_map.assign',
            'warehouse-map.transfer' => 'warehouse_map.transfer',
            'warehouses.index' => 'warehouses.view',
            'warehouses.edit' => 'warehouses.edit',
            'warehouses.update' => 'warehouses.edit',
            'warehouses.destroy' => 'warehouses.delete',
            'warehouses.personnel.index' => 'warehouses.personnel.view',
            'warehouses.personnel.store' => 'warehouses.personnel.manage',
            'warehouses.personnel.show' => 'warehouses.personnel.view',
            'purchases.index' => 'stock_in.view',
            'purchases.export' => 'stock_in.export',
            'purchases.create' => 'stock_in.create',
            'purchases.products.variants' => 'stock_in.create',
            'purchases.store' => 'stock_in.create',
            'purchases.show' => 'stock_in.view',
            'purchases.edit' => 'stock_in.edit',
            'purchases.update' => 'stock_in.edit',
            'purchases.destroy' => 'stock_in.delete',
            'persons.index' => 'customers.view',
            'persons.store' => 'customers.create',
            'persons.update' => 'customers.edit',
            'suppliers.index' => 'suppliers.view',
            'suppliers.store' => 'suppliers.create',
            'stocktake.index' => 'inventory.count.view',
            'stock-count-documents.index' => 'inventory.count.view',
            'stock-count-documents.create' => 'inventory.count.create',
            'stock-count-documents.store' => 'inventory.count.create',
            'stock-count-documents.show' => 'inventory.count.view',
            'stock-count-documents.view' => 'inventory.count.view',
            'stock-count-documents.edit' => 'inventory.count.edit',
            'stock-count-documents.update' => 'inventory.count.edit',
            'stock-count-documents.finalize' => 'inventory.count.confirm',
            'stock-count-documents.cancel' => 'inventory.count.cancel',
            'stock-count-documents.system-quantity' => 'inventory.view',
            'preinvoice.create' => 'preinvoices.create',
            'preinvoice.draft.save' => 'preinvoices.drafts.edit',
            'warehouse.reviews.index' => 'preinvoices.warehouse.reviews.view',
            'warehouse.reviews.show' => 'preinvoices.warehouse.reviews.view',
            'warehouse.reviews.print' => 'preinvoices.warehouse.reviews.view',
            'preinvoice.warehouse.index' => 'preinvoices.warehouse.view',
            'preinvoice.warehouse.review' => 'preinvoices.warehouse.edit',
            'preinvoice.warehouse.save' => 'preinvoices.warehouse.edit',
            'preinvoice.warehouse.approve' => 'preinvoices.warehouse.confirm',
            'preinvoice.warehouse.reject' => 'preinvoices.warehouse.cancel',
            'preinvoice.draft.index' => 'preinvoices.drafts.view',
            'preinvoice.draft.edit' => 'preinvoices.own.view',
            'preinvoice.draft.update' => 'preinvoices.own.view',
            'preinvoice.draft.finance' => 'preinvoices.finance.view',
            'preinvoice.draft.finance.edit' => 'preinvoices.finance.view',
            'preinvoice.draft.finance.update' => 'preinvoices.finance.confirm',
            'preinvoice.draft.finance.save-and-finalize' => 'preinvoices.finance.confirm',
            'preinvoice.draft.finalize' => 'preinvoices.finance.confirm',
            'preinvoice.draft.cancel' => 'preinvoices.finance.cancel',
            'preinvoice.all.index' => 'preinvoices.all.view',
            'preinvoice.my.index' => 'preinvoices.own.view',
            'preinvoice.my.show' => 'preinvoices.own.view',
            'preinvoice.print' => 'preinvoices.print',
            'api.customers.search' => 'customers.view',
            'api.customers.store' => 'customers.create',
            'api.customers.show' => 'customers.view',
            'preinvoice.api.product-finder' => 'preinvoices.create',
            'preinvoice.api.product-finder.categories' => 'preinvoices.create',
            'preinvoice.api.reservations.sync' => 'preinvoices.create',
            'preinvoice.api.reservations.release' => 'preinvoices.create',
            'customers.index' => 'customers.view',
            'customers.store' => 'customers.create',
            'customers.update' => 'customers.edit',
            'customers.destroy' => 'customers.delete',
            'customers.import' => 'customers.import',
            'archive.preinvoices.show' => 'preinvoices.print',
            'archive.invoices.show' => 'invoices.print',
            'invoices.index' => 'invoices.view',
            'invoices.data' => 'invoices.view',
            'invoices.customers.search' => 'invoices.view',
            'invoices.print' => 'invoices.print',
            'invoices.edit' => 'invoices.edit',
            'invoices.update' => 'invoices.edit',
            'invoices.reassign-seller' => 'invoices.edit',
            'invoices.bulk.reassign-seller' => 'invoices.edit',
            'invoices.show' => 'invoices.show',
            'invoices.status' => 'invoices.change_status',
            'invoices.cancel' => 'invoices.cancel',
            'invoices.payments.store' => 'payments.create',
            'invoices.notes.store' => 'notes.create',
            'cheques.store' => 'cheques.create',
            'finance.cheques.registered' => 'cheques.view',
            'finance.cheques.index' => 'cheques.view',
            'finance.reports.index' => 'finance.reports.view',
            'finance.reports.sales-visitors' => 'finance.reports.view',
            'finance.reports.sales-visitors.commission-batches.store' => 'finance.reports.view',
            'finance.reports.sales-visitors.commission-batches.show' => 'finance.reports.view',
            'finance.reports.sales-visitors.commission-batches.export' => 'finance.reports.view',
            'finance.reports.sales-visitors.commission-batches.print' => 'finance.reports.view',
            'payments.view' => 'payments.view',
            'account-statements.index' => 'account_statements.view',
            'account-statements.payments.store' => 'account_statements.payments.create',
            'account-statements.documents.invoices.show' => 'account_statements.view',
            'account-statements.documents.returns.show' => 'account_statements.view',
            'account-statements.documents.payments.show' => 'account_statements.view',
            'account-statements.show' => 'account_statements.view',
            'activity-logs.index' => 'logs.view',
            'users.index' => 'users.view',
            'users.sync' => 'users.sync',
            'admin.roles.index' => 'roles.view',
            'admin.roles.create' => 'roles.create',
            'admin.roles.store' => 'roles.create',
            'admin.roles.edit' => 'roles.edit',
            'admin.roles.update' => 'roles.edit',
            'admin.roles.destroy' => 'roles.delete',
        ];
    }
}
