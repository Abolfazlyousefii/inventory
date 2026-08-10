<?php

namespace App\Support;

use App\Models\User;

class PageAccessCatalog
{
    /** @return array<string, array<string, mixed>> */
    public static function pages(): array
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $definitions = [
            'warehouse.reservations' => ['انبارداری', 'رزرو موجودی', ['warehouse.reservations']],
            'dashboard' => ['داشبورد', 'داشبورد', ['dashboard', 'notifications']],
            'products' => ['کالاهای آریا', 'کالاها', ['products']],
            'products.price_changes' => ['کالاهای آریا', 'تغییر قیمت کالا', ['products.price_changes']],
            'categories' => ['پیکربندی', 'دسته‌بندی کالا', ['categories']],
            'brands_models' => ['پیکربندی', 'برندها و مدل‌ها', ['brands', 'model_lists']],
            'units' => ['پیکربندی', 'واحدهای کالا', ['units']],
            'shipping_methods' => ['پیکربندی', 'روش‌های ارسال', ['shipping_methods']],
            'warehouses' => ['انبارداری', 'انبارها و پرسنل انبار', ['warehouses']],
            'warehouse.stocks' => ['انبارداری', 'موجودی انبار', ['inventory']],
            'warehouse.stocktake' => ['انبارداری', 'انبارگردانی', ['inventory.count']],
            'warehouse.purchases' => ['انبارداری', 'خرید کالا', ['stock_in']],
            'warehouse.issues' => ['انبارداری', 'خروج کالا و حواله', ['stock_out', 'issues']],
            'warehouse.collection' => ['انبارداری', 'جمع‌آوری سفارش', ['warehouse.collection']],
            'warehouse.shipping' => ['انبارداری', 'ارسال سفارش', ['warehouse.shipping']],
            'warehouse.transfers' => ['انبارداری', 'انتقال بین انبار', ['transfers']],
            'warehouse.receipts' => ['انبارداری', 'رسید انبار', ['receipts']],
            'warehouse.map' => ['انبارداری', 'نقشه انبار', ['warehouse_map']],
            'assets' => ['انبارداری', 'امین اموال', ['assets']],
            'sales.preinvoices' => ['بازرگانی و فروش', 'ثبت و مدیریت پیش‌فاکتورها', ['preinvoices']],
            'sales.preinvoice_warehouse_review' => ['بازرگانی و فروش', 'بررسی انبار پیش‌فاکتور', ['preinvoices.warehouse']],
            'sales.preinvoice_finance_review' => ['مالی', 'تأیید مالی پیش‌فاکتور', ['preinvoices.finance']],
            'sales.invoices' => ['بازرگانی و فروش', 'فاکتورهای فروش', ['invoices', 'notes']],
            'sales.returns' => ['بازرگانی و فروش', 'برگشت از فروش', ['sales_returns']],
            'customers' => ['بازرگانی و فروش', 'مشتریان', ['customers']],
            'suppliers' => ['بازرگانی و فروش', 'تأمین‌کنندگان', ['suppliers']],
            'finance.payments' => ['مالی', 'پرداخت‌ها و چک‌ها', ['payments', 'cheques']],
            'finance.accounts' => ['مالی', 'گردش حساب و گزارش مالی', ['account_statements', 'finance.reports']],
            'finance.seller_sales_documents' => ['مالی', 'گزارش پورسانت فروشندگان', ['seller_sales_documents']],
            'reports' => ['گزارش‌ها', 'گزارش‌های مدیریتی', ['reports']],
            'tickets' => ['مدیریت سیستم', 'تیکت‌ها', ['tickets']],
            'users' => ['مدیریت سیستم', 'کاربران', ['users']],
            'roles' => ['مدیریت سیستم', 'نقش‌ها و دسترسی صفحات', ['roles', 'permissions']],
            'settings' => ['مدیریت سیستم', 'تنظیمات سیستم', ['settings']],
            'activity_logs' => ['مدیریت سیستم', 'لاگ فعالیت‌ها', ['logs']],
            'integrations.inventory' => ['یکپارچه‌سازی‌ها', 'همگام‌سازی موجودی', ['inventory_webhooks']],
        ];

