@once
<link rel="stylesheet" href="{{ asset('css/bundle-management.css') }}?v=20261121c">
<script src="{{ asset('js/bundle-form-payload.js') }}?v=20261024"></script>
@endonce
@php
    // The commercial bundle is one selling SKU; availability can be automatic or physically assembled.
    $bundleVariant = $product->variants->count() === 1 ? $product->variants->first() : null;
    $components = $bundleVariant ? $bundleVariant->bundleComponents()->with('product')->get() : collect();
    $assemblyHistory = $bundleVariant
        ? \App\Models\BundleAssembly::where('bundle_variant_id', $bundleVariant->id)->latest()->limit(8)->get()
        : collect();
    $isAutomatic = (bool) $product->is_bundle && $product->bundle_stock_mode === 'automatic';
    $automaticAvailable = $isAutomatic && $bundleVariant ? app(\App\Services\AutomaticBundleAvailabilityService::class)->available((int) $bundleVariant->id) : 0;
    $isShared = $isAutomatic && ($product->bundle_quantity_mode ?? 'custom') === 'shared';
    // The regular availability service intentionally returns zero for stopped sales;
    // readiness must inspect the still-protected allocation instead.
    $resumeIssues = $product->exists && $product->is_bundle && !$product->is_sellable && $bundleVariant
        ? app(\App\Services\ProductBundleService::class)->resumeReadinessIssues($product, $bundleVariant)
        : [];
    $totalPieces = $components->sum(fn ($part) => (int) $part->pivot->quantity);
    $allocations = $bundleVariant && $isAutomatic ? \Illuminate\Support\Facades\DB::table('bundle_component_allocations')->where('bundle_variant_id', $bundleVariant->id)->pluck('remaining','component_variant_id') : collect();
    $componentCapacity = $components->isNotEmpty() ? $components->map(fn ($part) => intdiv(max(0, (int) ($allocations[$part->id] ?? 0)), max(1, (int) $part->pivot->quantity)))->min() : 0;
    $sitePublished = $product->is_bundle ? \Illuminate\Support\Facades\DB::connection('site')->table('products')->where('id', $product->id)->where('published', 1)->exists() : false;
    $finishedStockExists = $bundleVariant && \App\Models\WarehouseStock::where('product_variant_id', $bundleVariant->id)->where('quantity', '>', 0)->exists();
    $compositionNeedsStockRelease = $isAutomatic && ($sitePublished || $allocations->sum() > 0);
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

    // Preserve selected components and their labels after validation redirects.
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

