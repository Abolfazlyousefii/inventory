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

@php
    $user = auth()->user();

    $hasRole = fn(array $roles) =>
        $user && $user->hasAnyRole($roles);
@endphp
<style>

  /* ===== Backdrop ===== */
  .sidebar-backdrop{
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,.45);
    opacity: 0;
    pointer-events: none;
    transition: opacity .22s ease;
    z-index: 1190;
  }

  /* ===== Sidebar base ===== */
  .app-sidebar{
    width: 280px;
    flex: 0 0 280px;
  }

  /* ===== Mobile Offcanvas Behavior ===== */
  @media (max-width: 991.98px){
    .app-sidebar{
      position: fixed;
      top: 0;
      right: 0;
      height: 100dvh;
      max-height: 100dvh;
      z-index: 1210;
      background: #fff;
      box-shadow: 0 16px 40px rgba(15,23,42,.22);
      transform: translateX(110%);
      transition: transform .24s ease;
      border-top-left-radius: 18px;
      border-bottom-left-radius: 18px;
      overflow: hidden;
    }

    .app-sidebar .sidebar-scroll{
      height: 100%;
      overflow: auto;
      -webkit-overflow-scrolling: touch;
      padding-bottom: 18px;
    }

    /* header داخل سایدبار روی موبایل */
    .app-sidebar__mobile-head{
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      padding: 12px 14px;
      border-bottom: 1px solid rgba(15,23,42,.08);
      background: linear-gradient(0deg,#fff,#f6f8ff);
    }
    .app-sidebar__mobile-head .brand{
      display:flex; align-items:center; gap:10px; min-width:0;
    }
    .app-sidebar__mobile-head .brand img{
      width: 40px; height: 40px; object-fit: contain;
    }
    .app-sidebar__mobile-head .brand .t{
      font-weight: 800;
      font-size: 13px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .app-sidebar__mobile-head .close-btn{
      width: 40px; height: 40px;
      border-radius: 12px;
      border: 1px solid rgba(15,23,42,.12);
      background: #fff;
      display:flex; align-items:center; justify-content:center;
    }
    .app-sidebar__mobile-head .close-btn svg{ width:18px; height:18px; }
  }

  /* ===== Open state ===== */
  body.sidebar-open .app-sidebar{
    transform: translateX(0);
  }
  body.sidebar-open .sidebar-backdrop{
    opacity: 1;
    pointer-events: auto;
  }
  body.sidebar-open{
    overflow: hidden; /* قفل اسکرول */
    touch-action: none;
  }

  .sidebar-section-link,
  .sidebar-accordion-trigger{
    width: 100%;
    border: 0;
    background: transparent;
    text-align: right;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    cursor: pointer;
  }

  .sidebar-section-link{
    color: inherit;
  }

  .sidebar-accordion-trigger-icon{
    flex: 0 0 auto;
    width: 18px;
    height: 18px;
    opacity: .6;
    transform: rotate(0deg);
    transition: transform .22s ease, opacity .22s ease;
  }

  .sidebar-accordion-item.is-open .sidebar-accordion-trigger-icon{
    transform: rotate(-180deg);
    opacity: 1;
  }

  .sidebar-accordion-panel{
    overflow: hidden;
    max-height: 0;
    opacity: 0;
    transition: max-height .26s ease, opacity .18s ease;
  }

  .sidebar-accordion-item.is-open .sidebar-accordion-panel{
    opacity: 1;
  }
</style>

{{-- بک‌دراپ --}}
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="app-sidebar" id="appSidebar">
    <div class="sidebar-scroll">

        {{-- Mobile Head (فقط موبایل) --}}
        <div class="app-sidebar__mobile-head d-lg-none">
          <div class="brand">
            <img src="{{ asset('logo.png') }}" alt="{{ config('app.name') }}">
            <div class="t">{{ config('app.name', 'سیستم انبار آریا جانبی') }}</div>
          </div>
          <button type="button" class="close-btn" id="sidebarCloseBtn" aria-label="بستن منو">
            <svg viewBox="0 0 24 24" fill="none">
              <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </button>
        </div>

        {{-- Brand (desktop) --}}
        <div class="app-sidebar__brand d-none d-lg-flex">
            <img src="{{ asset('logo.png') }}"
                 alt="{{ config('app.name') }}"
                 style="height:56px;width:56px;object-fit:contain;">
            <div class="app-sidebar__title">{{ config('app.name', 'سیستم انبار آریا جانبی') }}</div>
            <div class="app-sidebar__subtitle">مدیریت موجودی و گردش کالا</div>
        </div>

        {{-- Dashboard (no submenu) --}}
        @canPermission('dashboard.view')
        <a class="sidebar-section-title sidebar-section-link {{ $isRoute('dashboard') ? 'is-active' : '' }}"
           href="{{ route('dashboard') }}">
            <span>داشبورد</span>
        </a>
        @endcanPermission


        {{-- Products --}}
        @canAnyPermission(['products.view','products.create','stock_in.view','products.price_changes.view','products.export','products.change_status'])
        <div class="sidebar-accordion-item {{ $productsActive ? 'is-open' : '' }}" data-accordion-section="products">
            <button type="button"
                    class="sidebar-section-title sidebar-accordion-trigger {{ $productsActive ? 'is-active' : '' }}"
                    data-accordion-trigger
                    aria-expanded="{{ $productsActive ? 'true' : 'false' }}">
                <span>کالاهای آریا</span>
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


        {{-- Warehouse --}}
        @canAnyPermission(['issues.view','warehouse.collection.queue.view','warehouse.shipping.queue.view','inventory.count.view','assets.view','warehouse_map.view'])
        <div class="sidebar-accordion-item {{ $warehouseActive ? 'is-open' : '' }}" data-accordion-section="warehouse">
            <button type="button"
                    class="sidebar-section-title sidebar-accordion-trigger {{ $warehouseActive ? 'is-active' : '' }}"
                    data-accordion-trigger
                    aria-expanded="{{ $warehouseActive ? 'true' : 'false' }}">
                <span>انبارداری شرکت آریا</span>
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


        {{-- Commerce & Sales --}}
        @canAnyPermission(['preinvoices.create','preinvoices.own.view','customers.view'])
        <div class="sidebar-accordion-item {{ $salesActive ? 'is-open' : '' }}" data-accordion-section="sales">
            <button type="button"
                    class="sidebar-section-title sidebar-accordion-trigger {{ $salesActive ? 'is-active' : '' }}"
                    data-accordion-trigger
                    aria-expanded="{{ $salesActive ? 'true' : 'false' }}">
                <span>بازرگانی و فروش</span>
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
        {{-- Finance --}}
        <div class="sidebar-accordion-item {{ $financeActive ? 'is-open' : '' }}" data-accordion-section="finance">
            <button type="button"
                    class="sidebar-section-title sidebar-accordion-trigger {{ $financeActive ? 'is-active' : '' }}"
                    data-accordion-trigger
                    aria-expanded="{{ $financeActive ? 'true' : 'false' }}">
                <span>مالی</span>
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

        {{-- Configuration --}}
        <div class="sidebar-accordion-item {{ $configActive ? 'is-open' : '' }}" data-accordion-section="config">
            <button type="button"
                    class="sidebar-section-title sidebar-accordion-trigger {{ $configActive ? 'is-active' : '' }}"
                    data-accordion-trigger
                    aria-expanded="{{ $configActive ? 'true' : 'false' }}">
                <span>پیکربندی</span>
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
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var sidebarStorageKey = 'inventory.sidebar.open-section';
  var initialOpenSection = @json($initialOpenSection);
  var btnOpen = document.getElementById('sidebarToggleBtn');
  var btnClose = document.getElementById('sidebarCloseBtn');
  var backdrop = document.getElementById('sidebarBackdrop');
  var sidebar = document.getElementById('appSidebar');
  var accordionItems = sidebar ? Array.from(sidebar.querySelectorAll('[data-accordion-section]')) : [];

  function isMobile(){
    return window.matchMedia('(max-width: 991.98px)').matches;
  }

  function getPanel(item){
    return item.querySelector('[data-accordion-panel]');
  }

  function setPanelState(item, isOpen){
    var panel = getPanel(item);
    var trigger = item.querySelector('[data-accordion-trigger]');

    if(!panel || !trigger){
      return;
    }

    item.classList.toggle('is-open', isOpen);
    trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    panel.style.maxHeight = isOpen ? panel.scrollHeight + 'px' : '0px';
  }

  function closeOtherSections(openSectionId){
    accordionItems.forEach(function(item){
      var sectionId = item.getAttribute('data-accordion-section');
      setPanelState(item, sectionId === openSectionId);
    });
  }

  function applyInitialOpenState(){
    var storageOpenSection = null;
    try {
      storageOpenSection = window.localStorage.getItem(sidebarStorageKey);
    } catch (error) {
      storageOpenSection = null;
    }

    var desiredSection = initialOpenSection || storageOpenSection;
    var hasDesiredSection = desiredSection && accordionItems.some(function(item){
      return item.getAttribute('data-accordion-section') === desiredSection;
    });

    closeOtherSections(hasDesiredSection ? desiredSection : null);
  }

  function openSidebar(){
    if(!isMobile()) return;
    document.body.classList.add('sidebar-open');
  }

  function closeSidebar(){
    document.body.classList.remove('sidebar-open');
  }

  btnOpen && btnOpen.addEventListener('click', openSidebar);
  btnClose && btnClose.addEventListener('click', closeSidebar);
  backdrop && backdrop.addEventListener('click', closeSidebar);

  // بستن با ESC
  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') closeSidebar();
  });

  // کلیک روی لینک‌ها => بستن خودکار (موبایل)
  if(sidebar){
    applyInitialOpenState();

    accordionItems.forEach(function(item){
      var trigger = item.querySelector('[data-accordion-trigger]');
      if(!trigger){
        return;
      }

      trigger.addEventListener('click', function(){
        var sectionId = item.getAttribute('data-accordion-section');
        var willOpen = !item.classList.contains('is-open');

        closeOtherSections(willOpen ? sectionId : null);

        try {
          if(willOpen){
            window.localStorage.setItem(sidebarStorageKey, sectionId);
          } else {
            window.localStorage.removeItem(sidebarStorageKey);
          }
        } catch (error) {
          // localStorage might be disabled in some browsers.
        }
      });
    });

    sidebar.addEventListener('click', function(e){
      var a = e.target && e.target.closest ? e.target.closest('a') : null;
      if(a && isMobile()){
        // کمی تاخیر برای احساس بهتر کلیک
        setTimeout(closeSidebar, 60);
      }
    });
  }

  // اگر از موبایل رفت روی دسکتاپ، خودکار بسته شود
  window.addEventListener('resize', function(){
    if(!isMobile()) closeSidebar();
    accordionItems.forEach(function(item){
      if(item.classList.contains('is-open')){
        var panel = getPanel(item);
        if(panel){
          panel.style.maxHeight = panel.scrollHeight + 'px';
        }
      }
    });
  });
});
</script>
