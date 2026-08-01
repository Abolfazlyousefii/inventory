@php
    $currentRouteName = request()->route()?->getName() ?? '';

    $isRoute = static fn(string ...$patterns): bool => Str::is($patterns, $currentRouteName);
    $is = static fn(string ...$patterns): string => $isRoute(...$patterns) ? 'active' : '';
    $isPath = static fn(string ...$patterns): bool => request()->is(...$patterns);
    $pathActive = static fn(string ...$patterns): string => $isPath(...$patterns) ? 'active' : '';

    $productsActive = $isRoute('products.index', 'products.create', 'products.price-changes.*', 'purchases.*', 'admin.product-exports.*', 'product-deactivation-documents.*')
        || $isPath('products', 'products/create', 'products/price-changes', 'products/price-changes/*', 'purchases', 'purchases/*', 'admin/product-exports', 'admin/product-exports/*', 'product-deactivation-documents', 'product-deactivation-documents/*');

    $isSalesReturnRoute = $isRoute('vouchers.return-from-sale.*') || $isPath('vouchers/section/return-from-sale', 'vouchers/section/return-from-sale/*');

    $warehouseActive = ! $isSalesReturnRoute && (
        $isRoute('vouchers.*', 'sales-returns.*', 'warehouse.shipping.*', 'stocktake.*', 'stocktake.index', 'asset.*', 'warehouse-map.*')
        || $isPath('vouchers', 'vouchers/*', 'sales-returns', 'sales-returns/*', 'warehouse/shipping', 'warehouse/shipping/*', 'warehouse/asset-trustee', 'warehouse/asset-trustee/*', 'warehouse-map', 'warehouse-map/*', 'stocktake', 'stocktake/*')
    );

    $salesActive = $isRoute('preinvoice.create', 'preinvoice.my.*', 'customers.*', 'persons.*');
    $financeActive = $isRoute('preinvoice.draft.*', 'account-statements.*', 'invoices.*', 'finance.cheques.*', 'finance.reports.*');
    $configActive = $isRoute('categories.*', 'model-lists.*', 'shipping-methods.*', 'users.*', 'admin.permissions.*', 'admin.roles.*', 'activity-logs.*', 'inventory-webhooks.*');

    $initialOpenSection = match (true) {
        $productsActive => 'products',
        $warehouseActive => 'warehouse',
        $salesActive => 'sales',
        $financeActive => 'finance',
        $configActive => 'config',
        default => null,
    };
@endphp

<svg class="sidebar-symbols" aria-hidden="true">
    <symbol id="sidebar-icon-dashboard" viewBox="0 0 24 24">
        <path d="M4 13a8 8 0 1116 0M12 13l4-4M5 19h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="sidebar-icon-products" viewBox="0 0 24 24">
        <path d="M4 7l8-4 8 4-8 4-8-4zm0 0v10l8 4 8-4V7M12 11v10" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
    </symbol>
    <symbol id="sidebar-icon-warehouse" viewBox="0 0 24 24">
        <path d="M3 10l9-6 9 6v10H3V10zm4 10v-7h10v7M8 13h8M8 16h8" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="sidebar-icon-sales" viewBox="0 0 24 24">
        <path d="M3 4h2l2.2 10.2a2 2 0 002 1.6h7.7a2 2 0 001.9-1.4L20 8H7M10 20h.01M17 20h.01M10 11l1.5 1.5L15 9" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="sidebar-icon-finance" viewBox="0 0 24 24">
        <path d="M4 6.5A2.5 2.5 0 016.5 4H18a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V6.5zm0 1.5h14a2 2 0 012 2v4h-5a2 2 0 110-4h5M15 12h.01" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="sidebar-icon-config" viewBox="0 0 24 24">
        <path d="M12 8.5a3.5 3.5 0 110 7 3.5 3.5 0 010-7zm0-5v2m0 13v2M3.5 12h2m13 0h2M6 6l1.4 1.4M16.6 16.6L18 18M18 6l-1.4 1.4M7.4 16.6L6 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
    </symbol>