        $routes = PermissionCatalog::routePermissions();
        $pages = [];
        foreach ($definitions as $key => $definition) {
            [$group, $label, $legacyPrefixes] = $definition;
            $permission = 'page.'.$key;
            $legacy = collect(PermissionCatalog::registry())->keys()
                ->filter(fn (string $name) => self::matchesAnyPrefix($name, $legacyPrefixes))
                ->values()->all();
            $pageRoutes = collect($routes)
                ->filter(fn (string $legacyPermission) => in_array($legacyPermission, $legacy, true))
                ->keys()->values()->all();
            if ($key === 'finance.seller_sales_documents') {
                $pageRoutes = ['finance.seller-sales.index','finance.seller-sales.create','finance.seller-sales.available-invoices','finance.seller-sales.store','finance.seller-sales.show','finance.seller-sales.edit','finance.seller-sales.update','finance.seller-sales.print'];
            }
            $preferredRoute = self::preferredLandingRoute($key);
            if ($preferredRoute !== null && in_array($preferredRoute, $pageRoutes, true)) {
                $pageRoutes = array_values(array_unique([$preferredRoute, ...$pageRoutes]));
            }
            $pages[$key] = [
                'key' => $key,
                'permission' => $permission,
                'label' => $label,
                'group' => $group,
                'description' => 'دسترسی کامل به صفحه یا فرآیند «'.$label.'» و عملیات وابسته آن',
                'routes' => $pageRoutes,
                'legacy_permissions' => $legacy,
                'order' => (array_search($key, array_keys($definitions), true) + 1) * 10,
                'sensitive' => in_array($key, ['users', 'roles', 'settings', 'activity_logs', 'integrations.inventory', 'sales.preinvoice_finance_review', 'sales.preinvoice_warehouse_review', 'finance.accounts', 'finance.seller_sales_documents', 'finance.payments', 'warehouse.stocktake'], true),
                // Migration is intentionally stricter than runtime route compatibility:
                // action-only permissions never imply entry to a whole page.
                'migration_grant_permissions' => self::migrationGrantPermissions($key, $legacy),
                'system_only' => false,
            ];
        }

