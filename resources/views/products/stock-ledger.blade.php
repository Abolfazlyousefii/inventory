@extends('layouts.app')
@section('title', 'گردش موجودی '.$product->name)
@push('styles')<link rel="stylesheet" href="{{ asset('css/product-stock-ledger.css') }}?v=20261010c">@endpush
@section('content')
@php
$reasons=['sales_return_correction'=>'اختلاف اصلاح برگشت از فروش','purchase_return'=>'برگشت از خرید','purchase_return_restore'=>'بازگشت موجودی؛ اصلاح یا حذف برگشت از خرید','purchase'=>'خرید','sale'=>'فروش','return'=>'مرجوعی','sales_return'=>'برگشت از فروش','transfer'=>'انتقال انبار','adjustment'=>'اصلاح موجودی','purchase_item_added'=>'افزودن ردیف خرید','purchase_item_quantity_changed'=>'تغییر تعداد خرید','purchase_item_removed'=>'حذف ردیف خرید','invoice_adjustment_return'=>'برگشت اصلاح فاکتور','invoice_cancel_return'=>'برگشت لغو فاکتور','stocktake'=>'انبارگردانی','stock_count'=>'انبارگردانی','reservation'=>'رزرو','reserve'=>'رزرو','reservation_release'=>'آزادسازی رزرو'];
@endphp
<div class="psl">
    <header class="psl-heading"><div><span>گردش موجودی کالا</span><h1>{{ $product->name }}</h1><p>{{ $selectedVariant?->variant_name ?: 'همهٔ تنوع‌ها' }}</p></div><a class="btn btn-outline-secondary" href="{{ route('products.index') }}">بازگشت به کالاها</a></header>
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    <div class="psl-summary"><span>موجودی آزاد فعلیِ مرکزی <strong>{{ $summary->current===null?'—':number_format($summary->current) }}</strong></span><span>فروش ثبت‌شده (بدون لغوشده‌ها) <strong class="psl-out">{{ number_format($summary->sold) }}</strong></span><span>{{ number_format($summary->records) }} سابقه و سند</span></div>
    <p class="psl-hint">ثبت موجودی، سند فروش و سابقهٔ رزرو کنار هم نمایش داده می‌شوند. سند مرتبط با رزرو، کسر دوباره نیست. تعداد اسناد و رزروها را برای محاسبهٔ موجودی با هم جمع نکنید.</p>
    <div class="psl-book-title">همهٔ سوابق ثبت‌شده تا امروز <span>جدیدترین گردش در بالا</span></div>
    <section class="psl-book"><table><thead><tr><th>تاریخ</th><th>تنوع / انبار</th><th>رویداد / منبع</th><th>تعداد / اثر</th><th>موجودی قبل / بعد</th><th>مرجع / جزئیات</th></tr></thead><tbody>
    @forelse($movements as $movement)
        @php $source=$sources[$movement->id]; @endphp
        @php $isIn=in_array($movement->type,['in','document_in'],true); $isOut=in_array($movement->type,['out','document_out'],true); $direct=in_array($movement->type,['in','out'],true); @endphp
        <tr class="psl-row {{ $isIn?'psl-row-in':($isOut?'psl-row-out':'') }}">
            <td data-label="تاریخ" class="psl-date">{{ \App\Support\JalaliDate::date($movement->created_at) }}<small>{{ $movement->created_at?->format('H:i') }}</small></td>
            <td data-label="تنوع / انبار">{{ $movement->variant?->variant_name ?: ($movement->product_variant_id ? 'تنوع حذف‌شده #'.$movement->product_variant_id : 'بدون تنوع') }}<small>{{ $movement->warehouse?->name ?: 'انبار نامشخص در سابقه' }}</small></td>
            <td data-label="رویداد / منبع">{{ $movement->transaction_type==='stocktake_adjustment' ? 'انبارگردانی' : ($reasons[$movement->reason] ?? $movement->reason ?: 'نامشخص') }}<small>{{ $movement->history_kind }}</small></td>
            <td data-label="تعداد / اثر" class="psl-change {{ $isIn?'psl-in':($isOut?'psl-out':'') }}"><strong>{{ $direct?($isIn?'+':'−'):'' }}{{ number_format($movement->quantity) }}</strong><small>{{ $direct?($isIn?'ورودی موجودی':'خروجی موجودی'):($isIn?'سند ورودی':($isOut?'سند خروجی / رزرو':'بدون کسر یا برگشت اثبات‌شده')) }}</small></td>
            <td data-label="قبل / بعد" class="psl-snapshot"><span><small>قبل</small> {{ $movement->stock_before===null?'—':number_format($movement->stock_before) }}</span><span><small>بعد</small> {{ $movement->stock_after===null?'—':number_format($movement->stock_after) }}</span></td>
            <td data-label="مرجع / جزئیات">@if($source['url'])<a href="{{ $source['url'] }}">{{ $source['label'] }} ↗</a>@else<span>{{ $source['label'] }}</span>@endif
                <details><summary>جزئیات ثبت</summary><div class="psl-details"><div>شناسهٔ گردش: {{ $movement->id }}</div><div>ثبت‌کننده: {{ $movement->user?->name ?: 'نامشخص' }}</div><div>{{ $movement->note ?: 'توضیحی ثبت نشده است.' }}</div>@if($movement->transaction_type)<div>نوع ثبت: {{ $movement->transaction_type }}</div>@endif</div></details>
            </td>
        </tr>
    @empty<tr><td colspan="6" class="psl-empty">گردشی برای این کالا ثبت نشده است.</td></tr>@endforelse
    </tbody></table><footer><span>{{ $movements->firstItem() ?: 0 }} تا {{ $movements->lastItem() ?: 0 }} از {{ number_format($movements->total()) }} گردش</span>{{ $movements->links() }}</footer></section>
    <p class="psl-hint">عدد قبل و بعد فقط در صورت وجود سابقهٔ معتبر نمایش داده می‌شود. تغییرات میانیِ ثبت‌نشده یا سوابق حذف‌شده قابل بازسازی قطعی نیستند.</p>
</div>
@endsection