</svg>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<aside class="app-sidebar"
       id="appSidebar"
       data-initial-open-section="{{ $initialOpenSection }}"
       aria-label="منوی اصلی">
    <div class="sidebar-scroll">
        <div class="app-sidebar__mobile-head">
            <img class="app-sidebar__logo" src="{{ asset('logo.png') }}" alt="{{ config('app.name') }}">
            <div class="app-sidebar__mobile-copy">
                <span class="app-sidebar__title">{{ config('app.name', 'نرم افزار داخلی آریا گستر') }}</span>
                <span class="app-sidebar__subtitle">مدیریت موجودی و گردش کالا</span>
            </div>
            <button type="button" class="sidebar-close-btn" id="sidebarCloseBtn" aria-label="بستن منو">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </div>

        <div class="app-sidebar__brand">
            <img class="app-sidebar__logo" src="{{ asset('logo.png') }}" alt="{{ config('app.name') }}">
            <div class="app-sidebar__brand-copy">
                <span class="app-sidebar__title">{{ config('app.name', 'نرم افزار داخلی آریا گستر') }}</span>
                <span class="app-sidebar__subtitle">مدیریت موجودی و گردش کالا</span>
            </div>
        </div>

        @canPermission('dashboard.view')
            <a class="sidebar-section-title sidebar-section-link {{ $isRoute('dashboard') ? 'is-active' : '' }}"
               href="{{ route('dashboard') }}"
               title="داشبورد">
                <span class="sidebar-section-main">
                    <svg class="sidebar-icon" aria-hidden="true"><use href="#sidebar-icon-dashboard"/></svg>
                    <span class="sidebar-label">داشبورد</span>
                </span>
            </a>
        @endcanPermission

        @canAnyPermission(['products.view','products.create','stock_in.view','products.price_changes.view','products.export','products.change_status'])
            <div class="sidebar-accordion-item {{ $productsActive ? 'is-open' : '' }}" data-accordion-section="products">
                <button type="button"
                        class="sidebar-section-title sidebar-accordion-trigger {{ $productsActive ? 'is-active' : '' }}"
                        data-accordion-trigger
                        aria-expanded="{{ $productsActive ? 'true' : 'false' }}"
                        title="کالاهای آریا">
                    <span class="sidebar-section-main">
                        <svg class="sidebar-icon" aria-hidden="true"><use href="#sidebar-icon-products"/></svg>
                        <span class="sidebar-label">کالاهای آریا</span>
                    </span>
                    <svg class="sidebar-accordion-trigger-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <div class="sidebar-accordion-panel" data-accordion-panel>
                    <div class="sidebar-submenu">
                        @canPermission('products.view')
                            <a class="sidebar-sublink {{ $is('products.index') }}" href="{{ route('products.index') }}">نمایش کالاها</a>
                        @endcanPermission
                        @canPermission('products.create')
                            <a class="sidebar-sublink {{ $is('products.create') }}" href="{{ route('products.create') }}">تعریف کالا</a>
                        @endcanPermission
                        @canPermission('stock_in.view')
                            <a class="sidebar-sublink {{ $is('purchases.*') }}" href="{{ route('purchases.index') }}">خرید کالا</a>
                        @endcanPermission
                        @canPermission('products.price_changes.view')
                            <a class="sidebar-sublink {{ $is('products.price-changes.*') ?: $pathActive('products/price-changes', 'products/price-changes/*') }}" href="{{ route('products.price-changes.index') }}">تغییر قیمت کالا</a>
                        @endcanPermission
                        @canPermission('products.export')
                            <a class="sidebar-sublink {{ $is('admin.product-exports.*') }}" href="{{ route('admin.product-exports.index') }}">خروجی کالا</a>
                        @endcanPermission
                        @canPermission('products.change_status')
                            <a class="sidebar-sublink {{ $is('product-deactivation-documents.*') }}" href="{{ route('product-deactivation-documents.index') }}">غیرفعال کردن کالا</a>
                        @endcanPermission
                    </div>
                </div>
            </div>
        @endcanAnyPermission

        @canAnyPermission(['issues.view','warehouse.collection.queue.view','warehouse.shipping.queue.view','inventory.count.view','assets.view','warehouse_map.view'])
            <div class="sidebar-accordion-item {{ $warehouseActive ? 'is-open' : '' }}" data-accordion-section="warehouse">
                <button type="button"
                        class="sidebar-section-title sidebar-accordion-trigger {{ $warehouseActive ? 'is-active' : '' }}"
                        data-accordion-trigger
                        aria-expanded="{{ $warehouseActive ? 'true' : 'false' }}"
                        title="انبارداری">
                    <span class="sidebar-section-main">
                        <svg class="sidebar-icon" aria-hidden="true"><use href="#sidebar-icon-warehouse"/></svg>
                        <span class="sidebar-label">انبارداری</span>
                    </span>
                    <svg class="sidebar-accordion-trigger-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <div class="sidebar-accordion-panel" data-accordion-panel>
                    <div class="sidebar-submenu">
                        @canPermission('issues.view')
                            <a class="sidebar-sublink {{ $isRoute('vouchers.sales.queue', 'vouchers.sales.queue.*') || $isPath('vouchers/sales/queue', 'vouchers/sales/queue/*') ? '' : ($is('vouchers.*') ?: $pathActive('vouchers', 'vouchers/*')) }}" href="{{ route('vouchers.index') }}">حواله‌های انبار</a>
                        @endcanPermission
                        @canPermission('warehouse.collection.queue.view')
                            <a class="sidebar-sublink {{ $is('vouchers.sales.queue', 'vouchers.sales.queue.*') ?: $pathActive('vouchers/sales/queue', 'vouchers/sales/queue/*') }}" href="{{ route('vouchers.sales.queue') }}">صف جمع‌آوری فاکتور</a>
                        @endcanPermission
                        @canPermission('warehouse.shipping.queue.view')
                            <a class="sidebar-sublink {{ $is('warehouse.shipping.*') ?: $pathActive('warehouse/shipping', 'warehouse/shipping/*') }}" href="{{ route('warehouse.shipping.index') }}">صف ارسال فاکتور</a>
                        @endcanPermission
                        @canPermission('assets.view')
                            <a class="sidebar-sublink {{ $is('asset.*') ?: $pathActive('warehouse/asset-trustee', 'warehouse/asset-trustee/*') }}" href="{{ route('asset.hub') }}">امین اموال</a>
                        @endcanPermission
                        @canPermission('warehouse_map.view')
                            <a class="sidebar-sublink {{ $is('warehouse-map.*') ?: $pathActive('warehouse-map', 'warehouse-map/*') }}" href="{{ route('warehouse-map.index') }}">نقشه انبار</a>
                        @endcanPermission
                        @canPermission('inventory.count.view')
                            <a class="sidebar-sublink {{ $is('stocktake.*', 'stocktake.index') ?: $pathActive('stocktake', 'stocktake/*') }}" href="{{ route('stocktake.index') }}">انبارگردانی</a>
                        @endcanPermission
                    </div>
                </div>
            </div>
        @endcanAnyPermission

        @canAnyPermission(['preinvoices.create','preinvoices.own.view','customers.view'])
            <div class="sidebar-accordion-item {{ $salesActive ? 'is-open' : '' }}" data-accordion-section="sales">
                <button type="button"
                        class="sidebar-section-title sidebar-accordion-trigger {{ $salesActive ? 'is-active' : '' }}"
                        data-accordion-trigger
                        aria-expanded="{{ $salesActive ? 'true' : 'false' }}"
                        title="بازرگانی و فروش">
                    <span class="sidebar-section-main">
                        <svg class="sidebar-icon" aria-hidden="true"><use href="#sidebar-icon-sales"/></svg>
                        <span class="sidebar-label">بازرگانی و فروش</span>
                    </span>
                    <svg class="sidebar-accordion-trigger-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <div class="sidebar-accordion-panel" data-accordion-panel>
                    <div class="sidebar-submenu">
                        @canPermission('preinvoices.create')
                            <a class="sidebar-sublink {{ $is('preinvoice.create') }}" href="{{ route('preinvoice.create') }}">ثبت پیش‌فاکتور</a>
                        @endcanPermission
                        @canPermission('preinvoices.own.view')
                            <a class="sidebar-sublink {{ $is('preinvoice.my.*') }}" href="{{ route('preinvoice.my.index') }}">پیش‌فاکتورهای من</a>
                        @endcanPermission
                        @canPermission('customers.view')
                            <a class="sidebar-sublink {{ $is('customers.*', 'persons.*') }}" href="{{ route('customers.index') }}">اشخاص و طرف‌حساب‌ها</a>
                        @endcanPermission
                    </div>
                </div>
            </div>
        @endcanAnyPermission

        @canAnyPermission(['preinvoices.finance.view','account_statements.view','invoices.view','cheques.view','finance.reports.view'])
            <div class="sidebar-accordion-item {{ $financeActive ? 'is-open' : '' }}" data-accordion-section="finance">
                <button type="button"
                        class="sidebar-section-title sidebar-accordion-trigger {{ $financeActive ? 'is-active' : '' }}"
                        data-accordion-trigger
                        aria-expanded="{{ $financeActive ? 'true' : 'false' }}"
                        title="مالی">
                    <span class="sidebar-section-main">
                        <svg class="sidebar-icon" aria-hidden="true"><use href="#sidebar-icon-finance"/></svg>
                        <span class="sidebar-label">مالی</span>
                    </span>
                    <svg class="sidebar-accordion-trigger-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <div class="sidebar-accordion-panel" data-accordion-panel>
                    <div class="sidebar-submenu">
                        @canPermission('preinvoices.finance.view')
                            <a class="sidebar-sublink {{ $is('preinvoice.draft.*') }}" href="{{ route('preinvoice.draft.index') }}">در انتظار تایید مالی</a>
                        @endcanPermission
                        @canPermission('account_statements.view')
                            <a class="sidebar-sublink {{ $is('account-statements.*') }}" href="{{ route('account-statements.index') }}">گردش حساب اشخاص</a>
                        @endcanPermission
                        @canPermission('invoices.view')
                            <a class="sidebar-sublink {{ $is('invoices.*') }}" href="{{ route('invoices.index') }}">فاکتورها</a>
                        @endcanPermission
                        @canPermission('cheques.view')
                            <a class="sidebar-sublink {{ $is('finance.cheques.*') }}" href="{{ route('finance.cheques.registered') }}">چک‌های ثبت‌شده</a>
                        @endcanPermission
                        @canPermission('finance.reports.view')
                            <a class="sidebar-sublink {{ $is('finance.reports.*') }}" href="{{ route('finance.reports.index') }}">گزارش مالی</a>
                        @endcanPermission
                    </div>
                </div>
            </div>
        @endcanAnyPermission

        @canAnyPermission(['categories.view','model_lists.view','shipping_methods.view','users.view','permissions.view','roles.view','logs.view','inventory_webhooks.view'])
            <div class="sidebar-accordion-item {{ $configActive ? 'is-open' : '' }}" data-accordion-section="config">
                <button type="button"
                        class="sidebar-section-title sidebar-accordion-trigger {{ $configActive ? 'is-active' : '' }}"
                        data-accordion-trigger
                        aria-expanded="{{ $configActive ? 'true' : 'false' }}"
                        title="پیکربندی">
                    <span class="sidebar-section-main">
                        <svg class="sidebar-icon" aria-hidden="true"><use href="#sidebar-icon-config"/></svg>
                        <span class="sidebar-label">پیکربندی</span>
                    </span>
                    <svg class="sidebar-accordion-trigger-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <div class="sidebar-accordion-panel" data-accordion-panel>
                    <div class="sidebar-submenu">
                        @canPermission('categories.view')
                            <a class="sidebar-sublink {{ $is('categories.*') }}" href="{{ route('categories.index') }}">دسته‌بندی کالاها</a>
                        @endcanPermission
                        @canPermission('model_lists.view')
                            <a class="sidebar-sublink {{ $is('model-lists.*') }}" href="{{ route('model-lists.index') }}">مدل لیست کالا</a>
                        @endcanPermission
                        @canPermission('shipping_methods.view')
                            <a class="sidebar-sublink {{ $is('shipping-methods.*') }}" href="{{ route('shipping-methods.index') }}">روش‌های ارسال بار</a>
                        @endcanPermission
                        @canPermission('users.view')
                            <a class="sidebar-sublink {{ $is('users.*') }}" href="{{ route('users.index') }}">کاربران و پرسنل</a>
                        @endcanPermission
                        @canPermission('permissions.view')
                            <a class="sidebar-sublink {{ $is('admin.permissions.*') }}" href="{{ route('admin.permissions.index') }}">مدیریت دسترسی کاربران</a>
                        @endcanPermission
                        @canPermission('roles.view')
                            <a class="sidebar-sublink {{ $is('admin.roles.*') }}" href="{{ route('admin.roles.index') }}">مدیریت نقش‌ها</a>
                        @endcanPermission
                        @canPermission('logs.view')
                            <a class="sidebar-sublink {{ $is('activity-logs.*') }}" href="{{ route('activity-logs.index') }}">لاگ فعالیت کاربران</a>
                        @endcanPermission
                        @canPermission('inventory_webhooks.view')
                            <a class="sidebar-sublink {{ $is('inventory-webhooks.*') }}" href="{{ route('inventory-webhooks.index') }}">مدیریت API موجودی/قیمت</a>
                        @endcanPermission
                    </div>
                </div>
            </div>
        @endcanAnyPermission
    </div>
</aside>
