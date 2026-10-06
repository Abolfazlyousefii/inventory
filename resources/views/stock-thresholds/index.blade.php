@extends('layouts.app')
@section('title', 'مدیریت آستانه موجودی')

@section('content')
@php
    $outCount = $all->where('available', 0)->count();
    $lowCount = $all->filter(fn ($row) => (int) $row['available'] > 0 && (int) $row['available'] < (int) $row['minimum'])->count();
    $edgeCount = $all->filter(fn ($row) => (int) $row['available'] > 0 && (int) $row['available'] === (int) $row['minimum'])->count();
@endphp
<div class="threshold-page" dir="rtl" data-stock-threshold-editor data-search-url="{{ route('stock-thresholds.search') }}">
    <header class="threshold-hero">
        <div class="threshold-hero__copy">
            <div class="threshold-eyebrow"><span class="threshold-eyebrow__dot"></span> انبارداری / برنامه‌ریزی تأمین</div>
            <h1>مدیریت آستانه موجودی</h1>
            <p>موجودی آزاد انبار مرکزی را پایش کنید، اقلام نیازمند تأمین را ببینید و حداقل موجودی هر دسته، کالا یا تنوع را تعیین کنید.</p>
        </div>
        <a class="threshold-button threshold-button--primary threshold-hero__action" href="#threshold-settings">
            <span aria-hidden="true">＋</span> تعریف آستانه جدید
        </a>
    </header>

    @if(session('success'))
        <div class="threshold-flash threshold-flash--success" role="status">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="threshold-flash threshold-flash--error" role="alert">
            <strong>ذخیره انجام نشد.</strong> {{ $errors->first() }}
        </div>
    @endif

    <div class="threshold-metrics" aria-label="خلاصه وضعیت موجودی">
        <article class="threshold-metric threshold-metric--danger">
            <span class="threshold-metric__icon" aria-hidden="true">!</span>
            <span class="threshold-metric__label">هشدارهای ناموجود</span>
            <strong>{{ number_format($outCount) }}</strong>
            <small>موجودی آزاد صفر</small>
        </article>
        <article class="threshold-metric threshold-metric--warning">
            <span class="threshold-metric__icon" aria-hidden="true">↓</span>
            <span class="threshold-metric__label">کمتر از آستانه</span>
            <strong>{{ number_format($lowCount) }}</strong>
            <small>موجودی مثبت با کسری</small>
        </article>
        <article class="threshold-metric threshold-metric--edge">
            <span class="threshold-metric__icon" aria-hidden="true">=</span>
            <span class="threshold-metric__label">برابر آستانه</span>
            <strong>{{ number_format($edgeCount) }}</strong>
            <small>در مرز حداقل موجودی</small>
        </article>
        <article class="threshold-metric threshold-metric--neutral">
            <span class="threshold-metric__icon" aria-hidden="true">#</span>
            <span class="threshold-metric__label">قوانین ثبت‌شده</span>
            <strong>{{ number_format($rules->count()) }}</strong>
            <small>تنظیم‌های دسته، کالا و تنوع</small>
        </article>
    </div>

    <section class="threshold-card" aria-labelledby="threshold-supply-title">
        <div class="threshold-section-head">
            <div>
                <div class="threshold-section-head__eyebrow">فهرست عملیاتی</div>
                <h2 id="threshold-supply-title">لیست تأمین کالا</h2>
                <p>هشدارها براساس موجودی آزاد انبار مرکزی محاسبه می‌شوند؛ کالاهای غیرفعال و فروش‌بسته نیز نمایش داده می‌شوند.</p>
            </div>
            <span class="threshold-count">{{ number_format($all->count()) }} هشدار</span>
        </div>

        @if($rules->isEmpty())
            <div class="threshold-empty">
                <div class="threshold-empty__illustration" aria-hidden="true">◎</div>
                <h3>اولین آستانه موجودی را تعریف کنید</h3>
                <p>هنوز هیچ قانونی ثبت نشده است. برای شروع یک دسته، کالا یا تنوع انتخاب کنید؛ پس از ذخیره، اقلامی که به حداقل موجودی رسیده‌اند اینجا نمایش داده می‌شوند.</p>
                <a href="#threshold-settings" class="threshold-button threshold-button--primary">ثبت اولین آستانه <span aria-hidden="true">←</span></a>
            </div>
        @else
            <form class="threshold-filters" method="GET" action="{{ route('stock-thresholds.index') }}">
                <label class="threshold-field threshold-field--search" for="threshold-filter-query">
                    <span>جست‌وجوی کالا</span>
                    <input id="threshold-filter-query" type="search" name="q" value="{{ $q }}" placeholder="نام، تنوع یا کد کالا">
                </label>
                <label class="threshold-field" for="threshold-filter-category">
                    <span>دسته‌بندی</span>
                    <select id="threshold-filter-category" name="category_id">
                        <option value="">همه دسته‌ها</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="threshold-field" for="threshold-filter-status">
                    <span>وضعیت موجودی</span>
                    <select id="threshold-filter-status" name="status">
                        <option value="all" @selected($status === 'all')>همه هشدارها</option>
                        <option value="out" @selected($status === 'out')>ناموجود</option>
                        <option value="low" @selected($status === 'low')>کمتر از آستانه</option>
                        <option value="edge" @selected($status === 'edge')>برابر آستانه</option>
                    </select>
                </label>
                <button class="threshold-button threshold-button--primary" type="submit">اعمال فیلتر</button>
                <a class="threshold-button threshold-button--ghost" href="{{ route('stock-thresholds.index') }}">پاک کردن</a>
            </form>

            <div class="threshold-table-scroll" role="region" aria-label="جدول اقلام نیازمند تأمین" tabindex="0">
                <table class="threshold-table">
                    <thead>
                        <tr>
                            <th scope="col">کالا / تنوع</th>
                            <th scope="col">دسته</th>
                            <th scope="col">موجودی آزاد</th>
                            <th scope="col">حداقل موجودی</th>
                            <th scope="col">کسری</th>
                            <th scope="col">وضعیت</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($alerts as $item)
                            <tr>
                                <td>
                                    <strong class="threshold-product-name">{{ $item['name'] }}</strong>
                                    <span class="threshold-product-meta">{{ $item['variant_name'] ?: 'مجموع کالا' }} @if($item['code']) <span class="threshold-product-code" dir="ltr">{{ $item['code'] }}</span> @endif</span>
                                    <div class="threshold-product-flags">
                                        @if($item['variant_id'] !== null && $item['variant_active'] === false)
                                            <span class="threshold-tag threshold-tag--muted">تنوع غیرفعال</span>
                                        @endif
                                        @if(!$item['product_sale_enabled'] || ($item['variant_id'] !== null && $item['variant_sales_enabled'] === false))
                                            <span class="threshold-tag threshold-tag--muted">فروش بسته</span>
                                        @endif
                                    </div>
                                </td>
                                <td>{{ $item['category'] ?: 'بدون دسته' }}</td>
                                <td><strong class="threshold-quantity @if((int) $item['available'] === 0) threshold-quantity--zero @endif">{{ number_format($item['available']) }}</strong></td>
                                <td>{{ number_format($item['minimum']) }}</td>
                                <td><strong class="threshold-shortfall">{{ number_format($item['shortfall']) }}</strong></td>
                                <td>
                                    @if((int) $item['available'] === 0)
                                        <span class="threshold-tag threshold-tag--danger"><span class="threshold-tag__dot"></span> ناموجود</span>
                                    @elseif((int) $item['available'] < (int) $item['minimum'])
                                        <span class="threshold-tag threshold-tag--warning"><span class="threshold-tag__dot"></span> نیازمند تأمین</span>
                                    @else
                                        <span class="threshold-tag threshold-tag--edge"><span class="threshold-tag__dot"></span> مرز آستانه</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="threshold-table-empty" colspan="6">برای فیلتر انتخاب‌شده هشداری یافت نشد. <a href="{{ route('stock-thresholds.index') }}">مشاهده تمام هشدارها</a></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="threshold-pagination">{{ $alerts->links() }}</div>
        @endif
    </section>

    <section class="threshold-card threshold-card--settings" id="threshold-settings" aria-labelledby="threshold-settings-title">
        <div class="threshold-section-head">
            <div>
                <div class="threshold-section-head__eyebrow">تنظیمات تأمین</div>
                <h2 id="threshold-settings-title">تعریف و مدیریت آستانه‌ها</h2>
                <p>تنظیم دسته به زیردسته‌ها نیز منتقل می‌شود. قانون اختصاصی تنوع، سپس کالا و بعد نزدیک‌ترین دسته در اولویت است.</p>
            </div>
            <span class="threshold-count threshold-count--neutral">{{ number_format($rules->count()) }} قانون</span>
        </div>

        <div class="threshold-editor-card">
            <div class="threshold-editor-card__header">
                <div>
                    <h3 id="threshold-editor-title">تنظیم آستانه جدید</h3>
                    <p>سه مرحله ساده: انتخاب محدوده، انتخاب کالا یا دسته، تعیین حداقل موجودی.</p>
                </div>
                <div class="threshold-stepper" aria-hidden="true"><span>۱ محدوده</span><span>۲ انتخاب</span><span>۳ مقدار</span></div>
            </div>

            <form id="threshold-rule-form" class="threshold-rule-form" method="POST" action="{{ route('stock-thresholds.store') }}">
                @csrf
                <div class="threshold-form-grid">
                    <label class="threshold-field" for="threshold-type">
                        <span><b class="threshold-field-number">۱</b> محدوده آستانه</span>
                        <select name="target_type" id="threshold-type" required>
                            <option value="category">دسته / زیردسته</option>
                            <option value="product">کالای مشخص</option>
                            <option value="variant">تنوع مشخص کالا</option>
                        </select>
                        <small>آستانه را برای کدام سطح تعریف می‌کنید؟</small>
                    </label>

                    <label class="threshold-field threshold-search-field" for="threshold-search" id="threshold-search-field" hidden>
                        <span>جست‌وجوی کالا یا تنوع</span>
                        <input type="search" id="threshold-search" autocomplete="off" placeholder="حداقل دو حرف یا کد کالا" aria-describedby="threshold-search-status">
                        <small id="threshold-search-status" role="status" aria-live="polite"></small>
                    </label>

                    <label class="threshold-field" for="threshold-target">
                        <span><b class="threshold-field-number">۲</b> انتخاب محدوده</span>
                        <select name="target_id" id="threshold-target" required>
                            <option value="">یک دسته انتخاب کنید</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}{{ $category->parent_id ? ' (زیردسته)' : '' }}</option>
                            @endforeach
                        </select>
                        <small>دسته، کالا یا تنوع موردنظر را تعیین کنید.</small>
                    </label>

                    <label class="threshold-field" for="threshold-measure">
                        <span>مبنای محاسبه</span>
                        <select name="measure" id="threshold-measure">
                            <option value="product">مجموع هر کالا</option>
                            <option value="variant">هر تنوع به‌صورت جداگانه</option>
                        </select>
                        <small>برای هر تنوع، آستانه مستقل محاسبه می‌شود.</small>
                    </label>

                    <label class="threshold-field" for="threshold-minimum">
                        <span><b class="threshold-field-number">۳</b> حداقل موجودی</span>
                        <input name="minimum" id="threshold-minimum" type="number" inputmode="numeric" min="0" max="100000000" required placeholder="مثلاً ۲۰">
                        <small>مقدار صفر فقط هنگام ناموجودی هشدار می‌دهد.</small>
                    </label>
                </div>
                <div class="threshold-form-footer">
                    <p id="threshold-edit-message" role="status" aria-live="polite">هشدار در موجودی برابر یا کمتر از این مقدار فعال می‌شود. ثبت قانون موجود، مقدار قبلی را به‌روزرسانی می‌کند.</p>
                    <div class="threshold-form-actions">
                        <button type="button" class="threshold-button threshold-button--ghost" id="threshold-cancel-edit" hidden>انصراف از ویرایش</button>
                        <button type="submit" class="threshold-button threshold-button--primary" id="threshold-submit">ذخیره آستانه</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="threshold-rules">
            <div class="threshold-rules__head">
                <h3>آستانه‌های ثبت‌شده</h3>
                <p>برای تغییر مقدار یک قانون، گزینه ویرایش را انتخاب کنید.</p>
            </div>
            @if($rules->isEmpty())
                <div class="threshold-rules-empty">هنوز قانونی برای آستانه موجودی ثبت نشده است.</div>
            @else
                <div class="threshold-table-scroll" role="region" aria-label="جدول آستانه‌های ثبت‌شده" tabindex="0">
                    <table class="threshold-table threshold-table--rules">
                        <thead><tr><th scope="col">محدوده انتخاب‌شده</th><th scope="col">مبنای محاسبه</th><th scope="col">حداقل موجودی</th><th scope="col">عملیات</th></tr></thead>
                        <tbody>
                            @foreach($rules as $rule)
                                @php
                                    $ruleName = match ($rule->target_type) {
                                        'category' => $categories->firstWhere('id', $rule->target_id)?->name ?? 'دسته حذف‌شده',
                                        'product' => $products[$rule->target_id] ?? 'کالای حذف‌شده',
                                        default => trim(($variants->get($rule->target_id)?->product?->name ?? 'کالای حذف‌شده').' / '.($variants->get($rule->target_id)?->variant_name ?: $variants->get($rule->target_id)?->variety_name ?: 'تنوع حذف‌شده')),
                                    };
                                @endphp
                                <tr>
                                    <td><strong class="threshold-rule-name">{{ $ruleName }}</strong><span class="threshold-rule-kind">{{ ['category' => 'دسته‌بندی', 'product' => 'کالا', 'variant' => 'تنوع'][$rule->target_type] ?? $rule->target_type }}</span></td>
                                    <td>{{ $rule->measure === 'variant' ? 'هر تنوع' : 'مجموع کالا' }}</td>
                                    <td><strong>{{ number_format($rule->minimum) }}</strong></td>
                                    <td>
                                        <div class="threshold-rule-actions">
                                            <button type="button" class="threshold-button threshold-button--edit" data-threshold-edit data-type="{{ $rule->target_type }}" data-target-id="{{ $rule->target_id }}" data-rule-name="{{ $ruleName }}" data-measure="{{ $rule->measure }}" data-minimum="{{ $rule->minimum }}" aria-label="ویرایش آستانه {{ $ruleName }}">ویرایش</button>
                                            <form method="POST" action="{{ route('stock-thresholds.destroy', $rule) }}" onsubmit="return confirm('این تنظیم آستانه حذف شود؟')">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="threshold-button threshold-button--remove" aria-label="حذف آستانه {{ $ruleName }}">حذف</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    <p class="threshold-disclaimer"><span aria-hidden="true">ⓘ</span> این صفحه صرفاً برای پایش و برنامه‌ریزی تأمین است؛ تعریف یا ویرایش آستانه هیچ تغییری در موجودی انبار، رزروها یا اسناد فروش ایجاد نمی‌کند.</p>
</div>
@endsection

@push('styles')
<link rel="stylesheet" href="{{ asset('css/stock-thresholds.css') }}">
@endpush
@push('scripts')
<script src="{{ asset('js/stock-threshold-editor.js') }}" defer></script>
@endpush
