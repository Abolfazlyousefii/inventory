@extends('layouts.app')
@section('title', 'گردش موجودی '.$product->name)
@push('styles')<link rel="stylesheet" href="{{ asset('css/product-stock-ledger.css') }}?v=20261018-explanations">@endpush
@section('content')
@php
$fa=fn($value)=>strtr((string)$value,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
$n=fn($value)=>$fa(number_format((int)$value));
$reasons=['sales_return_correction'=>'اختلاف اصلاح برگشت از فروش','purchase_return'=>'برگشت از خرید','purchase_return_restore'=>'بازگشت موجودی؛ اصلاح یا حذف برگشت از خرید','purchase'=>'خرید','sale'=>'فروش','return'=>'مرجوعی','sales_return'=>'برگشت از فروش','transfer'=>'انتقال انبار','adjustment'=>'اصلاح موجودی','purchase_item_added'=>'افزودن ردیف خرید','purchase_item_quantity_changed'=>'تغییر تعداد خرید','purchase_item_removed'=>'حذف ردیف خرید','invoice_adjustment_return'=>'دریافت پس از اصلاح فاکتور','invoice_cancel_return'=>'دریافت پس از لغو فاکتور','stocktake'=>'انبارگردانی','stock_count'=>'انبارگردانی','reservation'=>'رزرو','reserve'=>'رزرو','reservation_release'=>'آزادسازی رزرو'];
$statuses=['pending'=>'در انتظار دریافت','received'=>'دریافت‌شده','discrepancy'=>'دارای مغایرت','cancelled'=>'لغوشده','draft'=>'پیش‌نویس','applied'=>'سند اعمال‌شده','pending_warehouse'=>'در انتظار انبار'];
@endphp
<div class="psl">
    <header class="psl-heading"><div><span>پیگیری علت تغییر موجودی</span><h1>{{ $product->name }}</h1><p>{{ $selectedVariant?->variant_name ?: 'همهٔ تنوع‌ها' }}</p></div><a class="btn btn-outline-secondary" href="{{ route('products.index') }}">بازگشت به کالاها</a></header>
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    <div class="psl-summary"><span>موجودی آزاد فعلیِ مرکزی <strong>{{ $summary->current===null?'—':$n($summary->current) }}</strong></span><span>رزرو ثبت‌شده <strong>{{ $n($summary->reserved) }}</strong></span><span>فروش و تخصیص معتبر <strong>{{ $n($summary->sold) }}</strong></span></div>
    <div class="psl-check {{ $summary->projection_matches?'is-matched':'is-review' }}" role="status"><strong>{{ $summary->projection_matches?'عدد نمایشگر با موجودی مرکزی مطابق است':'تطبیق عدد نمایشگر و موجودی مرکزی نیازمند بررسی است' }}</strong><span>این کنترل، کامل‌بودن سوابق یا درستی تمام عملیات گذشته را تأیید نمی‌کند. ماندهٔ فعلی از جمع ردیف‌های این صفحه محاسبه نشده است.</span></div>
    <form class="psl-filter" method="get" action="{{ route('products.stock-ledger',$product) }}">
        @if($selectedVariant)<input type="hidden" name="variant_id" value="{{ $selectedVariant->id }}">@endif
        <label>جست‌وجوی مدل، کد، سند یا توضیح<input class="form-control" name="q" value="{{ $filters['q'] }}" maxlength="150" placeholder="مثلاً شماره فاکتور یا رسید"></label>
        <label>نوع سابقه<select class="form-select" name="effect"><option value="">همهٔ سوابق</option><option value="recorded" @selected($filters['effect']==='recorded')>گردش با قبل/بعد مطابق</option><option value="reference" @selected($filters['effect']==='reference')>اسناد توضیحی</option><option value="incomplete" @selected($filters['effect']==='incomplete')>سابقه ناقص / نیازمند بررسی</option></select></label>
        <label>انبار<select class="form-select" name="warehouse_id"><option value="">همهٔ انبارها</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((string)$filters['warehouse_id']===(string)$warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></label>
        <button class="btn btn-primary" type="submit">جست‌وجو</button><a class="btn btn-outline-secondary" href="{{ route('products.stock-ledger',array_filter(['product'=>$product,'variant_id'=>$selectedVariant?->id])) }}">پاک‌کردن</a>
    </form>
    <p class="psl-hint">سند فروش، رزرو و نتیجهٔ دریافت را با هم جمع نکنید. حذف کالا از فاکتور، تا زمانی که انبار آن را دریافت نکرده، به معنی برگشت موجودی نیست.</p>
    @if($summary->incomplete_records)<p class="psl-history-warning">{{ $n($summary->incomplete_records) }} سابقه با اثر تاریخیِ ناقص وجود دارد؛ این برچسب به‌تنهایی نشانهٔ باگ یا کسری کالا نیست.</p>@endif
    <div class="psl-book-title">{{ $n($movements->total()) }} سابقه مطابق جست‌وجو <span>جدیدترین رویداد در بالا · تاریخ شمسی</span></div>
    <section class="psl-book"><table><thead><tr><th>تاریخ / ثبت‌کننده</th><th>تنوع / انبار</th><th>رویداد / وضعیت</th><th>تعداد / اثر</th><th>موجودی قبل / بعد</th><th>سند / توضیح علت</th></tr></thead><tbody>
    @forelse($movements as $movement)
        @php $source=$sources[$movement->id]; $recorded=$movement->evidence_level==='recorded'; $delta=$movement->available_delta; $status=$movement->event_status ?? null; @endphp
        <tr class="psl-row {{ $recorded && $delta>0?'psl-row-in':($recorded && $delta<0?'psl-row-out':'psl-row-reference') }}">
            <td data-label="تاریخ / ثبت‌کننده" class="psl-date">{{ $fa(\App\Support\JalaliDate::date($movement->created_at)) }}<small>{{ $fa($movement->created_at?->format('H:i') ?? '') }}</small><small>{{ $movement->user?->name ?: 'ثبت‌کننده نامشخص' }}</small></td>
            <td data-label="تنوع / انبار">{{ $movement->variant?->variant_name ?: ($movement->variant_snapshot ?? ($movement->product_variant_id ? 'تنوع حذف‌شده #'.$fa($movement->product_variant_id) : 'سابقه بدون تنوع مشخص')) }}<small class="psl-code">{{ $fa($movement->variant?->variant_code ?? $movement->variant_code_snapshot ?? '') }}</small><small>{{ $movement->warehouse?->name ?: 'انبار نامشخص در سابقه' }}</small></td>
            <td data-label="رویداد / وضعیت">{{ $movement->transaction_type==='stocktake_adjustment' ? 'انبارگردانی' : ($reasons[$movement->reason] ?? $movement->reason ?: 'نامشخص') }}<small>{{ $movement->history_kind }}</small><span class="psl-badge is-{{ $movement->evidence_level }}">{{ $movement->evidence_label }}</span>@if($status)<small>{{ $statuses[$status] ?? 'وضعیت ثبت‌شده: '.$status }}</small>@endif</td>
            <td data-label="تعداد / اثر" class="psl-change {{ $recorded && $delta>0?'psl-in':($recorded && $delta<0?'psl-out':'') }}">
                @if(isset($movement->expected_quantity))<div>انتظار: <b>{{ $n($movement->expected_quantity) }}</b></div><div>دریافت: <b>{{ $n($movement->accepted_quantity) }}</b></div>
                @else<strong>{{ $recorded?($delta>0?'+':($delta<0?'−':'')):'' }}{{ $n($recorded?abs($delta):$movement->quantity) }}</strong>@endif
                <small>{{ $movement->effect_label }}</small>@if(isset($movement->document_quantity_before))<small>تعداد قلم سند: {{ $n($movement->document_quantity_before) }} ← {{ $n($movement->document_quantity_after) }}</small>@endif
            </td>
            <td data-label="قبل / بعد" class="psl-snapshot">@if($movement->snapshot_reliable)<span><small>قبل</small> {{ $n($movement->stock_before) }}</span><span><small>بعد</small> {{ $n($movement->stock_after) }}</span>@else<span>—</span><small>ماندهٔ دقیق تاریخی قابل تأیید نیست</small>@endif</td>
            <td data-label="سند / توضیح علت">@if($source['url'])<a href="{{ $source['url'] }}">{{ $fa($source['label']) }} ↗</a>@else<span>{{ $fa($source['label']) }}</span>@endif
                @if($movement->related_url ?? null)<small><a href="{{ $movement->related_url }}">{{ $fa($movement->related_reference) }} ↗</a></small>@endif
                <p class="psl-explanation">{{ $movement->evidence_note }}</p><details><summary>جزئیات ثبت</summary><div class="psl-details"><div>شناسهٔ سابقه: {{ $fa($movement->id) }}</div><div>{{ $movement->note ?: 'توضیح بیشتری ثبت نشده است.' }}</div></div></details>
            </td>
        </tr>
    @empty<tr><td colspan="6" class="psl-empty">سابقه‌ای مطابق این جست‌وجو پیدا نشد.</td></tr>@endforelse
    </tbody></table><footer><span>{{ $n($movements->firstItem() ?: 0) }} تا {{ $n($movements->lastItem() ?: 0) }} از {{ $n($movements->total()) }} سابقه</span>{{ $movements->links() }}</footer></section>
    <p class="psl-hint">قبل و بعد فقط برای گردش‌های دارای مقادیر مطابق نمایش داده می‌شود. مقدارهای قدیمی یا تغییرات میانیِ ثبت‌نشده حدس زده نمی‌شوند.</p>
</div>
@endsection
