@extends('layouts.app')
@section('title',$transfers?'انتقال مشتری':'مشتریان من')
@include('personnel.workflows.assets')
@push('scripts')<script defer src="{{ asset('js/commercial-customers.js') }}?v={{ filemtime(public_path('js/commercial-customers.js')) }}"></script>@endpush
@section('content')
<div class="hr" data-customer-workspace>
<header class="hr-heading"><div><small>بازرگانی و فروش</small><h1>{{ $transfers?'انتقال مشتری':'مشتریان من' }}</h1><p>{{ $transfers?'مسئول پیگیری مشتری را تغییر دهید؛ فروشنده و اسناد فاکتورهای قبلی حفظ می‌شوند.':'مشتریان تخصیص‌یافته به شما برای پیگیری و ثبت فروش' }}</p></div></header>
<form class="hr-filters" data-customer-filters method="get"><label class="hr-search"><span class="visually-hidden">جستجوی مشتری</span><input name="q" value="{{ request('q') }}" placeholder="نام، موبایل یا کد مشتری" maxlength="100"></label>
@if($transfers)<select name="seller_id" aria-label="مسئول فعلی"><option value="">همه مسئولان</option>@foreach($sellers as $seller)<option value="{{ $seller->id }}" @selected(request('seller_id')==$seller->id)>{{ $seller->name }}</option>@endforeach</select><label class="d-flex gap-2 align-items-center"><input style="width:auto" type="checkbox" name="unassigned" value="1" @checked(request()->boolean('unassigned'))>بدون مسئول</label>@endif<button class="hr-button" type="submit">جستجو</button></form>
<div class="hr-feedback" data-customer-feedback role="status"></div>
@include('commercial.customers.list')
@if($transfers)<dialog class="hr-dialog" data-transfer-dialog aria-labelledby="transferTitle"><header><h2 id="transferTitle">انتقال مشتری</h2><button type="button" data-transfer-close aria-label="بستن">×</button></header><form class="hr-detail" data-transfer-form><div class="hr-feedback" data-transfer-feedback role="alert"></div>@csrf<input type="hidden" name="version"><p data-transfer-name></p><label class="hr-field">مسئول جدید<select name="seller_id" required><option value="">انتخاب همکار</option>@foreach($sellers as $seller)<option value="{{ $seller->id }}">{{ $seller->name }}</option>@endforeach</select></label><label class="hr-field mt-3">دلیل انتقال<textarea name="reason" required maxlength="1000" rows="3"></textarea></label><p class="hr-help">فقط مسئول ارتباط با مشتری تغییر می‌کند. موجودی، حساب مشتری و مالکیت فاکتورهای قبلی تغییر نمی‌کند.</p><footer class="hr-form-actions"><button class="hr-button" type="submit">تأیید انتقال مشتری</button></footer></form></dialog>@endif
</div>
@endsection
