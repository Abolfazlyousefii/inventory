@extends('layouts.app')

@section('content')
@php
  $tab = $activeTab ?? 'locations';
  $fmt = fn($n) => number_format((int) $n);
  $variantLabel = fn($v) => trim(($v?->product?->name ?? '—') . ' / ' . ($v?->variant_name ?: $v?->variety_name ?: 'تنوع اصلی'));
  $variantCode = fn($v) => $v?->variant_code ?: ($v?->sku ?: ($v?->barcode ?: ($v?->product?->code ?? '—')));
@endphp
<style>
  .warehouse-map-page{max-width:100%;overflow-x:hidden}.wm-hero{background:linear-gradient(135deg,#0f766e,#0ea5e9);border-radius:22px;color:#fff;padding:24px;box-shadow:0 18px 45px rgba(14,116,144,.18)}
  .wm-card{border:0;border-radius:18px;box-shadow:0 10px 28px rgba(15,23,42,.06)}.wm-stat{border-radius:18px;border:1px solid #e5e7eb;background:#fff;padding:18px;height:100%}.wm-stat .value{font-size:1.45rem;font-weight:900}.wm-stat .label{color:#64748b;font-size:.86rem}.wm-table-wrap{overflow-x:auto}.wm-table{min-width:920px}.nav-pills .nav-link{border-radius:14px;font-weight:700;color:#475569}.nav-pills .nav-link.active{background:#0f766e}.badge-soft{background:#f8fafc;border:1px solid #e2e8f0;color:#334155}.action-stack{display:flex;gap:.35rem;flex-wrap:wrap}.modal-xl{--bs-modal-width:1100px}
</style>
<div class="container-fluid py-4 warehouse-map-page">
  <div class="wm-hero mb-3 d-flex justify-content-between align-items-start gap-3 flex-wrap">
    <div>
      <h3 class="mb-2 fw-bold">نقشه انبار</h3>
      <div class="opacity-75">مدیریت جایگاه فیزیکی تنوع کالاها در زون، رک و باکس</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      @if($canManage)<button class="btn btn-light fw-bold" data-bs-toggle="collapse" data-bs-target="#newLocation">افزودن مکان</button>@endif
      <a class="btn btn-outline-light fw-bold" href="#transfer-tab" data-bs-toggle="pill">جابه‌جایی کالا</a>
      <a class="btn btn-warning fw-bold" href="#unmapped-tab" data-bs-toggle="pill">تنوع‌های بدون مکان</a>
    </div>
  </div>

  @if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
  @if($errors->any()) <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif

  <div class="row g-3 mb-3">
    <div class="col-6 col-lg"><div class="wm-stat"><div class="label">کل مکان‌ها</div><div class="value">{{ $fmt($summary['locations']) }}</div></div></div>
    <div class="col-6 col-lg"><div class="wm-stat"><div class="label">تنوع‌های دارای مکان</div><div class="value text-success">{{ $fmt($summary['mapped_variants']) }}</div></div></div>
    <div class="col-6 col-lg"><div class="wm-stat"><div class="label">تنوع‌های بدون مکان</div><div class="value text-warning">{{ $fmt($summary['unmapped_variants']) }}</div></div></div>
    <div class="col-6 col-lg"><div class="wm-stat"><div class="label">تنوع‌های چندمکانه</div><div class="value text-info">{{ $fmt($summary['multi_location_variants']) }}</div></div></div>
    <div class="col-6 col-lg"><div class="wm-stat"><div class="label">مغایرت‌های مکانی</div><div class="value text-danger">{{ $fmt($summary['mismatches']) }}</div></div></div>
  </div>

  @if($canManage)
  <div class="card wm-card mb-3 collapse" id="newLocation"><div class="card-body">
    <h5 class="fw-bold mb-3">افزودن مکان جدید</h5>
    <form method="POST" action="{{ route('warehouse-map.locations.store') }}" class="row g-3">@csrf
      <input type="hidden" name="tab" value="locations">
      <div class="col-md-2"><label class="form-label">انبار</label><select name="warehouse_id" class="form-select" required>@foreach($warehouses as $w)<option value="{{ $w->id }}" @selected($warehouseId===$w->id)>{{ $w->name }}</option>@endforeach</select></div>
      <div class="col-md-2"><label class="form-label">زون</label><input name="zone" class="form-control" placeholder="Z01" required></div>
      <div class="col-md-2"><label class="form-label">رک</label><input name="rack" class="form-control" placeholder="R02" required></div>
      <div class="col-md-2"><label class="form-label">باکس</label><input name="box" class="form-control" placeholder="B05" required></div>
      <div class="col-md-3"><label class="form-label">توضیحات</label><input name="description" class="form-control"></div>
      <div class="col-md-1 d-flex align-items-end"><label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_active" value="1" checked> فعال</label></div>
      <div class="col-12"><button class="btn btn-success px-4">ثبت مکان</button></div>
    </form>
  </div></div>
  @endif

  <ul class="nav nav-pills gap-2 mb-3" id="wmTabs" role="tablist">
    <li class="nav-item"><a class="nav-link {{ $tab==='locations'?'active':'' }}" data-bs-toggle="pill" href="#locations-tab">مکان‌های انبار</a></li>
    <li class="nav-item"><a class="nav-link {{ $tab==='assign'?'active':'' }}" data-bs-toggle="pill" href="#assign-tab">تخصیص کالا به مکان</a></li>
    <li class="nav-item"><a class="nav-link {{ $tab==='transfer'?'active':'' }}" data-bs-toggle="pill" href="#transfer-tab">جابه‌جایی بین مکان‌ها</a></li>
    <li class="nav-item"><a class="nav-link {{ $tab==='unmapped'?'active':'' }}" data-bs-toggle="pill" href="#unmapped-tab">تنوع‌های بدون مکان</a></li>
    <li class="nav-item"><a class="nav-link {{ $tab==='history'?'active':'' }}" data-bs-toggle="pill" href="#history-tab">تاریخچه</a></li>
  </ul>

  <div class="tab-content">
    <div class="tab-pane fade {{ $tab==='locations'?'show active':'' }}" id="locations-tab">
      <div class="card wm-card mb-3"><div class="card-body"><form class="row g-2"><input type="hidden" name="tab" value="locations"><div class="col-md-2"><select name="warehouse_id" class="form-select">@foreach($warehouses as $w)<option value="{{ $w->id }}" @selected($warehouseId===$w->id)>{{ $w->name }}</option>@endforeach</select></div><div class="col-md-2"><input name="zone" class="form-control" value="{{ request('zone') }}" placeholder="زون"></div><div class="col-md-2"><input name="rack" class="form-control" value="{{ request('rack') }}" placeholder="رک"></div><div class="col-md-2"><input name="box" class="form-control" value="{{ request('box') }}" placeholder="باکس"></div><div class="col-md-3"><input name="location_q" class="form-control" value="{{ request('location_q') }}" placeholder="جستجو بر اساس کد مکان"></div><div class="col-md-1"><button class="btn btn-dark w-100">فیلتر</button></div></form></div></div>
      <div class="card wm-card"><div class="card-header bg-white fw-bold">مکان‌های انبار</div><div class="wm-table-wrap"><table class="table wm-table align-middle mb-0"><thead class="table-light"><tr><th>کد مکان</th><th>انبار</th><th>زون</th><th>رک</th><th>باکس</th><th>تعداد تنوع</th><th>تعداد کل کالا</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
      @forelse($locations as $location)
        <tr><td dir="ltr" class="fw-bold">{{ $location->code }}</td><td>{{ $location->warehouse?->name }}</td><td>{{ $location->zone }}</td><td>{{ $location->rack }}</td><td>{{ $location->box }}</td><td>{{ $fmt($location->variants_count) }}</td><td>{{ $fmt($location->total_quantity) }}</td><td>{!! $location->is_active ? '<span class="badge text-bg-success">فعال</span>' : '<span class="badge text-bg-secondary">غیرفعال</span>' !!}</td><td><div class="action-stack"><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#locationModal{{ $location->id }}">مشاهده کالاها</button>@if($canManage)<button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#locationModal{{ $location->id }}">افزودن کالا</button><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editLocation{{ $location->id }}">ویرایش مکان</button>@endif</div></td></tr>
      @empty <tr><td colspan="9" class="text-center text-muted py-4">مکانی ثبت نشده است.</td></tr>@endforelse
      </tbody></table></div><div class="card-footer bg-white">{{ $locations->links() }}</div></div>
    </div>

    <div class="tab-pane fade {{ $tab==='assign'?'show active':'' }}" id="assign-tab">
      @include('warehouse-map.partials.variant-table', ['rows' => $variantRows, 'title' => 'کالاهای دارای مکان', 'showFilters' => true])
    </div>

    <div class="tab-pane fade {{ $tab==='transfer'?'show active':'' }}" id="transfer-tab">
      <div class="card wm-card mb-3"><div class="card-body"><h5 class="fw-bold mb-3">ثبت جابه‌جایی بین مکان‌ها</h5>@if($canManage)<form method="POST" action="{{ route('warehouse-map.transfer') }}" class="row g-3">@csrf<input type="hidden" name="warehouse_id" value="{{ $warehouseId }}"><div class="col-md-3"><label class="form-label">تنوع کالا</label><select name="product_variant_id" class="form-select" required><option value="">انتخاب تنوع...</option>@foreach($variantRows as $row)<option value="{{ $row['variant']->id }}">{{ $variantLabel($row['variant']) }} - {{ $variantCode($row['variant']) }}</option>@endforeach</select></div><div class="col-md-2"><label class="form-label">مکان مبدا</label><select name="from_location_id" class="form-select" required>@foreach($allLocations as $loc)<option value="{{ $loc->id }}">{{ $loc->code }}</option>@endforeach</select></div><div class="col-md-2"><label class="form-label">مکان مقصد</label><select name="to_location_id" class="form-select" required>@foreach($allLocations as $loc)<option value="{{ $loc->id }}">{{ $loc->code }}</option>@endforeach</select></div><div class="col-md-2"><label class="form-label">تعداد جابه‌جایی</label><input type="number" min="1" name="quantity" class="form-control" required></div><div class="col-md-3"><label class="form-label">توضیحات</label><input name="note" class="form-control"></div><div class="col-12"><button class="btn btn-dark">ثبت جابه‌جایی</button></div></form>@else<div class="alert alert-warning mb-0">شما فقط دسترسی مشاهده دارید.</div>@endif</div></div>
      <div class="card wm-card"><div class="card-header bg-white fw-bold">آخرین جابه‌جایی‌ها</div><div class="wm-table-wrap"><table class="table align-middle mb-0"><thead class="table-light"><tr><th>تاریخ</th><th>کاربر</th><th>کالا</th><th>تنوع</th><th>از مکان</th><th>به مکان</th><th>تعداد</th><th>توضیحات</th></tr></thead><tbody>@forelse($recentTransfers as $m)<tr><td>{{ $m->created_at?->format('Y-m-d H:i') }}</td><td>{{ $m->user?->name ?? '—' }}</td><td>{{ $m->variant?->product?->name }}</td><td>{{ $m->variant?->variant_name }}</td><td dir="ltr">{{ $m->fromLocation?->code ?? '—' }}</td><td dir="ltr">{{ $m->toLocation?->code ?? '—' }}</td><td>{{ $fmt($m->quantity) }}</td><td>{{ $m->note }}</td></tr>@empty<tr><td colspan="8" class="text-center text-muted py-4">جابه‌جایی ثبت نشده است.</td></tr>@endforelse</tbody></table></div></div>
    </div>

    <div class="tab-pane fade {{ $tab==='unmapped'?'show active':'' }}" id="unmapped-tab">
      @include('warehouse-map.partials.variant-table', ['rows' => $unmappedRows, 'title' => 'تنوع‌هایی که هنوز کامل جانمایی نشده‌اند', 'showFilters' => false])
    </div>

    <div class="tab-pane fade {{ $tab==='history'?'show active':'' }}" id="history-tab">
      <div class="card wm-card mb-3"><div class="card-body"><form class="row g-2"><input type="hidden" name="tab" value="history"><input type="hidden" name="warehouse_id" value="{{ $warehouseId }}"><div class="col-md-2"><select name="history_type" class="form-select"><option value="">نوع عملیات</option>@foreach($movementTypes as $k=>$v)<option value="{{ $k }}" @selected(request('history_type')===$k)>{{ $v }}</option>@endforeach</select></div><div class="col-md-3"><select name="history_variant_id" class="form-select"><option value="">کالا / تنوع</option>@foreach($variantRows as $row)<option value="{{ $row['variant']->id }}" @selected(request('history_variant_id')==$row['variant']->id)>{{ $variantLabel($row['variant']) }}</option>@endforeach</select></div><div class="col-md-2"><select name="from_location_id" class="form-select"><option value="">مکان مبدا</option>@foreach($allLocations as $loc)<option value="{{ $loc->id }}" @selected(request('from_location_id')==$loc->id)>{{ $loc->code }}</option>@endforeach</select></div><div class="col-md-2"><select name="to_location_id" class="form-select"><option value="">مکان مقصد</option>@foreach($allLocations as $loc)<option value="{{ $loc->id }}" @selected(request('to_location_id')==$loc->id)>{{ $loc->code }}</option>@endforeach</select></div><div class="col-md-2"><select name="user_id" class="form-select"><option value="">کاربر</option>@foreach($users as $u)<option value="{{ $u->id }}" @selected(request('user_id')==$u->id)>{{ $u->name }}</option>@endforeach</select></div><div class="col-md-1"><button class="btn btn-dark w-100">فیلتر</button></div><div class="col-md-2"><input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control"></div><div class="col-md-2"><input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control"></div></form></div></div>
      <div class="card wm-card"><div class="wm-table-wrap"><table class="table wm-table align-middle mb-0"><thead class="table-light"><tr><th>تاریخ</th><th>نوع عملیات</th><th>کاربر</th><th>کالا</th><th>تنوع</th><th>مکان مبدا</th><th>مکان مقصد</th><th>تعداد</th><th>توضیحات</th><th>reference</th></tr></thead><tbody>@forelse($movements as $m)<tr><td>{{ $m->created_at?->format('Y-m-d H:i') }}</td><td>{{ $movementTypes[$m->type] ?? $m->type }}</td><td>{{ $m->user?->name ?? '—' }}</td><td>{{ $m->variant?->product?->name }}</td><td>{{ $m->variant?->variant_name }}</td><td dir="ltr">{{ $m->fromLocation?->code ?? '—' }}</td><td dir="ltr">{{ $m->toLocation?->code ?? '—' }}</td><td>{{ $fmt($m->quantity) }}</td><td>{{ $m->note }}</td><td dir="ltr">{{ $m->reference_type ? $m->reference_type.'#'.$m->reference_id : '—' }}</td></tr>@empty<tr><td colspan="10" class="text-center text-muted py-4">تاریخچه‌ای ثبت نشده است.</td></tr>@endforelse</tbody></table></div><div class="card-footer bg-white">{{ $movements->links() }}</div></div>
    </div>
  </div>

  @foreach($locations as $location)
    <div class="modal fade" id="locationModal{{ $location->id }}" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">مکان: <span dir="ltr">{{ $location->code }}</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
      @if($canManage)
      <form method="POST" action="{{ route('warehouse-map.assign') }}" class="row g-2 border rounded-4 p-3 mb-3 bg-light wm-location-assign-form"
            data-children-url-template="{{ route('warehouse-map.categories.children', ['category' => '__ID__']) }}"
            data-products-url-template="{{ route('warehouse-map.categories.products', ['category' => '__ID__']) }}"
            data-variants-url-template="{{ route('warehouse-map.products.variants', ['product' => '__ID__']) }}"
            data-warehouse-id="{{ $warehouseId }}">
        @csrf
        <input type="hidden" name="warehouse_id" value="{{ $warehouseId }}">
        <input type="hidden" name="warehouse_location_id" value="{{ $location->id }}">
        <div class="col-md-3"><label class="form-label">دسته‌بندی اصلی</label><select class="form-select wm-main-category" required><option value="">انتخاب دسته‌بندی...</option>@foreach($mainCategories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label">زیر‌دسته‌بندی</label><select class="form-select wm-sub-category" disabled><option value="">ابتدا دسته‌بندی را انتخاب کنید</option></select></div>
        <div class="col-md-4"><label class="form-label">کالا</label><input type="search" class="form-control form-control-sm mb-1 wm-product-search" placeholder="جستجوی سریع کالا..." disabled><select class="form-select wm-product" disabled required><option value="">ابتدا دسته‌بندی را انتخاب کنید</option></select></div>
        <div class="col-md-4"><label class="form-label">تنوع کالا</label><select name="product_variant_id" class="form-select wm-variant" disabled required><option value="">ابتدا کالا را انتخاب کنید</option></select></div>
        <div class="col-md-4"><label class="form-label d-block">موجودی</label><div class="d-flex gap-2 flex-wrap"><span class="badge badge-soft">موجودی کل: <b class="wm-total">—</b></span><span class="badge badge-soft">دارای مکان: <b class="wm-mapped">—</b></span><span class="badge text-bg-warning">بدون مکان: <b class="wm-unmapped">—</b></span></div><div class="small mt-1 wm-stock-message text-muted">بعد از انتخاب تنوع، موجودی نمایش داده می‌شود.</div></div>
        <div class="col-md-2"><label class="form-label">تعداد برای افزودن</label><input type="number" name="quantity" min="1" class="form-control wm-quantity" disabled required></div>
        <div class="col-md-8"><label class="form-label">توضیحات</label><input name="note" class="form-control" placeholder="اختیاری"></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-success w-100 wm-submit" disabled>افزودن به مکان</button></div>
        <div class="col-12 small text-muted">مسیر اصلی انتخاب: دسته‌بندی اصلی → زیر‌دسته‌بندی → کالا → تنوع کالا. فقط از موجودی بدون مکان همین انبار قابل تخصیص است.</div>
      </form>
      @endif
      <div class="wm-table-wrap"><table class="table align-middle"><thead class="table-light"><tr><th>کالا</th><th>تنوع</th><th>کد / SKU / بارکد</th><th>تعداد در این مکان</th><th>عملیات</th></tr></thead><tbody>@forelse($location->stocks->where('quantity','>',0) as $stock)<tr><td>{{ $stock->variant?->product?->name }}</td><td>{{ $stock->variant?->variant_name }}</td><td dir="ltr">{{ $variantCode($stock->variant) }}</td><td>{{ $fmt($stock->quantity) }}</td><td><span class="badge badge-soft">جابه‌جایی و تاریخچه از تب‌های مربوط انجام می‌شود</span></td></tr>@empty<tr><td colspan="5" class="text-center text-muted py-4">کالایی در این مکان ثبت نشده است.</td></tr>@endforelse</tbody></table></div>
    </div></div></div></div>
    @if($canManage)<div class="modal fade" id="editLocation{{ $location->id }}" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">ویرایش مکان {{ $location->code }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form method="POST" action="{{ route('warehouse-map.locations.update', $location) }}"><div class="modal-body">@csrf @method('PUT')<label class="form-label">توضیحات</label><textarea name="description" class="form-control mb-3">{{ $location->description }}</textarea><label class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($location->is_active)> فعال</label><div class="small text-muted mt-2">غیرفعال کردن مکان فقط از تخصیص‌های جدید جلوگیری می‌کند.</div></div><div class="modal-footer"><button class="btn btn-primary">ثبت</button></div></form></div></div></div>@endif
  @endforeach
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const faToEn = (value) => String(value || '').replace(/[۰-۹٠-٩]/g, ch => '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩'.indexOf(ch) % 10);
  const fillSelect = (select, placeholder, items, mapper) => {
    select.innerHTML = `<option value="">${placeholder}</option>`;
    items.forEach(item => {
      const option = document.createElement('option');
      const mapped = mapper(item);
      option.value = mapped.value;
      option.textContent = mapped.text;
      if (mapped.dataset) Object.entries(mapped.dataset).forEach(([key, val]) => option.dataset[key] = val);
      select.appendChild(option);
    });
  };
  const setLoading = (select, text) => { select.disabled = true; select.innerHTML = `<option value="">${text}</option>`; };
  const endpoint = (template, id) => template.replace('__ID__', id);

  document.querySelectorAll('.wm-location-assign-form').forEach(form => {
    const main = form.querySelector('.wm-main-category');
    const sub = form.querySelector('.wm-sub-category');
    const product = form.querySelector('.wm-product');
    const productSearch = form.querySelector('.wm-product-search');
    const variant = form.querySelector('.wm-variant');
    const qty = form.querySelector('.wm-quantity');
    const submit = form.querySelector('.wm-submit');
    const total = form.querySelector('.wm-total');
    const mapped = form.querySelector('.wm-mapped');
    const unmapped = form.querySelector('.wm-unmapped');
    const message = form.querySelector('.wm-stock-message');
    let selectedCategory = null;

    const resetStock = () => { total.textContent = mapped.textContent = unmapped.textContent = '—'; message.textContent = 'بعد از انتخاب تنوع، موجودی نمایش داده می‌شود.'; message.className = 'small mt-1 wm-stock-message text-muted'; qty.value = ''; qty.disabled = true; qty.removeAttribute('max'); submit.disabled = true; };
    const resetVariant = () => { variant.disabled = true; variant.innerHTML = '<option value="">ابتدا کالا را انتخاب کنید</option>'; resetStock(); };
    const resetProduct = () => { product.disabled = true; product.innerHTML = '<option value="">ابتدا دسته‌بندی را انتخاب کنید</option>'; productSearch.value = ''; productSearch.disabled = true; resetVariant(); };

    async function fetchJson(url) { const response = await fetch(url, {headers: {'Accept': 'application/json'}}); if (!response.ok) throw new Error('request failed'); return response.json(); }
    async function loadProducts(categoryId, term = '') {
      if (!categoryId) return resetProduct();
      setLoading(product, 'در حال دریافت کالاها...'); productSearch.disabled = false; resetVariant();
      const url = new URL(endpoint(form.dataset.productsUrlTemplate, categoryId), window.location.origin);
      if (term) url.searchParams.set('q', term);
      const products = await fetchJson(url);
      fillSelect(product, products.length ? 'انتخاب کالا...' : 'کالایی برای این دسته‌بندی یافت نشد.', products, item => ({value: item.id, text: `${item.name}${item.code ? ' - کد: ' + item.code : ''}`}));
      product.disabled = products.length === 0;
    }

    main.addEventListener('change', async () => {
      selectedCategory = main.value; sub.disabled = true; sub.innerHTML = '<option value="">در حال دریافت زیر‌دسته‌بندی‌ها...</option>'; resetProduct();
      if (!selectedCategory) { sub.innerHTML = '<option value="">ابتدا دسته‌بندی را انتخاب کنید</option>'; return; }
      const children = await fetchJson(endpoint(form.dataset.childrenUrlTemplate, selectedCategory));
      if (children.length) {
        fillSelect(sub, 'انتخاب زیر‌دسته‌بندی...', children, item => ({value: item.id, text: item.name}));
        sub.disabled = false;
      } else {
        sub.innerHTML = '<option value="">زیر‌دسته‌بندی ندارد</option>'; sub.disabled = true; await loadProducts(selectedCategory);
      }
    });

    sub.addEventListener('change', () => { selectedCategory = sub.value || main.value; loadProducts(selectedCategory); });
    productSearch.addEventListener('input', () => { window.clearTimeout(productSearch._timer); productSearch._timer = window.setTimeout(() => loadProducts(selectedCategory, productSearch.value), 350); });
    product.addEventListener('change', async () => {
      resetVariant(); if (!product.value) return;
      setLoading(variant, 'در حال دریافت تنوع‌ها...');
      const url = new URL(endpoint(form.dataset.variantsUrlTemplate, product.value), window.location.origin);
      url.searchParams.set('warehouse_id', form.dataset.warehouseId);
      const variants = await fetchJson(url);
      fillSelect(variant, variants.length ? 'انتخاب تنوع کالا...' : 'تنوعی برای این کالا یافت نشد.', variants, item => ({value: item.id, text: item.option_text, dataset: {total: item.total_stock, mapped: item.mapped_quantity, unmapped: item.unmapped_quantity, mismatch: item.has_mismatch ? 1 : 0}}));
      variant.disabled = variants.length === 0;
      if (variants.length === 1) { variant.value = variants[0].id; variant.dispatchEvent(new Event('change')); }
    });

    variant.addEventListener('change', () => {
      resetStock(); const option = variant.selectedOptions[0]; if (!option || !option.value) return;
      const unmappedQty = Number(option.dataset.unmapped || 0);
      total.textContent = Number(option.dataset.total || 0).toLocaleString('en-US');
      mapped.textContent = Number(option.dataset.mapped || 0).toLocaleString('en-US');
      unmapped.textContent = unmappedQty.toLocaleString('en-US');
      qty.max = String(Math.max(0, unmappedQty));
      qty.disabled = unmappedQty <= 0;
      submit.disabled = unmappedQty <= 0;
      if (Number(option.dataset.mismatch || 0) === 1) { message.textContent = 'موجودی مکانی این تنوع بیشتر از موجودی کل ثبت‌شده است. لطفاً بررسی شود.'; message.className = 'small mt-1 wm-stock-message text-danger'; }
      else if (unmappedQty <= 0) { message.textContent = 'برای این تنوع موجودی بدون مکان وجود ندارد.'; message.className = 'small mt-1 wm-stock-message text-warning'; }
      else { message.textContent = `حداکثر مقدار قابل افزودن: ${unmappedQty.toLocaleString('en-US')}`; message.className = 'small mt-1 wm-stock-message text-success'; }
    });

    qty.addEventListener('input', () => {
      qty.value = faToEn(qty.value);
      const max = Number(qty.max || 0); const value = Number(qty.value || 0);
      if (value > max) { message.textContent = 'تعداد وارد شده بیشتر از موجودی بدون مکان این تنوع است.'; message.className = 'small mt-1 wm-stock-message text-danger'; submit.disabled = true; }
      else { submit.disabled = !variant.value || value <= 0; }
    });

    form.addEventListener('submit', event => {
      const max = Number(qty.max || 0); const value = Number(qty.value || 0);
      if (!variant.value || value <= 0 || value > max) { event.preventDefault(); message.textContent = value > max ? 'تعداد وارد شده بیشتر از موجودی بدون مکان این تنوع است.' : 'لطفاً تنوع و تعداد معتبر را انتخاب کنید.'; message.className = 'small mt-1 wm-stock-message text-danger'; }
    });
  });
});
</script>

@endsection
