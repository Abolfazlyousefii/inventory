@extends('layouts.app')
@section('title','برگشت از خرید '.$document->document_number)
@push('styles')<link rel="stylesheet" href="{{ asset('css/purchase-returns.css') }}?v=1">@endpush
@section('content')
@php
$fa = fn($value) => strtr((string)$value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
$money = fn($value) => $fa(number_format((int)$value)).' ریال';
@endphp

<div class="pr-page">
    <header class="pr-heading"><div><span class="pr-eyebrow">سند برگشت از خرید</span><h1>{{ $fa($document->document_number) }}</h1><p>{{ $document->supplier_name }} · {{ $fa(\App\Support\JalaliDate::date($document->returned_at)) }}</p></div><div class="pr-actions"><a class="btn btn-outline-secondary" href="{{ route('purchase-returns.index') }}">بازگشت</a><a class="btn btn-outline-primary" href="{{ route('purchase-returns.print',$document->id) }}" target="_blank" rel="noopener">چاپ / PDF</a>@if(!$document->trashed())<a class="btn btn-primary" href="{{ route('purchase-returns.edit',$document) }}">ویرایش</a>@endif</div></header>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    @if($document->trashed())<div class="alert alert-warning">این سند حذف شده و کالاهای آن به موجودی آزاد برگشته‌اند. {{ $document->delete_reason }}</div>@endif
    <div class="pr-summary"><div><span>خرید مرجع</span>@if($document->purchase)<a href="{{ route('purchases.show',$document->purchase_id) }}">{{ $fa($document->purchase_id) }} ↗</a>@else<strong>{{ $fa($document->purchase_id) }}</strong>@endif</div><div><span>تعداد برگشت</span><strong>{{ $fa(number_format($document->total_quantity)) }}</strong></div><div><span>مبلغ سند</span><strong>{{ $money($document->total_amount) }}</strong></div></div>
    <section class="pr-panel"><table class="pr-table"><thead><tr><th>کالا</th><th>تنوع</th><th>تعداد</th><th>قیمت خرید</th><th>جمع</th></tr></thead><tbody>@foreach($document->items as $item)<tr><td data-label="کالا">{{ $item->product_name }}</td><td data-label="تنوع">{{ $item->variant_name ?: '—' }}</td><td data-label="تعداد">{{ $fa($item->quantity) }}</td><td data-label="قیمت خرید">{{ $money($item->buy_price) }}</td><td data-label="جمع">{{ $money($item->line_total) }}</td></tr>@endforeach</tbody></table></section>
    @if($document->note)<p class="pr-note">{{ $document->note }}</p>@endif
    <details class="pr-history pr-panel"><summary>سوابق سند <span>{{ $fa($document->revisions->count()) }}</span></summary>@foreach($document->revisions as $revision)<article><strong>{{ ['created'=>'ثبت سند','updated'=>'ویرایش سند','deleted'=>'حذف و بازگرداندن موجودی'][$revision->action]??$revision->action }}</strong><small>{{ $revision->user?->name ?: 'کاربر '.$fa($revision->user_id) }} · {{ $fa(\App\Support\JalaliDate::dateTime($revision->created_at)) }}</small>@if($revision->before)<p>تعداد: {{ $fa($revision->before['total_quantity']) }} ← {{ $fa($revision->after['total_quantity']) }} · مبلغ: {{ $money($revision->before['total_amount']) }} ← {{ $money($revision->after['total_amount']) }}</p>@endif @if($revision->note)<p>{{ $revision->note }}</p>@endif<details><summary>جزئیات کالاها</summary>@foreach(($revision->after['items']??[]) as $row)<p>{{ $row['product_name'] }} · {{ $row['variant_name'] }} · {{ $fa($row['quantity']) }} عدد · {{ $money($row['line_total']) }}</p>@endforeach</details></article>@endforeach</details>
    @if(!$document->trashed())<details class="pr-delete"><summary>حذف سند</summary><p>با حذف، {{ $fa($document->total_quantity) }} عدد به موجودی آزاد مرکزی برمی‌گردد. سابقهٔ سند حفظ می‌شود.</p><form method="post" action="{{ route('purchase-returns.destroy',$document) }}" data-pr-delete>@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $document->version }}"><input class="form-control" name="reason" maxlength="1000" placeholder="توضیح حذف (اختیاری)" aria-label="توضیح حذف"><button class="btn btn-outline-danger" type="submit">حذف و بازگرداندن موجودی</button></form></details>@endif
</div>
@endsection
@push('scripts')<script src="{{ asset('js/purchase-returns.js') }}?v=1" defer></script>@endpush
