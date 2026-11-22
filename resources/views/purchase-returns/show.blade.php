@extends('layouts.app')
@section('title', 'برگشت از خرید '.$document->document_number)
@push('styles')<link rel="stylesheet" href="{{ asset('css/purchase-returns.css') }}?v={{ substr(hash_file('sha256', public_path('css/purchase-returns.css')), 0, 12) }}">@endpush
@section('content')
@php
    $fa = fn ($value) => strtr((string) $value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    $money = fn ($value) => $fa(number_format((int) $value)).' ریال';
    $user = auth()->user();
    $canEdit = $user && \App\Support\PageAccessCatalog::userCanRoute($user, 'purchase-returns.edit');
    $canCancel = $user && \App\Support\PageAccessCatalog::userCanRoute($user, 'purchase-returns.destroy');
@endphp
<div class="pr-page">
    <header class="pr-heading">
        <div>
            <span class="pr-eyebrow">سند برگشت از خرید</span>
            <h1>{{ $fa($document->document_number) }}</h1>
            <p>{{ $document->supplier_name }} · {{ $document->warehouse?->name ?: 'انبار #'.$document->warehouse_id }} · {{ $fa(\App\Support\JalaliDate::date($document->returned_at)) }}</p>
        </div>
        <div class="pr-actions">
            <a class="btn btn-outline-secondary" href="{{ route('purchase-returns.index') }}">بازگشت</a>
            <button class="btn btn-outline-primary" type="button" data-pr-print-url="{{ route('purchase-returns.print', $document->id) }}">چاپ</button>
            @unless($document->trashed())
                @if($canEdit)<a class="btn btn-primary" href="{{ route('purchase-returns.edit', $document) }}">ویرایش</a>@endif
                @if($canCancel)
                    <button class="btn btn-outline-danger" type="button" data-pr-cancel-trigger
                        data-action="{{ route('purchase-returns.destroy', $document) }}"
                        data-version="{{ $document->version }}"
                        data-number="{{ $document->document_number }}"
                        data-supplier="{{ $document->supplier_name }}"
                        data-purchase="{{ $document->purchase_id }}"
                        data-warehouse="{{ $document->warehouse?->name ?: 'انبار #'.$document->warehouse_id }}"
                        data-items-count="{{ $document->items->count() }}"
                        data-quantity="{{ $document->total_quantity }}">ابطال سند</button>
                @endif
            @endunless
        </div>
    </header>
    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    @if($document->trashed())
        <div class="alert alert-warning" role="status">این سند ابطال‌شده و فقط قابل مشاهده و چاپ است. موجودی به انبار مبدأ بازگشته و اثر حساب تأمین‌کننده معکوس شده است.
            @if($document->delete_reason)<div>دلیل ابطال: {{ $document->delete_reason }}</div>@endif
            @if($document->deleted_at)<small>زمان ابطال: {{ $fa(\App\Support\JalaliDate::dateTime($document->deleted_at)) }} · نسخه: {{ $fa($document->version) }}</small>@endif
        </div>
    @endif
    <div class="pr-summary">
        <div><span>خرید مرجع</span>@if($document->purchase)<a href="{{ route('purchases.show', $document->purchase_id) }}">{{ $fa($document->purchase_id) }}</a>@else<strong>{{ $fa($document->purchase_id) }}</strong>@endif</div>
        <div><span>تعداد برگشت</span><strong>{{ $fa(number_format($document->total_quantity)) }}</strong></div>
        <div><span>مبلغ سند</span><strong>{{ $money($document->total_amount) }}</strong></div>
    </div>
    <section class="pr-panel">
        <table class="pr-table">
            <thead><tr><th>کالا</th><th>تنوع</th><th>تعداد</th><th>قیمت خرید</th><th>مبلغ خالص برگشت</th></tr></thead>
            <tbody>@foreach($document->items as $item)
                <tr><td data-label="کالا">{{ $item->product_name }}</td><td data-label="تنوع">{{ $item->variant_name ?: '—' }}</td><td data-label="تعداد">{{ $fa($item->quantity) }}</td><td data-label="قیمت خرید">{{ $money($item->buy_price) }}</td><td data-label="مبلغ خالص">{{ $money($item->line_total) }}</td></tr>
            @endforeach</tbody>
        </table>
    </section>
    @if($document->note)<p class="pr-note">{{ $document->note }}</p>@endif
    <details class="pr-history pr-panel">
        <summary>سوابق سند <span>{{ $fa($document->revisions->count()) }}</span></summary>
        @foreach($document->revisions as $revision)
            <article>
                <strong>{{ ['created'=>'ثبت سند','updated'=>'ویرایش سند','deleted'=>'ابطال سند و بازگرداندن موجودی'][$revision->action] ?? $revision->action }}</strong>
                <small>{{ $revision->user?->name ?: 'کاربر '.$fa($revision->user_id) }} · {{ $fa(\App\Support\JalaliDate::dateTime($revision->created_at)) }}</small>
                @if($revision->before)<p>تعداد: {{ $fa($revision->before['total_quantity']) }} ← {{ $fa($revision->after['total_quantity']) }} · مبلغ: {{ $money($revision->before['total_amount']) }} ← {{ $money($revision->after['total_amount']) }}</p>@endif
                @if($revision->note)<p>دلیل: {{ $revision->note }}</p>@endif
                <details><summary>جزئیات کالاها</summary>
                    @foreach(($revision->after['items'] ?? []) as $row)<p>{{ $row['product_name'] }} · {{ $row['variant_name'] }} · {{ $fa($row['quantity']) }} عدد · {{ $money($row['line_total']) }}</p>@endforeach
                </details>
            </article>
        @endforeach
    </details>
    @if(!$document->trashed() && $canCancel)@include('purchase-returns._cancel-modal')@endif
</div>
@endsection
@push('scripts')<script src="{{ asset('js/purchase-returns.js') }}?v={{ substr(hash_file('sha256', public_path('js/purchase-returns.js')), 0, 12) }}" defer></script>@endpush
