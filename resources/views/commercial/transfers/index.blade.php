@extends('layouts.app')
@section('title','اسناد انتقال')
@push('styles')<link rel="stylesheet" href="{{ asset('css/commercial-transfers.css') }}?v={{ filemtime(public_path('css/commercial-transfers.css')) }}">@endpush
@push('scripts')<script defer src="{{ asset('js/commercial-transfers.js') }}?v={{ filemtime(public_path('js/commercial-transfers.js')) }}"></script>@endpush
@section('content')
<div class="transfer-workspace" id="transferWorkspace" data-index="{{ route('commercial.transfers.index') }}" data-search="{{ route('commercial.transfers.search') }}" data-store="{{ route('commercial.transfers.store') }}">
<section class="hero"><div><div class="eyebrow">بازرگانی و فروش</div><h1>اسناد انتقال</h1><p>هر انتقال، یک سند مشخص؛ از انتخاب تا ثبت مستقیم.</p></div><button class="primary" type="button" data-new>＋ ثبت انتقال جدید</button></section>
<div id="transferResults">@include('commercial.transfers.list')</div>
@if(in_array('invoice',$kinds))<a class="secondary" style="margin-top:12px" href="{{ route('commercial.invoice-reassignments.index',['history'=>1]) }}">سوابق انتقال فاکتور پیش از اسناد جدید</a>@endif
<div class="guidance"><p><b>مشتری و فاکتور مستقل منتقل می‌شوند.</b> انتقال مشتری فقط مسئول پرونده را تغییر می‌دهد؛ انتقال فاکتور، فروشندهٔ همان فاکتورها را. فاکتورهای قبلی با انتقال مشتری جابه‌جا نمی‌شوند.</p></div>
<dialog id="transferEditor" aria-labelledby="transferEditorTitle"><header class="dialog-head"><span class="type-icon">⇄</span><div><h2 id="transferEditorTitle">ثبت انتقال جدید</h2><small>ثبت مستقیم؛ پیش‌نویس فقط اگر کارتان ناتمام است</small></div><button class="close" type="button" data-close aria-label="بستن فرم">×</button></header>
<form id="transferDocumentForm"><div class="dialog-body">@csrf<input type="hidden" name="submission_key" value="{{ \Illuminate\Support\Str::uuid() }}"><div class="form-intro">موارد و همکار مقصد را انتخاب کنید؛ با «ثبت انتقال» کار تمام است.</div>
<div class="form-type">@foreach($kinds as $kind)<label class="kind-option"><input type="radio" name="kind" value="{{ $kind }}" @checked($loop->first)>{{ $kind==='customer'?'انتقال مشتری':'انتقال فاکتور' }}</label>@endforeach</div>
<div class="two-fields"><label class="field">از همکار<select name="from_id" required><option value="0">بدون مسئول</option>@foreach($sellers as $seller)<option value="{{ $seller->id }}">{{ $seller->name }}</option>@endforeach</select></label><label class="field">به همکار<select name="to_id" required><option value="">انتخاب همکار مقصد</option>@foreach($sellers as $seller)<option value="{{ $seller->id }}">{{ $seller->name }}</option>@endforeach</select></label></div>
<div class="field"><span data-selection-title>انتخاب موارد</span><small style="float:left">حداکثر ۱۰۰ مورد در هر سند</small><input type="search" data-item-search placeholder="نام، شماره یا کد…" aria-label="جستجوی موارد انتقال" maxlength="100"><div class="form-count" data-selected-count>۰ مورد انتخاب‌شده</div><div class="selection-list" data-selection></div><button type="button" class="load-button" data-more hidden>دریافت موارد بیشتر</button></div>
<label class="field">دلیل انتقال<textarea name="reason" required maxlength="1000" rows="2" placeholder="مثلاً تقسیم مشتریان یا اصلاح فروشندهٔ فاکتور…"></textarea></label><div class="error" role="alert" data-form-error></div><div class="note" data-effect></div></div>
<footer class="dialog-footer"><button class="secondary" type="submit" name="action" value="draft">ذخیرهٔ پیش‌نویس</button><button class="primary" type="submit" name="action" value="register">ثبت انتقال</button></footer></form></dialog>
<dialog id="transferDetail" aria-label="جزئیات سند انتقال"><header class="dialog-head"><h2>جزئیات سند انتقال</h2><button class="close" type="button" data-close aria-label="بستن جزئیات">×</button></header><div data-detail></div></dialog>
<div class="toast" role="status" data-toast hidden></div></div>
@endsection
