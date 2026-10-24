@once
<script src="{{ asset('js/bundle-form-payload.js') }}?v=20261024"></script>
@endonce
@php
    // The commercial bundle is one selling SKU; availability can be automatic or physically assembled.
    $bundleVariant = $product->variants->count() === 1 ? $product->variants->first() : null;
    $components = $bundleVariant ? $bundleVariant->bundleComponents()->with('product')->get() : collect();
    $assemblyHistory = $bundleVariant
        ? \App\Models\BundleAssembly::where('bundle_variant_id', $bundleVariant->id)->latest()->limit(8)->get()
        : collect();
    $recipeLocked = $bundleVariant && \App\Models\BundleAssembly::where('bundle_variant_id', $bundleVariant->id)->exists();
    $isAutomatic = (bool) $product->is_bundle && $product->bundle_stock_mode === 'automatic';
    $automaticAvailable = $isAutomatic && $bundleVariant ? app(\App\Services\AutomaticBundleAvailabilityService::class)->available((int) $bundleVariant->id) : 0;
    $isShared = $isAutomatic && ($product->bundle_quantity_mode ?? 'custom') === 'shared';
    $totalPieces = $components->sum(fn ($part) => (int) $part->pivot->quantity);
    $allocations = $bundleVariant && $isAutomatic ? \Illuminate\Support\Facades\DB::table('bundle_component_allocations')->where('bundle_variant_id', $bundleVariant->id)->pluck('remaining','component_variant_id') : collect();
    $componentCapacity = $components->isNotEmpty() ? $components->map(fn ($part) => intdiv(max(0, (int) ($allocations[$part->id] ?? 0)), max(1, (int) $part->pivot->quantity)))->min() : 0;
    $sitePublished = $product->is_bundle ? \Illuminate\Support\Facades\DB::connection('site')->table('products')->where('id', $product->id)->where('published', 1)->exists() : false;
    $recipeLocked = $recipeLocked || ($isAutomatic && ($sitePublished || $allocations->sum() > 0));
    $componentStock = $components->isNotEmpty() ? \Illuminate\Support\Facades\DB::table('warehouse_stocks')->where('warehouse_id', \App\Services\WarehouseStockService::centralWarehouseId())->whereIn('product_variant_id', $components->pluck('id'))->groupBy('product_variant_id')->selectRaw('product_variant_id, SUM(quantity) AS available')->pluck('available','product_variant_id') : collect();
    $selectedComponents = $components->map(fn ($component) => [
        'id' => (int) $component->id,
        'name' => (string) ($component->product?->name ?? 'کالای نامشخص'),
        'variant' => (string) ($component->variant_name ?: $component->variety_name ?: 'تنوع اصلی'),
        'code' => (string) ($component->variant_code ?? ''),
        'quantity' => (int) $component->pivot->quantity,
        'discount_type' => (string) ($component->pivot->discount_type ?: 'percent'),
        'discount_value' => (int) ($component->pivot->discount_value ?? 0),
        'sell_price' => \App\Services\BundleDiscountService::effectiveComponentPrice($component),
        'stock' => max(0, (int) ($componentStock[$component->id] ?? 0)),
        'allocated_quantity' => max(0, (int) ($allocations[$component->id] ?? 0)),
    ])->values()->all();

    // Keep selections after Laravel validation redirects, including their human-readable labels.
    $submittedComponents = old('components');
    if (is_array($submittedComponents)) {
        $oldIds = collect($submittedComponents)->pluck('variant_id')->filter(fn ($id) => filter_var($id, FILTER_VALIDATE_INT))->unique()->values();
        $oldVariants = \App\Models\ProductVariant::with('product')->whereIn('id', $oldIds)->get()->keyBy('id');
        $selectedComponents = collect($submittedComponents)->filter(fn ($row) => is_array($row) && ($row['variant_id'] ?? '') !== '')->map(function ($row) use ($oldVariants) {
            $variant = $oldVariants->get((int) ($row['variant_id'] ?? 0));
            return $variant ? [
                'id' => (int) $variant->id,
                'name' => (string) ($variant->product?->name ?? 'کالای نامشخص'),
                'variant' => (string) ($variant->variant_name ?: $variant->variety_name ?: 'تنوع اصلی'),
                'code' => (string) ($variant->variant_code ?? ''),
                'quantity' => $row['quantity'] ?? 1,
                'discount_type' => $row['discount_type'] ?? 'percent',
                'discount_value' => $row['discount_value'] ?? 0,
                'sell_price' => \App\Services\BundleDiscountService::effectiveComponentPrice($variant),
                'allocated_quantity' => $row['allocated_quantity'] ?? 0,
            ] : null;
        })->filter()->values()->all();
    }
