@extends('layouts.app')
@section('title', 'مشاهدهٔ پیش‌فاکتور')
@section('page-title', 'مشاهدهٔ پیش‌فاکتور')
@section('content')
@php
 $money = fn($v) => \App\Support\Currency::formatRial($v);
 $date = function($v){if(!$v)return '—';try{return \Morilog\Jalali\Jalalian::fromDateTime($v)->format('Y/m/d H:i');}catch(\Throwable $e){return (string)$v;}};
 $totals = \App\Support\SalesDocumentTotals::fromDocument($order);
 $cancelledInvoice = $order->invoice?->status === \App\Models\Invoice::STATUS_NOT_SHIPPED;
 $cancelled = $cancelledInvoice || in_array($order->status, [\App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE,\App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE], true);
 $status = $cancelledInvoice ? 'لغوشده به دلیل کنسلی فاکتور مرتبط' : $order->status_label;
 $timeline = $timeline ?? app(\App\Services\PreinvoiceTimelineService::class)->entries($order);
 $pageUrl = url()->current();
@endphp
<link rel="stylesheet" href="{{ asset('css/preinvoice-detail.css') }}?v=20261007">
<main class="pd-page" dir="rtl">
 <header class="pd-header">
  <div><div class="pd-eyebrow">پیش‌فاکتور فروش</div><h1>مشاهدهٔ پیش‌فاکتور <bdi>{{ $order->uuid }}</bdi></h1><p>{{ $date($order->created_at) }} <span class="pd-status {{ $cancelled ? 'pd-danger' : '' }}">{{ $status }}</span></p></div>
  <nav class="pd-actions" aria-label="عملیات پیش‌فاکتور">
   <a class="pd-button pd-primary" target="_blank" rel="noopener" href="{{ $pageUrl }}?download=pdf">PDF نسخهٔ مشتری</a>
   <a class="pd-button" target="_blank" rel="noopener" href="{{ $pageUrl }}?mode=customer">چاپ مشتری</a>
   <button class="pd-button" type="button" data-pd-history>سوابق <span>{{ $timeline->count() }}</span></button>
   <a class="pd-button" href="{{ route('preinvoice.my.index') }}">بازگشت</a>
  </nav>
 </header>
 @if($cancelled)<div class="pd-notice pd-danger">این پیش‌فاکتور لغو شده است.@if($order->warehouse_reject_reason) دلیل: {{ $order->warehouse_reject_reason }}@endif</div>@endif
 @if($documentAction['can_edit'] ?? false)<div class="pd-notice"><a href="{{ $documentAction['edit_url'] }}">ادامهٔ ویرایش پیش‌نویس</a></div>@elseif(!empty($documentAction['disabled_reason']))<div class="pd-notice">{{ $documentAction['disabled_reason'] }}</div>@endif
 <section class="pd-summary" aria-label="خلاصه سند">
  <div><span>مبلغ نهایی</span><strong>{{ $money($totals['grand_total']) }}</strong></div>
  <div><span>تعداد کالا</span><strong>{{ number_format($order->items->sum('quantity')) }}</strong></div>
  <div><span>ردیف کالا</span><strong>{{ number_format($order->items->count()) }}</strong></div>
 </section>
 <section class="pd-panel"><h2>مشتری و ارسال</h2><dl class="pd-info">
  <div><dt>مشتری</dt><dd>{{ $order->customer_name ?: '—' }}</dd></div>
  <div><dt>شماره تماس</dt><dd><bdi>{{ $order->customer_mobile ?: '—' }}</bdi></dd></div>
  <div><dt>ثبت‌کننده</dt><dd>{{ $order->creator?->name ?: '—' }}</dd></div>
  <div><dt>روش ارسال</dt><dd>{{ $order->shippingMethod?->name ?: '—' }}</dd></div>
 </dl><details class="pd-extra"><summary>آدرس، توضیحات و وضعیت رزرو</summary><dl class="pd-info">
  <div class="pd-wide"><dt>آدرس</dt><dd>{{ $order->customer_address ?: '—' }}</dd></div>
  <div class="pd-wide"><dt>توضیحات</dt><dd>{{ $order->description ?: 'توضیحی ثبت نشده است.' }}</dd></div>
  <div><dt>بازبین انبار</dt><dd>{{ $order->warehouseReviewer?->name ?: '—' }}</dd></div>
  <div><dt>مهلت رزرو</dt><dd>{{ $date($order->stock_frozen_until) }}</dd></div>
  <div><dt>زمان آزادسازی</dt><dd>{{ $date($order->stock_released_at) }}</dd></div>
 </dl></details></section>
 <section class="pd-panel pd-items"><div class="pd-section-heading"><h2>اقلام پیش‌فاکتور</h2><span>مبالغ به ریال</span></div>
 <table><thead><tr><th>کالا / مدل</th><th>تعداد</th><th>قیمت واحد</th><th>تخفیف ردیف</th><th>جمع خالص</th></tr></thead><tbody>
 @forelse($order->items as $item)
 <tr><td class="pd-product"><strong>{{ $item->product?->name ?: 'محصول نامشخص' }}</strong><small>{{ $item->variant?->variant_name ?: 'بدون تنوع' }}</small>@if($item->variant?->color?->name)<small>{{ $item->variant->color->name }}</small>@endif@include('partials.bundle-contents', ['productIsBundle' => $item->product?->is_bundle, 'variantId' => $item->variant_id, 'bundleQuantity' => $item->quantity])</td>
 <td data-label="تعداد">{{ number_format($item->quantity) }}</td><td data-label="قیمت واحد">{{ number_format($item->price) }}</td><td data-label="تخفیف ردیف">{{ number_format(\App\Support\SalesDocumentTotals::lineDiscount($item)) }}</td><td data-label="جمع خالص" class="pd-line-total">{{ number_format(\App\Support\SalesDocumentTotals::lineTotal($item)) }}</td></tr>
 @empty<tr><td colspan="5" class="pd-empty">کالایی ثبت نشده است.</td></tr>@endforelse
 </tbody></table></section>
 <section class="pd-panel pd-totals" aria-label="جمع مبالغ"><div><span>جمع کالاها</span><bdi>{{ $money($totals['subtotal_before_discount']) }}</bdi></div><div><span>کل تخفیف‌ها</span><bdi>{{ $money($totals['total_discount']) }}</bdi></div><div><span>هزینه ارسال</span><bdi>{{ $money($totals['shipping']) }}</bdi></div><div class="pd-final"><span>مبلغ نهایی</span><bdi>{{ $money($totals['grand_total']) }}</bdi></div></section>
 <dialog class="pd-dialog" id="pd-history" aria-labelledby="pd-history-title">
  <header><div><h2 id="pd-history-title">سوابق پیش‌فاکتور {{ $order->uuid }}</h2><p>تمام سوابق ثبت‌شدهٔ سند و بازبینی‌ها؛ جدیدترین در ابتدا</p></div><button class="pd-close" type="button" aria-label="بستن سوابق" data-pd-close>×</button></header>
  <div class="pd-history-body">@forelse($timeline as $event)<article class="pd-event"><div class="pd-event-meta"><span>{{ $event['source'] }}</span><time>{{ $date($event['time']) }}</time></div><h3>{{ $event['title'] }}</h3><p class="pd-actor">{{ $event['actor'] }}</p>@if($event['details'])<details><summary>جزئیات عملیات</summary><ul>@foreach($event['details'] as $line)<li>{{ $line }}</li>@endforeach</ul></details>@endif</article>@empty<p class="pd-empty">برای این سند سابقه‌ای ثبت نشده است.</p>@endforelse</div>
 </dialog>
</main>
<script src="{{ asset('js/preinvoice-detail.js') }}?v=20261007" defer></script>
@endsection