        return $cached = $pages;
    }

    public static function permissionForRoute(?string $routeName): ?string
    {
        if (! $routeName) return null;
        $legacy = PermissionCatalog::routePermissions()[$routeName] ?? null;
        if ($legacy) return self::pagePermissionForLegacy($legacy);
        foreach (self::pages() as $page) {
            if (in_array($routeName, $page['routes'], true)) return $page['permission'];
        }
        return null;
    }

    public static function pagePermissionForLegacy(string $legacyPermission): ?string
    {
        $explicit = self::legacyMigrationDispositions()[$legacyPermission]['page_permission'] ?? null;
        if ($explicit) return $explicit;
        if (str_starts_with($legacyPermission, 'products.price_changes.')) return 'page.products.price_changes';
        if (str_starts_with($legacyPermission, 'preinvoices.finance.')) return 'page.sales.preinvoice_finance_review';
        if (str_starts_with($legacyPermission, 'preinvoices.warehouse.')) return 'page.sales.preinvoice_warehouse_review';
        foreach (self::pages() as $page) {
            if (in_array($legacyPermission, $page['legacy_permissions'], true)) return $page['permission'];
        }
        return null;
    }

    public static function legacyMigrationDispositions(): array
    {
        $map = [
            'account_statements.adjust'=>'page.finance.accounts', 'finance.reports.view'=>'page.finance.accounts',
            'permissions.assign_roles'=>'page.roles',
            'products.price_changes.apply'=>'page.products.price_changes', 'products.price_changes.cancel'=>'page.products.price_changes', 'products.price_changes.create'=>'page.products.price_changes',
            'sales_returns.edit_applied'=>'page.sales.returns', 'sales_returns.void_applied'=>'page.sales.returns',
            'warehouse.collection.adjust_price'=>'page.warehouse.collection', 'warehouse.collection.edit'=>'page.warehouse.collection', 'warehouse.collection.queue.view'=>'page.warehouse.collection', 'warehouse.collection.receive'=>'page.warehouse.collection', 'warehouse.collection.start'=>'page.warehouse.collection', 'warehouse.collection.submit_reapproval'=>'page.warehouse.collection', 'warehouse.collection.view'=>'page.warehouse.collection',
            'warehouse.reservations.release'=>'page.warehouse.reservations', 'warehouse.reservations.view'=>'page.warehouse.reservations',
            'warehouse.shipping.queue.view'=>'page.warehouse.shipping', 'warehouse.shipping.ship'=>'page.warehouse.shipping', 'warehouse.shipping.view'=>'page.warehouse.shipping',
        ];
        $result = collect($map)->map(fn ($page) => ['status'=>'mapped', 'page_permission'=>$page])->all();
        foreach (['posts.create','posts.delete','posts.edit','posts.view','unions.create','unions.delete','unions.edit','unions.view'] as $permission) {
            $result[$permission] = ['status'=>'no_real_page', 'page_permission'=>null];
        }
        return $result;
    }

    public static function migrationDecision(string $legacyPermission): ?array
    {
        $pagePermission = self::pagePermissionForLegacy($legacyPermission);
        if ($pagePermission === null) return null;
        $page = collect(self::pages())->firstWhere('permission', $pagePermission);
        $sensitive = (bool) ($page['sensitive'] ?? false);
        $explicit = in_array($legacyPermission, $page['migration_grant_permissions'] ?? [], true);
        return ['page_permission'=>$pagePermission, 'decision'=>$sensitive ? 'review_required' : ($explicit ? 'grant' : 'ambiguous'), 'risk'=>$sensitive ? 'high' : ($explicit ? 'low' : 'medium')];
    }

    public static function page(string $key): ?array
    {
        return self::pages()[$key] ?? null;
    }

    public static function userCan(User $user, string $pagePermission): bool
    {
        if ($user->isSuperAdmin()) return true;
        $page = collect(self::pages())->firstWhere('permission', $pagePermission);
        if (! $page) return false;
        $effective = $user->getAllPermissions()->pluck('key')->filter()->all();
        return in_array($pagePermission, $effective, true);
    }

    public static function permissions(): array
    {
        return collect(self::pages())->pluck('permission')->values()->all();
    }

    private static function matchesAnyPrefix(string $permission, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($permission === $prefix || str_starts_with($permission, $prefix.'.')) return true;
        }
        return false;
    }

    private static function migrationGrantPermissions(string $key, array $legacy): array
    {
        $explicit = [
            'users' => ['users.view'],
            'roles' => ['roles.view', 'permissions.view'],
            'settings' => ['settings.view'],
            'activity_logs' => ['logs.view'],
            'integrations.inventory' => ['inventory_webhooks.view'],
            'sales.preinvoice_finance_review' => ['preinvoices.finance.view'],
            'sales.preinvoice_warehouse_review' => ['preinvoices.warehouse.view', 'preinvoices.warehouse.reviews.view'],
            'finance.accounts' => ['account_statements.view', 'finance.reports.view'],
            'finance.payments' => ['payments.view', 'cheques.view'],
            'warehouse.stocktake' => ['inventory.count.view'],
        ];
        if (isset($explicit[$key])) return array_values(array_intersect($legacy, $explicit[$key]));

        return collect($legacy)->filter(fn (string $permission) =>
            str_ends_with($permission, '.view')
            || str_ends_with($permission, '.index')
            || preg_match('/(^|\.)(manage|reports)$/', $permission) === 1
            || in_array($permission, ['dashboard', 'products', 'customers.manage', 'suppliers.manage', 'inventory', 'stock_in', 'stock_out'], true)
        )->values()->all();
    }

    private static function preferredLandingRoute(string $key): ?string
    {
        return [
            'dashboard'=>'dashboard', 'products'=>'products.index', 'products.price_changes'=>'products.price-changes.index',
            'categories'=>'categories.index', 'brands_models'=>'model-lists.index', 'shipping_methods'=>'shipping-methods.index',
            'warehouses'=>'warehouses.index', 'warehouse.stocks'=>'products.index', 'warehouse.stocktake'=>'stock-count-documents.index',
            'warehouse.purchases'=>'purchases.index', 'warehouse.issues'=>'vouchers.index', 'warehouse.collection'=>'vouchers.sales.queue',
            'warehouse.shipping'=>'warehouse.shipping.index', 'warehouse.map'=>'warehouse-map.index', 'assets'=>'asset.hub',
            'sales.preinvoices'=>'preinvoice.create', 'sales.preinvoice_warehouse_review'=>'warehouse.reviews.index',
            'sales.preinvoice_finance_review'=>'preinvoice.draft.finance', 'sales.invoices'=>'invoices.index',
            'sales.returns'=>'vouchers.return-from-sale.index', 'customers'=>'customers.index', 'suppliers'=>'suppliers.index',
            'finance.payments'=>'finance.cheques.index', 'finance.accounts'=>'account-statements.index', 'finance.seller_sales_documents'=>'finance.seller-sales.index', 'reports'=>'finance.reports.index',
            'users'=>'users.index', 'roles'=>'admin.roles.index', 'activity_logs'=>'activity-logs.index',
            'integrations.inventory'=>'inventory-webhooks.index',
        ][$key] ?? null;
    }
}
