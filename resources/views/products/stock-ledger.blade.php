@extends('layouts.app')
@section('title', 'گردش موجودی '.$product->name)
@push('styles')<link rel="stylesheet" href="{{ asset('css/product-stock-ledger.css') }}?v=20261018-calm">@endpush
@section('content')
@php
$fa=fn($value)=>strtr((string)$value,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
$n=fn($value)=>$fa(number_format((int)$value));
$reasons=['sales_return_correction'=>'اصلاح برگشت از فروش','purchase_return'=>'برگشت از خرید','purchase_return_restore'=>'بازگشت پس از اصلاح خرید','purchase'=>'خرید کالا','sale'=>'فروش کالا','return'=>'مرجوعی','sales_return'=>'برگشت از فروش','transfer'=>'انتقال بین انبارها','adjustment'=>'اصلاح موجودی','purchase_item_added'=>'افزودن به خرید','purchase_item_quantity_changed'=>'اصلاح تعداد خرید','purchase_item_removed'=>'حذف از خرید','invoice_adjustment_return'=>'دریافت پس از اصلاح فاکتور','invoice_cancel_return'=>'دریافت پس از لغو فاکتور','stocktake'=>'انبارگردانی','stock_count'=>'انبارگردانی','reservation'=>'رزرو کالا','reserve'=>'رزرو کالا','reservation_release'=>'آزادسازی رزرو'];
$statuses=['pending'=>'در انتظار دریافت','received'=>'دریافت‌شده','discrepancy'=>'دارای مغایرت','cancelled'=>'لغوشده','draft'=>'پیش‌نویس','applied'=>'سند اعمال‌شده','pending_warehouse'=>'در انتظار انبار'];
$evidence=['recorded'=>'گردش ثبت‌شده','reference'=>'سند مرتبط','incomplete'=>'سابقهٔ محدود'];
@endphp
<div class="psl">
    <header class="psl-heading">
        <div><a class="psl-breadcrumb" href="{{ route('products.index') }}">کالاها</a><span class="psl-breadcrumb-separator"> / </span><span class="psl-eyebrow">گردش موجودی</span><h1>{{ $product->name }}</h1><p>{{ $selectedVariant?->variant_name ?: 'همهٔ مدل‌ها و رنگ‌های این کالا' }}</p></div>
        <a class="psl-back" href="{{ route('products.index') }}">بازگشت به کالاها <span aria-hidden="true">←</span></a>
    </header>
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    <div class="psl-summary">
        <div class="psl-stat is-stock"><span class="psl-stat-icon" aria-hidden="true">↙</span><div><span>موجودی آزاد مرکزی</span><strong>{{ $summary->current===null?'—':$n($summary->current) }} <small>عدد</small></strong></div></div>
        <div class="psl-stat is-reserved"><span class="psl-stat-icon" aria-hidden="true">◷</span><div><span>رزرو ثبت‌شده</span><strong>{{ $n($summary->reserved) }} <small>عدد</small></strong></div></div>
        <div class="psl-stat is-sales"><span class="psl-stat-icon" aria-hidden="true">↗</span><div><span>فروش / تخصیص معتبر</span><strong>{{ $n($summary->sold) }} <small>عدد</small></strong></div></div>
    </div>
    <div class="psl-context"><span class="psl-check {{ $summary->projection_matches?'is-matched':'is-review' }}" role="status"><i aria-hidden="true"></i>{{ $summary->projection_matches?'نمایشگر با انبار مرکزی مطابق است':'تطبیق نمایشگر و انبار نیازمند بررسی است' }}</span>
        <details class="psl-guide"><summary>راهنمای خواندن گردش</summary><div><p>تطبیق بالا فقط عدد فعلیِ نمایشگر و انبار را مقایسه می‌کند؛ تأیید سلامت تمام سوابق نیست.</p><p>سند توضیحی، فروش و رزرو را با گردش موجودی جمع نکنید. حذف کالا از فاکتور، پیش از دریافت انبار به معنی برگشت موجودی نیست.</p>@if($summary->incomplete_records)<p>{{ $n($summary->incomplete_records) }} سابقه با اطلاعات تاریخی محدود وجود دارد؛ این وضعیت به‌تنهایی نشانهٔ باگ یا کسری نیست.</p>@endif<p>قبل و بعد فقط با وجود مقادیر مطابق نمایش داده می‌شود؛ مقدارهای ثبت‌نشده حدس زده نمی‌شوند.</p></div></details>
    </div>
    <form class="psl-filter" method="get" action="{{ route('products.stock-ledger',$product) }}">
        @if($selectedVariant)<input type="hidden" name="variant_id" value="{{ $selectedVariant->id }}">@endif
        <label class="psl-search">جست‌وجو<input class="form-control" name="q" value="{{ $filters['q'] }}" maxlength="150" placeholder="مدل، رنگ، کد کالا یا شماره سند…"></label>
        <label>نوع سابقه<select class="form-select" name="effect"><option value="">همهٔ سوابق</option><option value="recorded" @selected($filters['effect']==='recorded')>گردش ثبت‌شده</option><option value="reference" @selected($filters['effect']==='reference')>سند مرتبط</option><option value="incomplete" @selected($filters['effect']==='incomplete')>سابقهٔ محدود</option></select></label>
        <label>انبار<select class="form-select" name="warehouse_id"><option value="">همهٔ انبارها</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((string)$filters['warehouse_id']===(string)$warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></label>
        <div class="psl-filter-actions"><button class="btn btn-primary" type="submit">جست‌وجو</button><a class="psl-reset" href="{{ route('products.stock-ledger',array_filter(['product'=>$product,'variant_id'=>$selectedVariant?->id])) }}">پاک‌کردن</a></div>
    </form>
    <section class="psl-book">
    <div class="psl-book-title"><div><strong>مسیر تغییرات کالا</strong><span>{{ $n($movements->total()) }} سابقه مطابق جست‌وجو</span></div><span>جدیدترین در بالا</span></div>
    <table><thead><tr><th>زمان</th><th>مدل / انبار</th><th>رویداد</th><th>تعداد / تغییر</th><th>سند مرتبط</th></tr></thead><tbody>
    @forelse($movements as $movement)
        @php
            $source=$sources[$movement->id]; $recorded=$movement->evidence_level==='recorded'; $delta=$movement->available_delta; $status=$movement->event_status ?? null;
            $event=$movement->transaction_type==='stocktake_adjustment'?'انبارگردانی':($reasons[$movement->reason] ?? $movement->reason ?: 'ثبت موجودی');
            $tone=$recorded && $delta>0?'in':($recorded && $delta<0?'out':'reference');
        @endphp
        <tr class="psl-row psl-row-{{ $tone }}">
            <td class="psl-date" data-label="زمان"><span>{{ $fa(\App\Support\JalaliDate::date($movement->created_at)) }}</span><small>{{ $fa($movement->created_at?->format('H:i') ?? '') }}</small></td>
            <td class="psl-item" data-label="مدل / انبار"><strong>{{ $movement->variant?->variant_name ?: ($movement->variant_snapshot ?? ($movement->product_variant_id ? 'تنوع حذف‌شده #'.$fa($movement->product_variant_id) : 'سابقه بدون تنوع مشخص')) }}</strong><small>{{ $movement->warehouse?->name ?: 'انبار نامشخص' }}<span class="psl-code"> · {{ $fa($movement->variant?->variant_code ?? $movement->variant_code_snapshot ?? '') }}</span></small></td>
            <td class="psl-event" data-label="رویداد"><strong><i class="psl-event-dot is-{{ $tone }}" aria-hidden="true"></i>{{ $event }}</strong><span class="psl-badge is-{{ $movement->evidence_level }}">{{ $evidence[$movement->evidence_level] }}</span>@if($status)<small>{{ $statuses[$status] ?? 'وضعیت ثبت‌شده: '.$status }}</small>@endif</td>
            <td class="psl-change" data-label="تعداد / تغییر">
                @if(isset($movement->expected_quantity))<strong>{{ $n($movement->accepted_quantity) }} <small>دریافت</small></strong><small>از {{ $n($movement->expected_quantity) }} عددِ مورد انتظار</small>
                @else<strong class="psl-amount is-{{ $tone }}">{{ $recorded?($delta>0?'+':($delta<0?'−':'')):'' }}{{ $n($recorded?abs($delta):$movement->quantity) }} <small>{{ $recorded?'عدد':'در سند' }}</small></strong>@endif
                @if($movement->snapshot_reliable)<div class="psl-snapshot"><span>قبل {{ $n($movement->stock_before) }}</span><span aria-hidden="true">←</span><span>بعد {{ $n($movement->stock_after) }}</span></div>
                @elseif(isset($movement->document_quantity_before))<small>تعداد سند: {{ $n($movement->document_quantity_before) }} ← {{ $n($movement->document_quantity_after) }}</small>@endif
            </td>
            <td class="psl-source" data-label="سند مرتبط">@if($source['url'])<a class="psl-source-link" href="{{ $source['url'] }}">{{ $fa($source['label']) }} <span aria-hidden="true">↗</span></a>@else<span class="psl-source-label">{{ $fa($source['label']) }}</span>@endif
                <details class="psl-row-details"><summary>جزئیات</summary><div><p class="psl-evidence-note">{{ $movement->evidence_note }}</p><p>{{ $movement->effect_label }}</p>@if($movement->related_url ?? null)<p><a href="{{ $movement->related_url }}">{{ $fa($movement->related_reference) }} ↗</a></p>@endif<p>ثبت‌کننده: {{ $movement->user?->name ?: 'نامشخص' }}</p><p>{{ $movement->note ?: 'توضیح بیشتری ثبت نشده است.' }}</p><small>شناسهٔ سابقه: {{ $fa($movement->id) }}</small></div></details>
            </td>
        </tr>
    @empty<tr><td colspan="5" class="psl-empty"><strong>سابقه‌ای پیدا نشد</strong><span>جست‌وجو یا فیلترها را تغییر دهید.</span></td></tr>@endforelse
    </tbody></table><footer><span>{{ $n($movements->firstItem() ?: 0) }} تا {{ $n($movements->lastItem() ?: 0) }} از {{ $n($movements->total()) }} سابقه</span>{{ $movements->links() }}</footer></section>
</div>
@endsection