<section class="card mt-4 bundle-shell" aria-label="Bundle configuration" dir="rtl">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong>مدیریت بسته ترکیبی</strong>
        <span class="chip chip-soft">Bundle SKU</span>
    </div>
    <div class="card-body">
        <div class="kit-overview kit-preview bundle-hero mb-3" role="status">
            <div><small>نام / کد</small><strong>{{ $product->name }} · {{ $product->code }}</strong></div>
            <div><small>تنوع / تعداد در بسته</small><strong>{{ $components->count() }} / {{ number_format($totalPieces) }}</strong></div>
            <div><small>موجودی قابل فروش</small><strong>{{ $isAutomatic ? number_format($automaticAvailable) : number_format((int) ($bundleVariant?->stock ?? 0)) }}</strong></div>
            <div><small>قیمت ذخیره‌شده</small><strong>{{ number_format((int) ($bundleVariant?->sell_price ?? 0)) }} ریال</strong></div>
            <div><small>وضعیت</small><strong>{{ $product->bundle_archived_at ? 'بایگانی‌شده' : ($product->is_sellable ? 'فروش فعال' : 'فروش متوقف') }}</strong></div>
        </div>
        @if($product->is_bundle)
        <div class="bundle-action-nav mb-3" role="tablist" aria-label="بخش‌های ویرایش بسته">
            <button type="button" role="tab" data-bundle-tab="price" aria-selected="true" class="bundle-action-chip is-active">قیمت و تخفیف</button>
            <button type="button" role="tab" data-bundle-tab="stock" aria-selected="false" class="bundle-action-chip">موجودی</button>
            <button type="button" role="tab" data-bundle-tab="recipe" aria-selected="false" class="bundle-action-chip">قطعات</button>
            <button type="button" role="tab" data-bundle-tab="site" aria-selected="false" class="bundle-action-chip">فروش در سایت</button>
            <button type="button" role="tab" data-bundle-tab="actions" aria-selected="false" class="bundle-action-chip">مدیریت</button>
            <button type="button" role="tab" data-bundle-tab="basic" aria-selected="false" class="bundle-action-chip bundle-basic-nav">نام و تصویر</button>
        </div>
        @endif
        <p class="text-muted small bundle-subtle">هر بخش را جداگانه ویرایش و ذخیره کنید. سفارش‌های قبلی تغییر نمی‌کنند.</p>
        @if($product->is_bundle && !$product->is_sellable && $bundleVariant)
            <div class="border rounded p-3 mb-3 bundle-resume-box" id="bundle-resume-guide-{{ $product->id }}" aria-label="راهنمای فعال‌سازی دوباره بسته">
                <h6 class="mb-2">راهنمای فعال‌سازی دوباره فروش</h6>
                @if($isAutomatic)
                    <p class="small text-muted">سهمیه قطعات برای {{ number_format((int) $componentCapacity) }} بسته کامل موجود است. تا وقتی فروش متوقف باشد، موجودی قابل فروش صفر می‌ماند.</p>
                @endif
                @if($resumeIssues)
                    <p class="small text-muted mb-2">موارد زیر را برطرف کنید و سپس از بخش عملیات، فروش را دوباره فعال کنید.</p>
                    <ul class="small mb-2">
                        @foreach($resumeIssues as $issue)
                            <li class="mb-2">
                                {{ $issue['message'] }}
                                @if($issue['step'] !== 'variant')
                                    <a href="#bundle-resume-{{ $issue['step'] }}-{{ $product->id }}" data-bundle-resume-target="bundle-resume-{{ $issue['step'] }}-{{ $product->id }}" class="ms-1">رفتن به بخش مربوط</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p class="small text-muted mb-0">این راهنما فقط وضعیت فروش را آماده می‌کند و روی سفارش‌های قبلی اثری ندارد.</p>
                @else
                    <div class="text-success small">✓ قطعات، قیمت و موجودی برای ادامه فروش آماده‌اند.</div>
                    <a href="#bundle-resume-actions-{{ $product->id }}" data-bundle-resume-target="bundle-resume-actions-{{ $product->id }}" class="small">رفتن به دکمه ادامه فروش</a>
                @endif
            </div>
            <script>
            (() => {
                document.querySelectorAll('[data-bundle-resume-target]').forEach(link => {
                    link.addEventListener('click', () => {
                        const target = document.getElementById(link.dataset.bundleResumeTarget);
                        if (target?.tagName === 'DETAILS') target.open = true;
                    });
                });
            })();
            </script>
        @endif
        @if($product->is_bundle && $isAutomatic && $sitePublished && !$product->is_sellable)
            <div class="alert alert-danger" role="alert">ناهماهنگی انبار/سایت: فروش در انبار متوقف است اما سبد هنوز در سایت منتشر شده است.</div>
        @endif
        @if($product->is_bundle)
            @if($isAutomatic)
                <details class="bundle-card" data-bundle-panel="site" id="bundle-resume-site-{{ $product->id }}"><summary>انتشار در سایت</summary>
                <section class="rounded border p-3 mb-3" aria-label="Website publication status">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h6 class="mb-0">انتشار و همگام‌سازی در سایت</h6>
                        <span class="badge {{ $sitePublished ? 'bg-success' : 'bg-secondary' }}">{{ $sitePublished ? 'منتشر شده در سایت' : 'منتشر نشده در سایت' }}</span>
                    </div>
                    <p class="small text-muted mt-2 mb-2">قیمت و موجودی بسته با سایت همگام‌سازی می‌شود.</p>
                    @if($product->bundle_site_auto_publish === null)
                        <div class="alert alert-info py-2 small">این بسته قدیمی هنوز انتشارش را از سایت می‌گیرد. با گزینه زیر کنترل انتشار را به انبار منتقل کنید.</div>
                    @elseif(!$product->bundle_site_auto_publish)
                        <div class="alert alert-warning py-2 small">انتشار برای ویرایش متوقف است. پس از ذخیره ترکیب جدید و تعیین سهمیه قطعات تازه، دوباره از همین‌جا منتشر کنید.</div>
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
                    <div class="small text-muted mt-2">برای تغییر قطعات: ۱. توقف انتشار ۲. تغییر و ذخیره قطعات (سهمیه قطعات حذف‌شده خودکار آزاد می‌شود) ۳. تعیین سهمیه قطعات جدید ۴. انتشار مجدد.</div>
                </section>
                </details>
                <details class="bundle-card" data-bundle-panel="stock" id="bundle-resume-stock-{{ $product->id }}"><summary>تعداد بسته و موجودی قطعات</summary>
                @if(($product->bundle_quantity_mode ?? 'custom') !== 'shared')
                    <div class="alert alert-warning">این بسته با روش قدیمی موجودی جداگانه ساخته شده است. پیش از تغییر ترکیب، سفارش‌های فعال را بررسی و انتشار سایت را متوقف کنید. سهمیه قطعات حذف‌شده خودکار آزاد می‌شود؛ سهمیه قطعات جدید را پس از ذخیره تنظیم کنید.</div>
                @else
                    <form id="bundleAllocationForm-{{ $product->id }}" method="POST" action="{{ route('products.bundle.policy', $product) }}" data-bundle-payload="allocations expected_allocations" class="border rounded p-3 mb-3">
                        @csrf
                        <input type="hidden" name="bundle_quantity_mode" value="shared">
                        @foreach($components as $component)
                            <input type="hidden" name="expected_allocations[{{ $component->id }}]" value="{{ (int) ($allocations[$component->id] ?? 0) }}">
                        @endforeach
                        <h6>موجودی اختصاصی این بسته</h6>
                        <p class="small text-muted">کاهش تعداد، قطعات آزادشده را به موجودی عادی برمی‌گرداند. افزایش، قطعات موردنیاز را کنار می‌گذارد؛ رزروهای قبلی محفوظ‌اند.</p>
                        <label class="form-label" for="sharedQty-{{ $product->id }}">تعداد بسته قابل فروش (موجودی یکسان)</label>
                        <input id="sharedQty-{{ $product->id }}" class="form-control" style="max-width:230px" type="number" name="bundle_shared_quantity" min="0" max="1000000" required value="{{ old('bundle_shared_quantity', $componentCapacity) }}">
                        <div class="mt-2 small text-muted">موجودی آزاد جداگانه برای هر قطعه:</div>
                        @foreach($components as $component)
                            <div class="d-flex justify-content-between flex-wrap small border-bottom py-1 gap-2" data-bundle-part data-per="{{ (int) $component->pivot->quantity }}" data-free="{{ max(0, (int) ($componentStock[$component->id] ?? 0)) }}" data-remaining="{{ max(0, (int) ($allocations[$component->id] ?? 0)) }}"><span>{{ $component->product?->name }} — {{ $component->variant_name }}</span><span>در هر بسته: {{ number_format((int) $component->pivot->quantity) }} · آزاد: {{ number_format($componentStock[$component->id] ?? 0) }} · سهم فعلی: {{ number_format($allocations[$component->id] ?? 0) }} <strong data-stock-change></strong></span></div>
                        @endforeach
                        <div class="small mt-2" data-allocation-preview role="status" aria-live="polite"></div>
                        <p class="small text-muted mt-2">قطعات آزادشده پس از ذخیره، موجودی عادی قابل فروش همان تنوع می‌شوند؛ موجودی اختصاصی دیگر برای فروش تکی قابل استفاده نیست.</p>
                        <label class="form-label small" for="bundleAllocationReason-{{ $product->id }}">علت تغییر موجودی</label>
                        <input id="bundleAllocationReason-{{ $product->id }}" class="form-control form-control-sm mb-2" style="max-width:440px" name="reason" minlength="10" maxlength="500" required value="{{ old('reason') }}" placeholder="مثلاً کاهش تعداد بسته و بازگرداندن قطعات آزاد">
                        <button class="btn btn-primary btn-sm" type="submit" data-allocation-submit>ثبت تغییر موجودی بسته</button>
                        @if($errors->has('reason'))<div class="text-danger small">{{ $errors->first('reason') }}</div>@endif
                        @if($errors->has('bundle_shared_quantity') || $errors->has('allocations') || $errors->has('bundle_quantity_mode'))
                            <div class="alert alert-danger mt-2">{{ $errors->first('bundle_shared_quantity') ?: ($errors->first('allocations') ?: $errors->first('bundle_quantity_mode')) }}</div>
                        @endif
                    </form>
                    <script>
                    (() => {
                        const form = document.getElementById('bundleAllocationForm-{{ $product->id }}');
                        if (!form) return;
                        const qty = form.querySelector('[name="bundle_shared_quantity"]');
                        const preview = form.querySelector('[data-allocation-preview]');
                        const submit = form.querySelector('[data-allocation-submit]');
                        const parts = form.querySelectorAll('[data-bundle-part]');
                        const update = () => {
                            const count = Number(qty.value);
                            let returning = 0, taking = 0, short = 0, valid = qty.value.trim() !== '' && Number.isSafeInteger(count) && count >= 0 && count <= 1000000;
                            for (const row of parts) {
                                const per = Number(row.dataset.per), free = Number(row.dataset.free), current = Number(row.dataset.remaining);
                                const desired = count * per, delta = current - desired;
                                if (!Number.isSafeInteger(desired) || desired > 1000000 || desired < 0) valid = false;
                                const missing = Math.max(0, -delta - free);
                                if (delta >= 0) returning += delta;
                                else { taking -= delta; short += missing; if (missing) valid = false; }
                                row.querySelector('[data-stock-change]').textContent = missing
                                    ? `· کسری ${missing} قطعه` : delta > 0
                                    ? `· بازگشت ${delta} ← آزاد جدید ${free + delta}`
                                    : delta < 0 ? `· برداشت ${-delta} ← آزاد جدید ${free + delta}` : '';
                            }
                            submit.disabled = !valid;
                            preview.className = valid ? 'small mt-2 text-success' : 'small mt-2 text-danger';
                            preview.textContent = valid
                                ? `بازگشت به موجودی عادی: ${returning} قطعه · برداشت از موجودی عادی: ${taking} قطعه`
                                : `تعداد نامعتبر یا کسری موجودی ${short} قطعه؛ ابتدا موجودی آزاد را بررسی کنید.`;
                        };
                        qty.addEventListener('input', update);
                        form.addEventListener('submit', event => {
                            update();
                            if (submit.disabled || !confirm('این تعداد از سهمیه بسته و موجودی آزاد هر تنوع در یک تراکنش جابه‌جا شود؟')) event.preventDefault();
                        });
                        update();
                    })();
                    </script>
                @endif
                </details>
                <details class="bundle-card" data-bundle-panel="price" id="bundle-resume-price-{{ $product->id }}"><summary>قیمت و تخفیف بسته</summary>
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
                    <div class="small mt-2">قیمت ذخیره‌شده فعلی: <strong>{{ number_format((int) $bundleVariant->sell_price) }}</strong> ریال</div><div class="p-2 mt-2 rounded bg-light small" data-discount-preview>قیمت نهایی و میزان صرفه‌جویی بعد از ورود تخفیف نمایش داده می‌شوند.</div>
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
                </details>
                <details class="bundle-card" data-bundle-panel="actions"><summary class="small text-muted">تبدیل به بسته فیزیکی برای فروش از موجودی آماده</summary>
                    <form method="POST" action="{{ route('products.bundle.mode', $product) }}" class="mt-2" onsubmit="return confirm('سهمیه قطعات معادل بسته‌های آماده مصرف و باقی‌مانده به موجودی آزاد بازمی‌گردد. از تطبیق واقعی قطعات مطمئن هستید؟')">
                        @csrf<input type="hidden" name="bundle_stock_mode" value="physical">
                        <p class="small text-muted">فقط وقتی موجودی فیزیکی واقعاً آماده است. اگر قطعات اختصاص‌یافته دارید، باید بسته‌های آماده از همان قطعات ساخته شده باشند؛ سهمیه مربوط مصرف می‌شود و باقی‌مانده آزاد خواهد شد. اگر سهمیه‌ای ندارید، موجودی بسته باید قبلاً مستقل و به‌درستی ثبت شده باشد. تغییر حالت با سفارش/رزرو فعال مجاز نیست.</p>
                        <label class="small d-block mb-2"><input type="checkbox" name="confirm_physical_conversion" value="1" required> تأیید می‌کنم بسته‌های آماده واقعاً موجودند و موجودی قطعات آن‌ها به‌درستی ثبت شده است.</label>
                        <button class="btn btn-sm btn-outline-secondary" type="submit">تبدیل به بسته فیزیکی</button>
                        @if($errors->has('bundle_stock_mode'))<div class="alert alert-danger mt-2">{{ $errors->first('bundle_stock_mode') }}</div>@endif
                    </form>
                </details>
            @else
                <div class="border rounded p-3 mb-3" data-bundle-panel="stock">
                    <strong>بسته آماده فیزیکی</strong><p class="text-muted small mb-2">این کالا موجودی مستقلِ مونتاژشده دارد. می‌توانید ترکیب بسته را حتی با موجودی مثبت تغییر دهید؛ ویرایش دستور ساخت موجودی فعلی را تغییر نمی‌دهد و بسته‌های آماده قبلی ممکن است همچنان ترکیب قدیمی را داشته باشند.</p>
                    <form method="POST" action="{{ route('products.bundle.mode', $product) }}">@csrf<input type="hidden" name="bundle_stock_mode" value="automatic"><button class="btn btn-sm btn-outline-secondary" type="submit">تبدیل به بسته خودکار (فقط بدون فروش، موجودی و مونتاژ)</button></form>
                    @if($errors->has('bundle_stock_mode'))<div class="alert alert-danger mt-2">{{ $errors->first('bundle_stock_mode') }}</div>@endif
                </div>
            @endif
        @endif
        @if($product->is_bundle && $bundleVariant)
        <details class="bundle-card" data-bundle-panel="actions" id="bundle-resume-actions-{{ $product->id }}" @if($errors->has('keep') || $errors->has('enabled') || $errors->has('restore')) open @endif>
            <summary>توقف فروش، آزادسازی و بایگانی</summary>
            <div class="p-3">
                <form method="POST" action="{{ route('products.bundle.sale', $product) }}" class="border rounded p-3 mb-3">
                    @csrf
                    <input type="hidden" name="enabled" value="{{ $product->is_sellable ? '0' : '1' }}">
                    <label class="form-label" for="bundleSaleReason-{{ $product->id }}">علت {{ $product->is_sellable ? 'توقف' : 'ادامه' }} فروش</label>
                    <input id="bundleSaleReason-{{ $product->id }}" name="reason" required minlength="10" maxlength="500" class="form-control mb-2" placeholder="علت عملیاتی (حداقل ۱۰ حرف)">
                    <button type="submit" class="btn btn-sm {{ $product->is_sellable ? 'btn-outline-warning' : 'btn-success' }}" @if($product->bundle_archived_at || (!$product->is_sellable && $resumeIssues)) disabled @endif onclick="return confirm('وضعیت فروش در انبار و سایت تغییر کند؟ سفارش‌ها و رزروهای قبلی محفوظ هستند.')">{{ $product->is_sellable ? 'توقف فروش' : 'ادامه فروش با کنترل موجودی و قیمت' }}</button>
                    @if($errors->has('enabled'))<div class="text-danger small">{{ $errors->first('enabled') }}</div>@endif
                </form>
                @if($isAutomatic)
                <form id="bundleReleaseForm-{{ $product->id }}" method="POST" action="{{ route('products.bundle.release', $product) }}" data-preview-url="{{ route('products.bundle.release-preview', $product) }}" class="border rounded p-3 mb-3">
                    @csrf
                    <h6>آزادسازی فقط سهمیه آزاد فروش‌نرفته</h6>
                    <p class="text-muted small">رزروهای فعال پیش‌فاکتور، سفارش سایت یا بسته آماده فیزیکی آزاد نمی‌شوند. تغییر سهمیه و انبار در یک تراکنش انجام می‌شود.</p>
                    <label class="form-label" for="bundleReleaseKeep-{{ $product->id }}">چند بسته از سهمیه آزاد باقی بماند؟</label>
                    <input type="number" min="0" max="1000000" required value="0" name="keep" id="bundleReleaseKeep-{{ $product->id }}" class="form-control mb-2" style="max-width:180px">
                    <label class="form-label" for="bundleReleaseReason-{{ $product->id }}">علت آزادسازی</label>
                    <input id="bundleReleaseReason-{{ $product->id }}" type="text" name="reason" required minlength="10" maxlength="500" class="form-control mb-2" placeholder="علت عملیاتی و شماره درخواست">
                    <input type="hidden" name="preview_token" value="">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-release-preview>پیش‌نمایش ردیف‌به‌ردیف</button>
                    <div data-release-result class="my-2 small" role="status" aria-live="polite"></div>
                    <button type="submit" class="btn btn-sm btn-danger" disabled data-release-apply>تأیید نهایی و آزادسازی</button>
                    @if($errors->has('keep') || $errors->has('preview_token'))<div class="text-danger small mt-2">{{ $errors->first('keep') ?: $errors->first('preview_token') }}</div>@endif
                </form>
                @endif
                <form method="POST" action="{{ route('products.bundle.archive', $product) }}" class="border rounded p-3 mb-3">
                    @csrf
                    <input type="hidden" name="restore" value="{{ $product->bundle_archived_at ? '1' : '0' }}">
                    <p class="small text-muted mb-2">برای بایگانی: توقف فروش، رفع رزروهای فعال و تعیین تکلیف کامل موجودی آزاد الزامی است. بازگردانی خودکار فروش یا موجودی نمی‌سازد.</p>
                    <label class="form-label" for="bundleArchiveReason-{{ $product->id }}">علت عملیات</label>
                    <input id="bundleArchiveReason-{{ $product->id }}" name="reason" required minlength="10" maxlength="500" class="form-control mb-2">
                    <button type="submit" class="btn btn-outline-secondary btn-sm" onclick="return confirm('وضعیت بایگانی سبد تغییر کند؟ سابقه فاکتور و انبار دست‌نخورده باقی می‌ماند.')">{{ $product->bundle_archived_at ? 'بازگردانی از بایگانی (بدون فعال‌سازی فروش)' : 'بایگانی غیرمخرب' }}</button>
                    @if($errors->has('restore'))<div class="text-danger small">{{ $errors->first('restore') }}</div>@endif
                </form>
                @if(\Illuminate\Support\Facades\Schema::hasTable('bundle_management_events'))
                    @php $events = \Illuminate\Support\Facades\DB::table('bundle_management_events')->where('bundle_variant_id', $bundleVariant->id)->orderByDesc('id')->limit(8)->get(); @endphp
                    <details><summary>آخرین رویدادهای مدیریت سبد</summary><ul class="small mt-2">@forelse($events as $event)<li>{{ $event->created_at }} · {{ $event->action }} · {{ $event->reason }} · {{ $event->event_id }}</li>@empty<li>رویدادی ثبت نشده است.</li>@endforelse</ul></details>
                @endif
            </div>
        </details>
        @if($isAutomatic)
        <script>
        (()=>{
            const form=document.getElementById('bundleReleaseForm-{{ $product->id }}');if(!form)return;
            const result=form.querySelector('[data-release-result]'),button=form.querySelector('[data-release-apply]'),token=form.querySelector('[name=preview_token]'),keep=form.querySelector('[name=keep]');
            const reset=()=>{token.value='';button.disabled=true;result.replaceChildren();};
            keep.addEventListener('input',reset);
            form.querySelector('[data-release-preview]').addEventListener('click',async()=>{
                reset();result.textContent='در حال دریافت پیش‌نمایش...';
                try {
                    const url=new URL(form.dataset.previewUrl,location.origin);url.searchParams.set('keep',keep.value);
                    const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});
                    const data=await response.json();
                    if(!response.ok)throw Error(Object.values(data.errors||{})[0]?.[0]||data.message||'پیش‌نمایش ناموفق بود.');
                    const table=document.createElement('div');table.className='kit-release-table';
                    for(const row of data.rows){const line=document.createElement('div');line.className='border-bottom py-1';
                        line.textContent=`${row.name}: نیاز در بسته ${row.per_bundle} · سهمیه آزاد ${row.remaining} · نگه‌داری ${row.keep} · برگشت ${row.release} · انبار عادی ${row.stock}`;
                        table.append(line);}
                    result.replaceChildren(table);
                    const total=document.createElement('strong');total.textContent=`جمع قطعات برگشتی: ${data.released_total}`;result.prepend(total);
                    token.value=data.token;button.disabled=data.released_total<=0;
                }catch(e){result.textContent=e.message;}
            });
            form.addEventListener('submit',e=>{if(button.disabled||!confirm('فقط ردیف‌های پیش‌نمایش‌شده آزاد شوند؟'))e.preventDefault();});
        })();
        </script>
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
            @if ($compositionNeedsStockRelease)
                <div class="alert alert-warning">برای تغییر نوع قطعات یا تعدادشان، ابتدا انتشار سایت را متوقف کنید. سهمیه قطعات باقی‌مانده حفظ و سهمیه قطعات حذف‌شده خودکار آزاد می‌شود؛ قطعات جدید باید سهمیه بگیرند.</div>
            @endif
            @if ($finishedStockExists && !$isAutomatic)
                <div class="alert alert-warning" role="alert">موجودی بسته آماده مانع ویرایش ترکیب نیست. با این حال، بسته‌های آماده موجود ممکن است از ترکیب قبلی ساخته شده باشند. ثبت دستور ساخت جدید، موجودی یا محتویات فیزیکی بسته‌های قبلی را تغییر نمی‌دهد. پیش از فروش یا دمونتاژ، محتوای بسته‌های موجود را با دستور جدید تطبیق دهید.</div>
            @endif
                <details class="bundle-card" data-bundle-panel="recipe" id="bundle-resume-recipe-{{ $product->id }}"><summary>قطعات بسته و تخفیف هر قطعه</summary>
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
                         data-confirm-conversion="{{ $product->is_bundle ? '0' : '1' }}"
                         data-has-finished-stock="{{ $finishedStockExists && !$isAutomatic ? '1' : '0' }}">
                        <label for="bundle-component-search-{{ $product->id }}" class="form-label fw-semibold">افزودن قطعه به بسته</label>
                        <div class="position-relative mb-3">
                            <input id="bundle-component-search-{{ $product->id }}" type="search" class="form-control" placeholder="جستجو با نام کالا، کد یا SKU (حداقل ۲ حرف)" autocomplete="off" aria-controls="bundle-component-results-{{ $product->id }}" aria-expanded="false">
                            <div class="d-flex gap-2 align-items-center my-2"><label class="small" for="bundle-stock-filter-{{ $product->id }}">فیلتر جستجو</label><select class="form-select form-select-sm" style="max-width:230px" id="bundle-stock-filter-{{ $product->id }}"><option value="all">همه تنوع‌های فعال (حتی صفر)</option><option value="available">فقط موجود</option></select></div>
                            <div id="bundle-component-results-{{ $product->id }}" class="list-group position-absolute w-100 shadow-sm overflow-auto" style="z-index: 25;max-height: 280px;display: none;" role="listbox" aria-label="نتایج جستجوی کالا"></div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
                            <strong>قطعات انتخاب‌شده</strong>
                            <span class="text-muted small" id="bundle-selected-summary-{{ $product->id }}"></span>
                        </div>
                        <div class="d-flex flex-wrap gap-2 align-items-end my-2">
                            <label class="small">جستجو در انتخاب‌شده‌ها<input id="bundle-edit-filter-{{ $product->id }}" class="form-control form-control-sm" type="search"></label>
                            <label class="small">تعداد گروهی<input id="bundle-edit-bulk-{{ $product->id }}" class="form-control form-control-sm" type="number" value="10" min="1" max="100000" style="max-width:110px"></label>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="bundle-edit-apply-{{ $product->id }}">اعمال به انتخاب‌ها</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="bundle-edit-undo-{{ $product->id }}" disabled>برگشت</button>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="bundle-edit-remove-{{ $product->id }}">حذف انتخاب‌ها</button>
                        </div>
                        <div id="bundle-edit-price-{{ $product->id }}" class="kit-preview small mb-2" aria-live="polite"></div>
                        <div id="bundle-selected-items-{{ $product->id }}" class="mb-3" aria-live="polite"></div>
                        <div class="d-flex justify-content-between align-items-center mb-2"><button type="button" class="btn btn-sm btn-outline-secondary" id="bundle-edit-prev-{{ $product->id }}">قبلی</button><span id="bundle-edit-page-{{ $product->id }}" class="small text-muted"></span><button type="button" class="btn btn-sm btn-outline-secondary" id="bundle-edit-next-{{ $product->id }}">بعدی</button></div>
                        <div id="bundle-empty-{{ $product->id }}" class="text-muted small border rounded p-3 mb-3">هنوز هیچ قطعه‌ای اضافه نشده است. از جستجوی بالا استفاده کنید.</div>
                    </div>
                    @if($errors->has('bundle_variant_id') || $errors->has('components') || $errors->has('components.*.variant_id') || $errors->has('components.*.quantity') || $errors->has('components.*.discount_value'))
                        <div class="alert alert-danger">{{ $errors->first('bundle_variant_id') ?: $errors->first('components') ?: $errors->first('components.*.variant_id') ?: $errors->first('components.*.quantity') ?: $errors->first('components.*.discount_value') }}</div>
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
                    const selected = new Map(), checked=new Set();
                    const filter=document.getElementById('bundle-edit-filter-{{ $product->id }}'),stockFilter=document.getElementById('bundle-stock-filter-{{ $product->id }}');
                    let page=0,beforeBulk=null;const pageSize=25;
                    const pricePreview=document.getElementById('bundle-edit-price-{{ $product->id }}'),savedPrice={{ (int) ($bundleVariant->sell_price ?? 0) }};
                    const initial = {{ \Illuminate\Support\Js::from($selectedComponents) }};
                    for (const item of initial) if (Number(item.id) > 0) selected.set(Number(item.id), {...item, quantity: Number(item.quantity) || 1});
                    const digits=s=>String(s??'').replace(/[۰-۹]/g,c=>String(c.charCodeAt(0)-1776)).replace(/[٠-٩]/g,c=>String(c.charCodeAt(0)-1632));
                    const bundleVariantId = Number(root.dataset.bundleVariant);
                    let delay;
                    let controller;
                    let revision = 0;
                    const info = (message) => { results.replaceChildren(); const el = document.createElement('div'); el.className = 'list-group-item text-muted small'; el.textContent = message; results.append(el); results.style.display = 'block'; search.setAttribute('aria-expanded', 'true'); };
                    const hide = () => { results.style.display = 'none'; search.setAttribute('aria-expanded', 'false'); };
                    const renderPrice=()=>{
                        let base=0,regular=0,valid=true;
                        for(const i of selected.values()){
                            const price=Number(i.sell_price||0),value=Number(i.discount_value||0),qty=Number(i.quantity||0),discount=i.discount_type==='fixed'?value:Math.round(price*value/100);
                            if(!Number.isSafeInteger(qty)||qty<1||qty>100000||!Number.isSafeInteger(value)||value<0||discount>=price||value>(i.discount_type==='fixed'?price-1:99))valid=false;
                            regular+=price*qty;base+=(price-discount)*qty;
                        }
                        const pieces=[...selected.values()].reduce((n,i)=>n+(Number(i.quantity)||0),0);
                        summary.textContent=`${selected.size} تنوع · ${pieces} قطعه در هر بسته`;
                        pricePreview.textContent=`قیمت ذخیره‌شده: ${savedPrice.toLocaleString('fa-IR')} ریال · قیمت عادی قطعات: ${regular.toLocaleString('fa-IR')} · پس از تخفیف قطعات: ${valid?base.toLocaleString('fa-IR'):'نامعتبر'} ریال · قیمت کل در کارت قیمت‌گذاری پس از ثبت دستور ساخت محاسبه و ذخیره می‌شود.`;
                    };
                    const render = () => {
                        selectedList.replaceChildren();
                        const all=[...selected.values()],pieces=all.reduce((n,item)=>n+(Number(item.quantity)||0),0);
                        const q=filter.value.trim().toLowerCase();
                        const filtered=all.map((item,index)=>({item,index})).filter(({item})=>[item.name,item.variant,item.code].join(' ').toLowerCase().includes(q));
                        const pages=Math.max(1,Math.ceil(filtered.length/pageSize));page=Math.min(page,pages-1);
                        document.getElementById('bundle-edit-page-{{ $product->id }}').textContent=`صفحه ${page+1} از ${pages} · ${filtered.length} مورد`;
                        document.getElementById('bundle-edit-prev-{{ $product->id }}').disabled=page===0;
                        document.getElementById('bundle-edit-next-{{ $product->id }}').disabled=page>=pages-1;
                        const shown=new Set(filtered.slice(page*pageSize,(page+1)*pageSize).map(x=>x.index));
                        all.forEach((item, index) => {
                            if(!shown.has(index)){
                                for(const [key,val] of Object.entries({variant_id:item.id,quantity:item.quantity,discount_type:item.discount_type||'percent',discount_value:item.discount_value??0})){
                                    const hidden=document.createElement('input');hidden.type='hidden';hidden.name=`components[${index}][${key}]`;hidden.value=val;selectedList.append(hidden);
                                }
                                return;
                            }
                            const row = document.createElement('div');
                            row.className = 'd-flex flex-wrap align-items-center gap-2 border rounded p-2 mb-2';
                            const title = document.createElement('div');
                            title.className = 'flex-grow-1';
                            const strong = document.createElement('strong');
                            strong.textContent = item.name;
                            const detail = document.createElement('div');
                            detail.className = 'small text-muted';
                            detail.textContent = `${item.variant || 'تنوع اصلی'}${item.code ? ' · ' + item.code : ''} · موجودی: ${item.stock ?? 'نامشخص'}`;
                            const check=document.createElement('input');check.type='checkbox';check.checked=checked.has(Number(item.id));check.setAttribute('aria-label',`انتخاب ${item.name}`);
                            check.addEventListener('change',()=>{if(check.checked)checked.add(Number(item.id));else checked.delete(Number(item.id));});
                            title.append(strong, detail);
                            const id = document.createElement('input');
                            id.type = 'hidden'; id.name = `components[${index}][variant_id]`; id.value = item.id;
                            const quantity = document.createElement('input');
                            quantity.type = 'text';quantity.inputMode='numeric';quantity.pattern='[0-9]+';quantity.required = true;
                            quantity.className = 'form-control'; quantity.style.maxWidth = '125px'; quantity.style.direction = 'ltr';
                            quantity.name = `components[${index}][quantity]`; quantity.value = item.quantity;
                            quantity.setAttribute('aria-label', `تعداد ${item.name} در هر بسته`);
                            quantity.addEventListener('input', () => {const normalized=digits(quantity.value);if(/^[0-9]*$/.test(normalized))quantity.value=normalized;item.quantity=quantity.value;renderPrice();});
                            const discountWrap=document.createElement('div');discountWrap.className='d-flex flex-wrap gap-1 align-items-center';
                            const discountLabel=document.createElement('label');discountLabel.className='small text-muted';discountLabel.textContent='تخفیف هر عدد';
                            const discountType=document.createElement('select');discountType.className='form-select form-select-sm';discountType.style.width='94px';discountType.name=`components[${index}][discount_type]`;
                            for(const [value,label] of [['percent','درصد ٪'],['fixed','ریال']]){const option=document.createElement('option');option.value=value;option.textContent=label;discountType.append(option);}
                            discountType.value=item.discount_type||'percent';
                            const discountValue=document.createElement('input');discountValue.type='text';discountValue.dataset.rial='';discountWrap.dataset.rialDiscount='';discountValue.min='0';discountValue.step='1';discountValue.required=true;discountValue.className='form-control form-control-sm';discountValue.style.width='105px';discountValue.dir='ltr';
                            discountValue.value=item.discount_value??0;discountValue.name=`components[${index}][discount_value]`;
                            discountValue.setAttribute('aria-label',`تخفیف هر عدد ${item.name}`);
                            const limit=()=>{discountValue.max=discountType.value==='percent'?'99':String(Math.max(0,Number(item.sell_price||0)-1));};
                            discountType.addEventListener('change',()=>{item.discount_type=discountType.value;item.discount_value=0;discountValue.value='0';limit();renderPrice();});
                            discountValue.addEventListener('input',()=>{item.discount_value=RialInput.raw(discountValue.value);renderPrice();});
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
                            row.append(check, title, id, less, quantity, more, discountWrap, remove);
                            selectedList.append(row);
                        });
                        empty.hidden = selected.size > 0;
                        summary.textContent = `${selected.size} تنوع · ${pieces} قطعه در هر بسته`;
                        renderPrice();
                    };
                    const add = item => {
                        if (selected.has(Number(item.id))) { hide(); search.value = ''; search.focus(); return; }
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
                            url.searchParams.set('exclude_variant_id', String(bundleVariantId));url.searchParams.set('stock_filter',stockFilter.value);url.searchParams.set('complete_products','1');
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
                                const groupAdd=document.createElement('button');groupAdd.type='button';groupAdd.className='btn btn-sm btn-outline-secondary my-1';groupAdd.textContent='افزودن همه تنوع‌های این محصول';groupAdd.addEventListener('click',()=>{for(const item of group.variants){if(!selected.has(Number(item.id)))selected.set(Number(item.id),{...item,quantity:1,discount_type:'percent',discount_value:0});}render();hide();});groupEl.append(heading,groupAdd);
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
                    filter.addEventListener('input',()=>{page=0;render();});
                    document.getElementById('bundle-edit-prev-{{ $product->id }}').addEventListener('click',()=>{page--;render();});
                    document.getElementById('bundle-edit-next-{{ $product->id }}').addEventListener('click',()=>{page++;render();});
                    document.getElementById('bundle-edit-apply-{{ $product->id }}').addEventListener('click',()=>{
                        const n=Number(document.getElementById('bundle-edit-bulk-{{ $product->id }}').value);
                        if(!Number.isSafeInteger(n)||n<1||n>100000){alert('تعداد گروهی نامعتبر است.');return;}
                        beforeBulk=new Map([...selected.values()].map(i=>[Number(i.id),i.quantity]));
                        for(const id of checked)if(selected.has(id))selected.get(id).quantity=n;
                        document.getElementById('bundle-edit-undo-{{ $product->id }}').disabled=false;render();
                    });
                    document.getElementById('bundle-edit-undo-{{ $product->id }}').addEventListener('click',()=>{
                        if(beforeBulk){for(const [id,qty] of beforeBulk)if(selected.has(id))selected.get(id).quantity=qty;beforeBulk=null;render();}
                        document.getElementById('bundle-edit-undo-{{ $product->id }}').disabled=true;
                    });
                    document.getElementById('bundle-edit-remove-{{ $product->id }}').addEventListener('click',()=>{for(const id of checked)selected.delete(id);checked.clear();render();});
                    stockFilter.addEventListener('change',()=>{if(search.value.trim().length>=2)fetchResults(search.value.trim());});
                    search.addEventListener('input', () => { clearTimeout(delay); const term = search.value.trim(); if (term.length < 2) { revision++; if (controller) controller.abort(); hide(); return; } delay = setTimeout(() => fetchResults(term), 250); });
                    search.addEventListener('keydown', event => { if (event.key === 'Escape') hide(); });
                    document.addEventListener('click', event => { if (!root.contains(event.target)) hide(); });
                    form.addEventListener('submit', event => {
                        if (selected.size === 0) { event.preventDefault(); window.alert('ابتدا حداقل یک قطعه انتخاب کنید.'); search.focus(); return; }
                        if (root.dataset.confirmConversion === '1' && !window.confirm('این کالای فعلی به بسته فیزیکی تبدیل می‌شود. ادامه می‌دهید؟')) { event.preventDefault(); return; }
                        const initialRecipe = new Map(initial.map(item => [Number(item.id), Number(item.quantity)]));
                        if([...selected.values()].some(i=>!Number.isSafeInteger(Number(i.quantity))||Number(i.quantity)<1||Number(i.quantity)>100000)){event.preventDefault();alert('تعداد هر قطعه باید بین ۱ تا ۱۰۰۰۰۰ باشد.');return;}
                        const compositionChanged = selected.size !== initialRecipe.size ||
                            [...selected.values()].some(item => Number(item.quantity) !== initialRecipe.get(Number(item.id)));
                        if (root.dataset.hasFinishedStock === '1' && compositionChanged &&
                            !window.confirm('این بسته موجودی آماده دارد. ترکیب جدید ذخیره می‌شود اما موجودی فعلی و محتویات واقعی بسته‌های آماده تغییر نمی‌کند. مسئولیت تطبیق بسته‌های موجود با ترکیب جدید پیش از فروش یا دمونتاژ با شماست. ادامه می‌دهید؟')) {
                            event.preventDefault();
                        }
                    });
                    render();
                })();
                </script>
                </details>
            @if ($components->isNotEmpty())
                <details class="bundle-card" data-bundle-panel="recipe"><summary>ترکیب ذخیره‌شده ({{ $components->count() }} تنوع)</summary>
                <ul class="kit-recipe-history">
                    @foreach ($components as $component)
                        <li>{{ $component->product?->name }} — {{ $component->variant_name }} (#{{ $component->id }}) × {{ $component->pivot->quantity }}
                            <small class="text-muted">| موجودی مرکزی: {{ number_format(max(0, (int) ($componentStock[$component->id] ?? 0))) }} | حداکثر بسته از این قطعه: {{ number_format(intdiv(max(0, (int) ($componentStock[$component->id] ?? 0)), max(1, (int) $component->pivot->quantity))) }}</small>
                        </li>
                    @endforeach
                </ul></details>
                @if(!$isAutomatic)
                <div class="row g-3 mt-3" data-bundle-panel="stock" id="bundle-resume-stock-{{ $product->id }}">
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
                    <div data-bundle-panel="stock" class="alert alert-info mt-3">این بسته خودکار است؛ دکمه مونتاژ ندارد. در سفارش سایت، قطعات لازم باهم رزرو می‌شوند.
                    <strong> خرید اینترنتی فقط با فعال بودن سرویس رزرو اتمیک Inventory مجاز است.</strong></div>
                @endif
            @endif
            @if ($assemblyHistory->isNotEmpty())
                <h6 class="mt-4" data-bundle-panel="actions">آخرین عملیات</h6>
                <ul class="small" data-bundle-panel="actions">
                    @foreach ($assemblyHistory as $assembly)
                        <li>{{ $assembly->created_at }} — {{ $assembly->operation }}: {{ $assembly->quantity }}</li>
                    @endforeach
                </ul>
            @endif
        @endif
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const root = document.querySelector('section[aria-label="Bundle configuration"]');
        if (!root) return;
        const tabs = [...root.querySelectorAll('[data-bundle-tab]')];
        if (!tabs.length) return;
        const panels = [...root.querySelectorAll('[data-bundle-panel]')];
        const basic = document.getElementById('bundleBaseDetails');
        const groups = new Set(panels.map(node => node.dataset.bundlePanel));
        if (basic) groups.add('basic');
        const errors = {{ \Illuminate\Support\Js::from($errors->keys()) }};
        const reasonGroup = {{ \Illuminate\Support\Js::from(old('bundle_shared_quantity') !== null ? 'stock' : 'actions') }};
        const enabledGroup = {{ \Illuminate\Support\Js::from(old('reason') !== null ? 'actions' : 'site') }};
        const groupFor = (name) => {
            if (/^(components|recipe|component_)/.test(name)) return 'recipe';
            if (/^(bundle_discount|sell_price|discount_)/.test(name)) return 'price';
            if (/^(bundle_shared_quantity|bundle_quantity_mode|allocations|expected_allocations)/.test(name)) return 'stock';
            if (/^(bundle_site_auto_publish|site_)/.test(name)) return 'site';
            if (name === 'reason') return reasonGroup;
            if (name === 'enabled') return enabledGroup;
            if (/^(keep|preview_token|restore|bundle_stock_mode)/.test(name)) return 'actions';
            return 'basic';
        };
        let active = '';
        function show(group, scroll = false) {
            if (!groups.has(group)) return;
            active = group;
            root.classList.add('bundle-tabs-ready');
            for (const panel of panels) {
                const visible = panel.dataset.bundlePanel === group;
                panel.classList.toggle('is-active', visible);
                if (visible && panel.tagName === 'DETAILS') panel.open = true;
            }
            for (const tab of tabs) {
                const selected = tab.dataset.bundleTab === group;
                tab.classList.toggle('is-active', selected);
                tab.setAttribute('aria-selected', String(selected));
                tab.tabIndex = selected ? 0 : -1;
            }
            if (basic) basic.open = group === 'basic';
            if (scroll) {
                const target = group === 'basic' ? basic : panels.find(el => el.dataset.bundlePanel === group);
                target?.scrollIntoView({behavior:'smooth',block:'start'});
            }
        }
        for (const tab of tabs) {
            if (!groups.has(tab.dataset.bundleTab)) {tab.disabled = true; tab.hidden = true; continue;}
            const panel = panels.find(node => node.dataset.bundlePanel === tab.dataset.bundleTab);
            if (panel?.id) tab.setAttribute('aria-controls', panel.id);
            tab.addEventListener('click', () => show(tab.dataset.bundleTab, true));
            tab.addEventListener('keydown', event => {
                if (!['ArrowRight','ArrowLeft','Home','End'].includes(event.key)) return;
                const enabled = tabs.filter(item => !item.disabled);
                let index = enabled.indexOf(tab);
                if (event.key === 'Home') index = 0;
                else if (event.key === 'End') index = enabled.length - 1;
                else index = (index + (event.key === 'ArrowLeft' ? 1 : -1) + enabled.length) % enabled.length;
                event.preventDefault(); enabled[index]?.focus(); enabled[index]?.click();
            });
        }
        const uniqueErrors = [...new Set(errors.map(groupFor).filter(group => groups.has(group)))];
        for (const group of uniqueErrors) {
            const tab = tabs.find(item => item.dataset.bundleTab === group);
            tab?.classList.add('has-error');
            tab?.setAttribute('aria-label', `${tab.textContent.trim()} - دارای خطا`);
        }
        const initial = uniqueErrors[0] || (groups.has('price') ? 'price' : groups.has('stock') ? 'stock' : groups.has('recipe') ? 'recipe' : 'actions');
        show(initial);
        root.querySelectorAll('[data-bundle-resume-target]').forEach(link => {
            link.addEventListener('click', event => {
                const target = document.getElementById(link.dataset.bundleResumeTarget);
                if (!target) return;
                const panel = target.closest('[data-bundle-panel]') || target;
                const group = panel.dataset.bundlePanel || (target === basic ? 'basic' : null);
                if (group && groups.has(group)) {event.preventDefault(); show(group, true);}
            });
        });
        document.querySelectorAll('[data-bundle-error-field]').forEach(button => button.addEventListener('click', () => {
            const field = button.dataset.bundleErrorField;
            const group = groupFor(field);
            show(groups.has(group) ? group : initial, true);
            const target = group === 'basic' ? document.getElementById('productEditForm') : panels.find(el => el.dataset.bundlePanel === group);
            const focus = [...(target?.querySelectorAll('[name]') || [])].find(input => input.name === field) ||
                (group === 'price' ? target?.querySelector('[data-discount-display]') : null) ||
                (group === 'recipe' ? target?.querySelector('input[type=search]') : null) ||
                target?.querySelector('input:not([type=hidden]),select,textarea');
            if (focus) requestAnimationFrame(() => focus.focus({preventScroll:true}));
        }));
    });
    </script>
    </div>
</section>