@endphp

<section class="card mt-4" aria-label="Bundle configuration" dir="rtl">
    <div class="card-header"><strong>مدیریت بسته ترکیبی (Bundle SKU)</strong></div>
    <div class="card-body">
        <p class="text-muted small">بسته در سایت مانند یک کالا با یک قیمت و یک دکمه خرید نشان داده می‌شود. موجودی خودکار از قطعات محاسبه می‌شود و به مونتاژ دستی نیاز ندارد.</p>
        @if($product->is_bundle)
            @if($isAutomatic)
                <section class="rounded border p-3 mb-3" aria-label="Website publication status">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h6 class="mb-0">انتشار و همگام‌سازی در سایت</h6>
                        <span class="badge {{ $sitePublished ? 'bg-success' : 'bg-secondary' }}">{{ $sitePublished ? 'منتشر شده در سایت' : 'منتشر نشده در سایت' }}</span>
                    </div>
                    <p class="small text-muted mt-2 mb-2">قیمت، تخفیف، موجودی و مشخصات بسته از انبار به سایت منتقل می‌شود. برای بسته‌های جدید، انتشار خودکار است؛ نیازی به تأیید جداگانه در Site Admin نیست.</p>
                    @if($product->bundle_site_auto_publish === null)
                        <div class="alert alert-info py-2 small">این بسته قدیمی هنوز انتشارش را از سایت می‌گیرد. با گزینه زیر کنترل انتشار را به انبار منتقل کنید.</div>
                    @elseif(!$product->bundle_site_auto_publish)
                        <div class="alert alert-warning py-2 small">انتشار برای ویرایش متوقف است. بعد از آزادکردن سهمیه و ذخیره ترکیب جدید، دوباره از همین‌جا منتشر کنید.</div>
                    @endif
                    <form method="POST" action="{{ route('products.bundle.site-publication', $product) }}" class="d-flex flex-wrap gap-2 align-items-center">
                        @csrf
                        @if($sitePublished || $product->bundle_site_auto_publish)
                            <input type="hidden" name="enabled" value="0">
                            <button type="submit" class="btn btn-outline-warning btn-sm" onclick="return confirm('بسته فوراً از حالت انتشار در سایت خارج می‌شود. سفارش‌های قبلی تغییر نمی‌کنند. ادامه؟')">توقف انتشار برای ویرایش ترکیب</button>
                        @else
                            <input type="hidden" name="enabled" value="1">
                            <button type="submit" class="btn btn-success btn-sm">انتشار خودکار در سایت</button>
                        @endif
                    </form>
                    @if($errors->has('enabled'))<div class="alert alert-danger mt-2">{{ $errors->first('enabled') }}</div>@endif
                    <div class="small text-muted mt-2">برای تغییر قطعات: ۱. توقف انتشار ۲. سهمیه بسته را صفر و ذخیره کنید ۳. قطعات را تغییر دهید ۴. موجودی اختصاصی را دوباره تنظیم کنید ۵. از همین‌جا منتشر کنید.</div>
                </section>
                @if(($product->bundle_quantity_mode ?? 'custom') !== 'shared')
                    <div class="alert alert-warning">این بسته با روش قدیمی موجودی جداگانه ساخته شده است. برای تبدیل ایمن به موجودی یکسان، ابتدا فروش/سفارش فعال را بررسی کنید، بسته را از انتشار خارج کنید و سهمیه قبلی را آزاد کنید. این فرم موجودی قبلی را خودکار تغییر نمی‌دهد.</div>
                @else
                    <form method="POST" action="{{ route('products.bundle.policy', $product) }}" data-bundle-payload="allocations expected_allocations" class="border rounded p-3 mb-3">
                        @csrf
                        <input type="hidden" name="bundle_quantity_mode" value="shared">
                        @foreach($components as $component)
                            <input type="hidden" name="expected_allocations[{{ $component->id }}]" value="{{ (int) ($allocations[$component->id] ?? 0) }}">
                        @endforeach
                        <h6>موجودی اختصاصی این بسته</h6>
                        <p class="small text-muted">تعداد بسته را مشخص کنید؛ برای هر تنوع، <strong>تعداد داخل هر بسته × تعداد بسته</strong> از موجودی آزاد کنار گذاشته می‌شود.</p>
                        <label class="form-label" for="sharedQty-{{ $product->id }}">تعداد بسته قابل فروش (موجودی یکسان)</label>
                        <input id="sharedQty-{{ $product->id }}" class="form-control" style="max-width:230px" type="number" name="bundle_shared_quantity" min="0" max="1000000" required value="{{ old('bundle_shared_quantity', $componentCapacity) }}">
                        <div class="mt-2 small text-muted">موجودی آزاد جداگانه برای هر قطعه:</div>
                        @foreach($components as $component)
                            <div class="d-flex justify-content-between small border-bottom py-1 gap-2"><span>{{ $component->product?->name }} — {{ $component->variant_name }}</span><span>در هر بسته: {{ number_format((int) $component->pivot->quantity) }} · آزاد: {{ number_format($componentStock[$component->id] ?? 0) }} · سهم فعلی: {{ number_format($allocations[$component->id] ?? 0) }}</span></div>
                        @endforeach
                        <p class="small text-muted mt-2">سهمیه فقط از موجودی آزاد واقعی منتقل می‌شود؛ کالاهای عادی نمی‌توانند سهمیه قفل‌شده را بفروشند.</p>
                        <button class="btn btn-primary btn-sm" type="submit" onclick="return confirm('موجودی اختصاصی تمام تنوع‌ها تغییر کند؟')">ذخیره موجودی بسته</button>
                        @if($errors->has('bundle_shared_quantity') || $errors->has('allocations') || $errors->has('bundle_quantity_mode'))
                            <div class="alert alert-danger mt-2">{{ $errors->first('bundle_shared_quantity') ?: ($errors->first('allocations') ?: $errors->first('bundle_quantity_mode')) }}</div>
                        @endif
                    </form>
                @endif
                @if($bundleVariant && $components->isNotEmpty() && ($isAutomatic || $components->every(fn($v) => (int) $v->pivot->quantity === 1)))
                @php
                    $pricingOriginal = 0;
                    try { $pricingOriginal = app(\App\Services\BundleDiscountService::class)->regularPrice(
                        $components->pluck('id')->all(), $components->pluck('pivot.quantity', 'id')->all()
                    ); }
                    catch (\Throwable $e) { $pricingOriginal = 0; }
                    $pricingBase = $pricingOriginal;
                    if ($pricingOriginal > 0) {
                        try { $pricingBase = app(\App\Services\BundleDiscountService::class)->quoteForBundle(
                            (int) $bundleVariant->id, 'percent', 0
                        )['final']; }
                        catch (\Throwable $e) { $pricingBase = 0; }
                    }
                    $pricingType = old('bundle_discount_type', $product->bundle_discount_type ?: 'percent');
                    $pricingValue = old('bundle_discount_value', $product->bundle_discount_value ?? 0);
                @endphp
                <form action="{{ route('products.bundle.pricing', $product) }}" method="POST" class="border rounded p-3 mb-3" id="bundlePricingForm-{{ $product->id }}" data-original="{{ $pricingOriginal }}" data-base="{{ $pricingBase }}" dir="rtl">
                    @csrf
                    <h6>قیمت و تخفیف بسته</h6>
                    <div class="small text-muted mb-2">مجموع قیمت عادی قطعات: <strong>{{ number_format($pricingOriginal) }}</strong> ریال · پس از تخفیف قطعات: <strong>{{ number_format($pricingBase) }}</strong> ریال</div>
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label">روش تعیین تخفیف</label><select class="form-select" name="bundle_discount_type"><option value="percent" @selected($pricingType==='percent')>درصد تخفیف</option><option value="final" @selected($pricingType==='final')>قیمت نهایی بسته</option></select></div>
                        <div class="col-md-6"><label class="form-label" data-discount-label>درصد تخفیف</label><div class="input-group" dir="ltr"><input class="form-control" type="text" inputmode="numeric" autocomplete="off" data-discount-display value="{{ $pricingValue }}" aria-describedby="bundleEditDiscountCheck-{{ $product->id }}"><span class="input-group-text" data-discount-unit>٪</span></div><input type="hidden" name="bundle_discount_value" value="{{ $pricingValue }}"><div id="bundleEditDiscountCheck-{{ $product->id }}" data-discount-check class="small mt-1" role="status" aria-live="polite"></div></div>
                    </div>
                    <div class="p-2 mt-2 rounded bg-light small" data-discount-preview>قیمت نهایی و میزان صرفه‌جویی بعد از ورود تخفیف نمایش داده می‌شوند.</div>
                    <button type="submit" class="btn btn-primary btn-sm mt-2">ذخیره تخفیف بسته</button>
                    @if($errors->has('bundle_discount_value') || $errors->has('bundle_discount_type'))<div class="alert alert-danger mt-2">{{ $errors->first('bundle_discount_value') ?: $errors->first('bundle_discount_type') }}</div>@endif
                </form>
                <script>
                (()=>{
                    const form=document.getElementById('bundlePricingForm-{{ $product->id }}');if(!form)return;
                    const type=form.querySelector('[name="bundle_discount_type"]'),input=form.querySelector('[data-discount-display]'),raw=form.querySelector('[name="bundle_discount_value"]'),label=form.querySelector('[data-discount-label]'),preview=form.querySelector('[data-discount-preview]');
                    const unit=form.querySelector('[data-discount-unit]'),checker=form.querySelector('[data-discount-check]');
                    const original=Number(form.dataset.original),base=Number(form.dataset.base),fmt=n=>Math.round(n).toLocaleString('fa-IR');
                    const digits=s=>String(s||'').replace(/[۰-۹]/g,c=>String(c.charCodeAt(0)-1776)).replace(/[٠-٩]/g,c=>String(c.charCodeAt(0)-1632)).replace(/[^0-9]/g,'');
                    const update=()=>{
                      const pct=type.value==='percent',d=digits(input.value);raw.value=d;
                      input.value=!pct&&d?Number(d).toLocaleString('en-US'):d;
                      label.textContent=pct?'درصد تخفیف (۰ تا ۹۹)':'قیمت نهایی بسته (ریال)';unit.textContent=pct?'٪':'ریال';
                      const v=Number(d),final=pct?base-Math.round(base*v/100):v;
                      const ok=d!==''&&base>0&&Number.isSafeInteger(final)&&final>0&&final<=base&&(!pct||v<=99);
                      preview.textContent=ok?`قیمت نهایی: ${fmt(final)} ریال · صرفه‌جویی: ${fmt(original-final)} ریال`:'قیمت نهایی نامعتبر است.';
                      checker.textContent=ok?'✓ مقدار معتبر است.':'درصد تخفیف باید بین ۰ و ۹۹، یا قیمت نهایی بین ۱ و مجموع قیمت قطعات باشد.';
                      checker.className=ok?'small mt-1 text-success':'small mt-1 text-danger';input.setAttribute('aria-invalid',ok?'false':'true');
                      return ok;
                    };
                    type.addEventListener('change',()=>{input.value=type.value==='percent'?'0':String(base);update();});
                    input.addEventListener('input',update);
                    form.addEventListener('submit',e=>{if(!update()){e.preventDefault();input.focus();}});
                    update();
                })();
                </script>
                @endif
                <details class="mb-3"><summary class="small text-muted">حالت پیشرفته: تبدیل به بسته فیزیکی (در صورت نداشتن سابقه)</summary>
                    <form method="POST" action="{{ route('products.bundle.mode', $product) }}" class="mt-2" onsubmit="return confirm('این کار روش تأمین موجودی را تغییر می‌دهد؛ ادامه می‌دهید؟')">@csrf<input type="hidden" name="bundle_stock_mode" value="physical"><button class="btn btn-sm btn-outline-secondary">انتخاب بسته فیزیکی</button></form>
                </details>
            @else
                <div class="border rounded p-3 mb-3">
                    <strong>بسته آماده فیزیکی</strong><p class="text-muted small mb-2">این کالا موجودی مستقلِ مونتاژشده دارد و ترکیب آن پس از مونتاژ قفل می‌شود.</p>
                    <form method="POST" action="{{ route('products.bundle.mode', $product) }}">@csrf<input type="hidden" name="bundle_stock_mode" value="automatic"><button class="btn btn-sm btn-outline-secondary" type="submit">تبدیل به بسته خودکار (فقط بدون فروش، موجودی و مونتاژ)</button></form>
                    @if($errors->has('bundle_stock_mode'))<div class="alert alert-danger mt-2">{{ $errors->first('bundle_stock_mode') }}</div>@endif
                </div>
            @endif
        @endif
        @if (!$bundleVariant)
            <div class="alert alert-warning">برای تعریف بسته، کالا باید دقیقاً یک تنوع واقعی داشته باشد.</div>
        @else
            <div class="rounded border p-3 mb-3" style="background:#f8fafc">
                <div class="text-muted small">شناسه تنوع: {{ $bundleVariant->id }} · {{ $isAutomatic ? 'حالت خودکار' : 'بسته آماده' }}</div>
                @if($isAutomatic)
                    <div class="row g-2 mt-2">
                        <div class="col-md-4"><div class="rounded border bg-white p-2"><div class="small text-muted">تعداد کالا در هر بسته</div><div class="fs-4 fw-bold">{{ number_format($totalPieces) }}</div></div></div>
                        <div class="col-md-4"><div class="rounded border bg-white p-2"><div class="small text-muted">تعداد بسته قابل فروش</div><div class="fs-4 fw-bold">{{ number_format($automaticAvailable) }}</div></div></div>
                        <div class="col-md-4"><div class="rounded border bg-white p-2"><div class="small text-muted">محدودیت سهمیه اختصاصی</div><div class="fs-4 fw-bold">{{ number_format((int) $componentCapacity) }}</div></div></div>
                    </div>
                    <div class="small text-muted mt-2">تعداد کالا در هر بسته با تعداد بسته قابل فروش فرق دارد. تعداد بسته فقط از سهمیه اختصاصی قطعات محاسبه می‌شود؛ با خرید، سهمیه مربوطه کم خواهد شد.</div>
                    @if($automaticAvailable === 0)<div class="alert alert-warning mt-2 mb-0 py-2">موجودی اختصاصی برای ساخت یک بسته کامل کافی نیست. از فرم بالا موجودی واقعی و آزاد قطعات را به این بسته اختصاص دهید.</div>@endif
                @else
                    <div class="fs-4 fw-bold">{{ number_format((int) $bundleVariant->stock) }} <small class="fs-6">بسته آماده</small></div>
                @endif
            </div>
            @if ($recipeLocked)
                <div class="alert alert-secondary">ویرایش ترکیب فعلاً قفل است. برای بسته خودکار، از بخش «انتشار و همگام‌سازی در سایت» انتشار را موقتاً متوقف کنید، موجودی اختصاصی بالا را صفر کرده و ذخیره کنید؛ سپس قطعات را ویرایش کنید. بسته مونتاژشده یا دارای سابقه فروش، به محصول جدید نیاز دارد.</div>
            @endif
            @if (!$recipeLocked)
                <form method="POST" action="{{ route('products.bundle.recipe', $product) }}" data-bundle-payload="components" class="mb-4" id="bundle-recipe-form-{{ $product->id }}">
                    @csrf
                    <input type="hidden" name="bundle_variant_id" value="{{ $bundleVariant->id }}">
                    <h6>قطعات مورد نیاز برای ساخت یک بسته</h6>
                    <p class="small text-muted">کالای موردنظر و مدل/رنگ دقیق آن را انتخاب کنید. تعداد هر تنوع <strong>در یک بسته</strong> را تعیین کنید. موجودی قابل فروش از تعداد بسته‌های اختصاص‌یافته محاسبه می‌شود.</p>
                    @unless($product->is_bundle)
                        <div class="alert alert-info py-2 small">با ثبت این دستور ساخت، <strong>همین کالای فعلی</strong> به بسته فیزیکی تبدیل می‌شود؛ کالای جدیدی ایجاد نمی‌شود. برای حفظ کالای عادی، ابتدا یک کالای مجزا برای بسته بسازید.</div>
                    @endunless

                    <div id="bundle-editor-{{ $product->id }}"
                         data-search-url="{{ route('products.bundle.search') }}"
                         data-bundle-variant="{{ $bundleVariant->id }}"
                         data-confirm-conversion="{{ $product->is_bundle ? '0' : '1' }}">
                        <label for="bundle-component-search-{{ $product->id }}" class="form-label fw-semibold">افزودن قطعه به بسته</label>
                        <div class="position-relative mb-3">
                            <input id="bundle-component-search-{{ $product->id }}" type="search" class="form-control" placeholder="جستجو با نام کالا، کد یا SKU (حداقل ۲ حرف)" autocomplete="off" aria-controls="bundle-component-results-{{ $product->id }}" aria-expanded="false">
                            <div id="bundle-component-results-{{ $product->id }}" class="list-group position-absolute w-100 shadow-sm overflow-auto" style="z-index: 25;max-height: 280px;display: none;" role="listbox" aria-label="نتایج جستجوی کالا"></div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
                            <strong>قطعات انتخاب‌شده</strong>
                            <span class="text-muted small" id="bundle-selected-summary-{{ $product->id }}"></span>
                        </div>
                        <div id="bundle-selected-items-{{ $product->id }}" class="mb-3" aria-live="polite"></div>
                        <div id="bundle-empty-{{ $product->id }}" class="text-muted small border rounded p-3 mb-3">هنوز هیچ قطعه‌ای اضافه نشده است. از جستجوی بالا استفاده کنید.</div>
                    </div>
                    @if($errors->has('components') || $errors->has('components.*.variant_id') || $errors->has('components.*.quantity') || $errors->has('components.*.discount_value'))
                        <div class="alert alert-danger">{{ $errors->first('components') ?: $errors->first('components.*.variant_id') ?: $errors->first('components.*.quantity') ?: $errors->first('components.*.discount_value') }}</div>
                    @endif
                    <button class="btn btn-outline-primary" type="submit">ثبت دستور ساخت</button>
                </form>
                <script>
                (() => {
                    const root = document.getElementById('bundle-editor-{{ $product->id }}');
                    if (!root) return;
                    const form = document.getElementById('bundle-recipe-form-{{ $product->id }}');
                    const search = document.getElementById('bundle-component-search-{{ $product->id }}');
                    const results = document.getElementById('bundle-component-results-{{ $product->id }}');
                    const selectedList = document.getElementById('bundle-selected-items-{{ $product->id }}');
                    const summary = document.getElementById('bundle-selected-summary-{{ $product->id }}');
                    const empty = document.getElementById('bundle-empty-{{ $product->id }}');
                    const selected = new Map();
                    const initial = {{ \Illuminate\Support\Js::from($selectedComponents) }};
                    for (const item of initial) if (Number(item.id) > 0) selected.set(Number(item.id), {...item, quantity: Number(item.quantity) || 1});
                    const bundleVariantId = Number(root.dataset.bundleVariant);
                    let delay;
                    let controller;
                    let revision = 0;
                    const info = (message) => { results.replaceChildren(); const el = document.createElement('div'); el.className = 'list-group-item text-muted small'; el.textContent = message; results.append(el); results.style.display = 'block'; search.setAttribute('aria-expanded', 'true'); };
                    const hide = () => { results.style.display = 'none'; search.setAttribute('aria-expanded', 'false'); };
                    const render = () => {
                        selectedList.replaceChildren();
                        let pieces = 0;
                        [...selected.values()].forEach((item, index) => {
                            pieces += Number(item.quantity) || 0;
                            const row = document.createElement('div');
                            row.className = 'd-flex flex-wrap align-items-center gap-2 border rounded p-2 mb-2';
                            const title = document.createElement('div');
                            title.className = 'flex-grow-1';
                            const strong = document.createElement('strong');
                            strong.textContent = item.name;
                            const detail = document.createElement('div');
                            detail.className = 'small text-muted';
                            detail.textContent = `${item.variant || 'تنوع اصلی'}${item.code ? ' · ' + item.code : ''} · موجودی: ${item.stock ?? 'نامشخص'}`;
                            title.append(strong, detail);
                            const id = document.createElement('input');
                            id.type = 'hidden'; id.name = `components[${index}][variant_id]`; id.value = item.id;
                            const quantity = document.createElement('input');
                            quantity.type = 'number'; quantity.min = '1'; quantity.max = '100000'; quantity.required = true;
                            quantity.className = 'form-control'; quantity.style.maxWidth = '125px'; quantity.style.direction = 'ltr';
                            quantity.name = `components[${index}][quantity]`; quantity.value = item.quantity;
                            quantity.setAttribute('aria-label', `تعداد ${item.name} در هر بسته`);
                            quantity.addEventListener('input', () => { item.quantity = quantity.value; summary.textContent = `${selected.size} تنوع · ${[...selected.values()].reduce((sum, part) => sum + (Number(part.quantity) || 0), 0)} قطعه در هر بسته`; });
                            const discountWrap=document.createElement('div');discountWrap.className='d-flex flex-wrap gap-1 align-items-center';
                            const discountLabel=document.createElement('label');discountLabel.className='small text-muted';discountLabel.textContent='تخفیف هر عدد';
                            const discountType=document.createElement('select');discountType.className='form-select form-select-sm';discountType.style.width='94px';discountType.name=`components[${index}][discount_type]`;
                            for(const [value,label] of [['percent','درصد ٪'],['fixed','ریال']]){const option=document.createElement('option');option.value=value;option.textContent=label;discountType.append(option);}
                            discountType.value=item.discount_type||'percent';
                            const discountValue=document.createElement('input');discountValue.type='number';discountValue.min='0';discountValue.step='1';discountValue.required=true;discountValue.className='form-control form-control-sm';discountValue.style.width='105px';discountValue.dir='ltr';
                            discountValue.value=item.discount_value??0;discountValue.name=`components[${index}][discount_value]`;
                            discountValue.setAttribute('aria-label',`تخفیف هر عدد ${item.name}`);
                            const limit=()=>{discountValue.max=discountType.value==='percent'?'99':String(Math.max(0,Number(item.sell_price||0)-1));};
                            discountType.addEventListener('change',()=>{item.discount_type=discountType.value;item.discount_value=0;discountValue.value='0';limit();});
                            discountValue.addEventListener('input',()=>{item.discount_value=discountValue.value;});
                            limit();discountWrap.append(discountLabel,discountType,discountValue);
                            const less = document.createElement('button');
                            less.type = 'button'; less.className = 'btn btn-sm btn-outline-secondary'; less.textContent = '−';
                            const more = document.createElement('button');
                            more.type = 'button'; more.className = 'btn btn-sm btn-outline-secondary'; more.textContent = '+';
                            less.addEventListener('click', () => { quantity.value= Math.max(1, (Number(quantity.value) || 1) - 1); quantity.dispatchEvent(new Event('input')); });
                            more.addEventListener('click', () => { quantity.value = Math.min(100000, (Number(quantity.value) || 1) + 1); quantity.dispatchEvent(new Event('input')); });
                            const remove = document.createElement('button');
                            remove.type = 'button'; remove.className = 'btn btn-outline-danger btn-sm'; remove.textContent = 'حذف';
                            remove.setAttribute('aria-label', `حذف ${item.name}`);
                            remove.addEventListener('click', () => { selected.delete(item.id); render(); });
                            row.append(title, id, less, quantity, more, discountWrap, remove);
                            selectedList.append(row);
                        });
                        empty.hidden = selected.size > 0;
                        summary.textContent = `${selected.size} تنوع · ${pieces} قطعه در هر بسته`;
                    };
                    const add = item => {
                        if (selected.has(Number(item.id))) { hide(); search.value = ''; search.focus(); return; }
                        if (selected.size >= 100) { info('حداکثر ۱۰۰ قطعه مجاز است.'); return; }
                        selected.set(Number(item.id), {...item, quantity: 1, discount_type:'percent', discount_value:0});
                        render(); hide(); search.value = ''; search.focus();
                    };
                    const fetchResults = async term => {
                        const thisRevision = ++revision;
                        if (controller) controller.abort();
                        if (term.length < 2) { hide(); return; }
                        controller = new AbortController();
                        info('در حال جستجو...');
                        try {
                            const url = new URL(root.dataset.searchUrl, location.origin);
                            url.searchParams.set('q', term);
                            url.searchParams.set('exclude_variant_id', String(bundleVariantId));
                            const response = await fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin', signal: controller.signal});
                            if (!response.ok) throw new Error(`HTTP ${response.status}`);
                            const payload = await response.json();
                            if (thisRevision !== revision) return;
                            results.replaceChildren();
                            if (!(payload.results || []).length) { info('کالایی پیدا نشد.'); return; }
                            const byProduct = new Map();
                            for (const item of payload.results) {
                                const id = Number(item.product_id);
                                if (!byProduct.has(id)) byProduct.set(id, {name:item.name, variants:[]});
                                byProduct.get(id).variants.push(item);
                            }
                            for (const group of byProduct.values()) {
                                const groupEl = document.createElement('details');groupEl.className='list-group-item';
                                const heading = document.createElement('summary');heading.className='fw-semibold';heading.style.cursor='pointer';heading.textContent=`${group.name} — انتخاب تنوع (${group.variants.length})`;
                                groupEl.append(heading);
                                for (const item of group.variants) {
                                    const option=document.createElement('button');option.type='button';option.className='btn btn-sm btn-outline-primary d-block w-100 text-end my-1';
                                    option.disabled=selected.has(Number(item.id));option.setAttribute('role','option');
                                    option.textContent=`${item.variant||'اصلی'} · ${item.code||''} · موجودی آزاد: ${item.stock}`;
                                    option.addEventListener('click',()=>add(item));groupEl.append(option);
                                }
                                if(byProduct.size===1)groupEl.open=true;
                                results.append(groupEl);
                            }
                            results.style.display = 'block'; search.setAttribute('aria-expanded', 'true');
                        } catch (error) {
                            if (error.name !== 'AbortError' && thisRevision === revision) info('جستجو انجام نشد؛ دوباره تلاش کنید.');
                        }
                    };
                    search.addEventListener('input', () => { clearTimeout(delay); const term = search.value.trim(); if (term.length < 2) { revision++; if (controller) controller.abort(); hide(); return; } delay = setTimeout(() => fetchResults(term), 250); });
                    search.addEventListener('keydown', event => { if (event.key === 'Escape') hide(); });
                    document.addEventListener('click', event => { if (!root.contains(event.target)) hide(); });
                    form.addEventListener('submit', event => {
                        if (selected.size === 0) { event.preventDefault(); window.alert('ابتدا حداقل یک قطعه انتخاب کنید.'); search.focus(); return; }
                        if (root.dataset.confirmConversion === '1' && !window.confirm('این کالای فعلی به بسته فیزیکی تبدیل می‌شود. ادامه می‌دهید؟')) event.preventDefault();
                    });
                    render();
                })();
                </script>
            @endif
            @if ($components->isNotEmpty())
                <h6>ترکیب فعلی</h6>
                <ul>
                    @foreach ($components as $component)
                        <li>{{ $component->product?->name }} — {{ $component->variant_name }} (#{{ $component->id }}) × {{ $component->pivot->quantity }}
                            <small class="text-muted">| موجودی مرکزی: {{ number_format(max(0, (int) ($componentStock[$component->id] ?? 0))) }} | حداکثر بسته از این قطعه: {{ number_format(intdiv(max(0, (int) ($componentStock[$component->id] ?? 0)), max(1, (int) $component->pivot->quantity))) }}</small>
                        </li>
                    @endforeach
                </ul>
                @if(!$isAutomatic)
                <div class="row g-3 mt-3">
                    @foreach (['assemble' => 'مونتاژ بسته', 'disassemble' => 'باز کردن بسته'] as $action => $label)
                        <div class="col-md-6">
                            <form method="POST" action="{{ route('products.bundle.'.$action, $product) }}" class="d-flex gap-2">
                                @csrf
                                <input type="hidden" name="bundle_variant_id" value="{{ $bundleVariant->id }}">
                                <input class="form-control" aria-label="تعداد بسته" type="number" name="quantity" min="1" max="100000" placeholder="تعداد" required>
                                <button type="submit" class="btn btn-{{ $action === 'assemble' ? 'success' : 'outline-warning' }}">{{ $label }}</button>
                            </form>
                        </div>
                    @endforeach
                </div>
                @else
                    <div class="alert alert-info mt-3">این بسته خودکار است؛ دکمه مونتاژ ندارد. در سفارش سایت، قطعات لازم باهم رزرو می‌شوند.
                    <strong> خرید اینترنتی فقط با فعال بودن سرویس رزرو اتمیک Inventory مجاز است.</strong></div>
                @endif
            @endif
            @if ($assemblyHistory->isNotEmpty())
                <h6 class="mt-4">آخرین عملیات</h6>
                <ul class="small">
                    @foreach ($assemblyHistory as $assembly)
                        <li>{{ $assembly->created_at }} — {{ $assembly->operation }}: {{ $assembly->quantity }}</li>
                    @endforeach
                </ul>
            @endif
        @endif
    </div>
</section>
