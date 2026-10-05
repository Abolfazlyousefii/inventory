@extends('layouts.app')
@section('title', 'آستانه موجودی')
@section('content')
<div class="threshold-page" dir="rtl" data-stock-threshold-editor data-search-url="{{ route('stock-thresholds.search') }}">
    <header class="threshold-head"><div><h1>آستانه موجودی</h1><p>موجودی آزاد انبار مرکزی؛ هشدار در موجودی برابر یا کمتر از آستانه</p></div><a href="#threshold-settings" class="threshold-button">تنظیم آستانه‌ها</a></header>
    @if(session('success'))<div class="threshold-success" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="threshold-error" role="alert">{{ $errors->first() }}</div>@endif
    <div class="threshold-metrics"><article><span>کالاهای نیازمند بررسی</span><strong>{{ number_format($all->pluck('product_id')->unique()->count()) }}</strong></article><article><span>هشدار کالا و تنوع</span><strong>{{ number_format($all->count()) }}</strong></article><article><span>آستانه‌های تنظیم‌شده</span><strong>{{ number_format($rules->count()) }}</strong></article></div>
    <section class="threshold-card"><h2>لیست تأمین</h2><form method="GET" class="threshold-filter"><input name="q" value="{{ $q }}" placeholder="نام کالا، تنوع یا کد"><select name="category_id"><option value="">همه دسته‌ها</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>@endforeach</select><button class="threshold-button">جست‌وجو</button><a href="{{ route('stock-thresholds.index') }}">پاک کردن</a></form>
        <div class="threshold-table"><table><thead><tr><th>کالا / تنوع</th><th>دسته</th><th>موجودی آزاد</th><th>آستانه</th><th>کسری تا آستانه</th></tr></thead><tbody>@forelse($alerts as $item)<tr><td><strong>{{ $item['name'] }}</strong><small>{{ $item['variant_name'] ?: 'مجموع کالا' }} · {{ $item['code'] }}</small></td><td>{{ $item['category'] }}</td><td><span class="threshold-badge">{{ number_format($item['available']) }}</span></td><td>{{ number_format($item['minimum']) }}</td><td>{{ number_format($item['shortfall']) }}</td></tr>@empty<tr><td colspan="5">هیچ کالا یا تنوعی با این فیلتر به آستانه نرسیده است. فقط موارد دارای تنظیم آستانه بررسی می‌شوند.</td></tr>@endforelse</tbody></table></div>
        {{ $alerts->links() }}
    </section>
    <section class="threshold-card" id="threshold-settings"><h2>تنظیم آستانه</h2><p>آستانهٔ دسته برای هر کالای آن و زیردسته‌هایش اعمال می‌شود؛ می‌توانید آن را روی هر تنوع هم اعمال کنید. اولویت با تنوع اختصاصی، کالا، زیردسته و سپس دسته است. کالاهای غیرفعال نیز بررسی می‌شوند.</p>
        <form method="POST" action="{{ route('stock-thresholds.store') }}" class="threshold-rule-form">@csrf
            <label>محدوده<select name="target_type" id="threshold-type"><option value="category">دسته / زیردسته</option><option value="product">کالا</option><option value="variant">تنوع</option></select></label>
            <label id="threshold-search-label" hidden>جست‌وجوی کالا یا تنوع<input id="threshold-search" autocomplete="off" placeholder="حداقل دو حرف یا کد"><small id="threshold-search-status" role="status"></small></label>
            <label>انتخاب<select name="target_id" id="threshold-target" required><option value="">انتخاب کنید</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}{{ $category->parent_id ? ' (زیردسته)' : '' }}</option>@endforeach</select></label>
            <label>مبنای آستانه<select name="measure" id="threshold-measure"><option value="product">مجموع هر کالا</option><option value="variant">هر تنوع به‌صورت جداگانه</option></select></label>
            <label>حداقل موجودی<input name="minimum" type="number" min="0" max="100000000" required placeholder="مثلاً ۲۰"></label>
            <button class="threshold-button">ذخیره آستانه</button>
        </form>
        <p class="threshold-note">برای تغییر مقدار، همان محدوده و مبنا را دوباره ذخیره کنید. مقدار صفر فقط هنگام ناموجود شدن هشدار می‌دهد. حذف تنظیم اختصاصی، تنظیم دستهٔ بالاتر را دوباره اعمال می‌کند.</p>
        <div class="threshold-table"><table><thead><tr><th>محدوده</th><th>مبنا</th><th>آستانه</th><th>عملیات</th></tr></thead><tbody>@forelse($rules as $rule)<tr><td>@if($rule->target_type === 'category')دسته: {{ $categories->firstWhere('id', $rule->target_id)?->name ?? 'دسته حذف‌شده' }}@elseif($rule->target_type === 'product')کالا: {{ $products[$rule->target_id] ?? 'کالا حذف‌شده' }}@elseتنوع: {{ $variants->get($rule->target_id)?->product?->name }} / {{ $variants->get($rule->target_id)?->variant_name ?? 'تنوع حذف‌شده' }}@endif</td><td>{{ $rule->measure === 'variant' ? 'هر تنوع' : 'مجموع هر کالا' }}</td><td>{{ number_format($rule->minimum) }}</td><td><form method="POST" action="{{ route('stock-thresholds.destroy', $rule) }}">@csrf @method('DELETE')<button class="threshold-remove" onclick="return confirm('این تنظیم آستانه حذف شود؟')">حذف</button></form></td></tr>@empty<tr><td colspan="4">هنوز آستانه‌ای تعیین نشده است.</td></tr>@endforelse</tbody></table></div>
    </section>
</div>
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('css/stock-thresholds.css') }}">@endpush
@push('scripts')<script src="{{ asset('js/stock-threshold-editor.js') }}" defer></script>@endpush
