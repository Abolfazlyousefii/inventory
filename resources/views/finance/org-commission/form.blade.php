@extends('layouts.app')
@section('title', $document ? 'ویرایش سند پورسانت اداری' : 'صدور سند پورسانت اداری')
@section('page-title', 'مالی / پورسانت اداری')
@push('styles')<link rel="stylesheet" href="{{ asset('css/pages/org-commission.css') }}?v={{ filemtime(public_path('css/pages/org-commission.css')) }}">@endpush
@section('content')
@php
    use App\Support\Currency;
    use App\Support\JalaliDate;
    $isEdit = (bool) $document;
    $monthStart = JalaliDate::format(now(), 'Y/m/01');
    $monthPrefix = substr($monthStart, 0, 8);
    $monthEnd = $monthPrefix.'29';
    foreach ([31,30,29] as $day) { if (JalaliDate::toGregorianDate($monthPrefix.$day)) { $monthEnd=$monthPrefix.$day; break; } }
    $prefillFrom=old('period_from', $isEdit ? JalaliDate::date($document->period_from, '') : $monthStart);
    $prefillTo=old('period_to', $isEdit ? JalaliDate::date($document->period_to, '') : $monthEnd);
    $initialTotal=$isEdit ? (int)$document->total_seller_commission : 0;
    $ready=collect($snapshot['departments'])->flatMap(fn($d)=>$d['members'])->isNotEmpty() && collect($snapshot['departments'])->flatMap(fn($d)=>$d['members'])->every(fn($m)=>$m['percentage']!==null);
@endphp
<div class="org-rates-page" id="orgCommissionForm" data-seller-total-url="{{ route('finance.org-commission.seller-total') }}" data-initial-total="{{ $initialTotal }}">
    <header class="org-rates-header"><div><span>پورسانت اداری</span><h1>{{ $isEdit ? 'ویرایش بازه سند' : 'صدور سند ماهانه' }}</h1><p>محاسبه خودکار سهم افراد با درصدهای ثابت</p></div><a class="btn btn-outline-secondary" href="{{ route('finance.org-commission.index', ['tab'=>'documents']) }}">بازگشت</a></header>
    @if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<p class="mb-1">{{ $error }}</p>@endforeach</div>@endif
    <div class="org-rates-note">{{ $isEdit ? 'درصدهای زمان صدور این سند حفظ می‌شوند؛ تغییر بازه فقط مبلغ‌ها را دوباره محاسبه می‌کند.' : 'درصدها از تنظیمات خوانده می‌شوند و در سند قابل تغییر نیستند.' }} <a href="{{ route('finance.org-commission.settings') }}">تنظیمات درصد افراد</a></div>
    @if(!$ready)<div class="alert alert-warning">درصد همه افراد فعال را در تنظیمات مشخص کنید؛ سپس سند صادر کنید.</div>@endif
    <form method="POST" action="{{ $isEdit ? route('finance.org-commission.update', $document) : route('finance.org-commission.store') }}" id="orgDocumentForm">
        @csrf @if($isEdit) @method('PUT') @endif
        <input type="hidden" name="settings_version" value="{{ $snapshot['version'] }}">
        <section class="org-rates-card">
            <div class="org-rates-period">
                <div><label for="periodFrom">از تاریخ</label><input class="form-control" id="periodFrom" name="period_from" data-jdp autocomplete="off" value="{{ $prefillFrom }}" required></div>
                <div><label for="periodTo">تا تاریخ</label><input class="form-control" id="periodTo" name="period_to" data-jdp autocomplete="off" value="{{ $prefillTo }}" required></div>
                <button type="button" class="btn btn-outline-primary" id="calculateTotalButton">محاسبه سهم‌ها</button>
            </div>
            <div class="org-rates-totals"><div>کل پورسانت خالص فروشندگان<strong id="sellerTotalDisplay">{{ Currency::formatRial($initialTotal) }}</strong></div><div>جمع پورسانت اداری<strong id="allocationTotal">{{ Currency::formatRial(0) }}</strong></div></div>
            <p class="small mb-0">مبنای محاسبه، اسناد تأییدشده و نهایی‌شده فروشندگانی است که بازه آن‌ها داخل بازه انتخابی قرار می‌گیرد.</p>
        </section>
        <div class="org-rates-grid mt-3">
        @foreach($snapshot['departments'] as $department)
            <section class="org-rates-card"><h2>{{ $department['name'] }}</h2>
            @foreach($department['members'] as $member)
                <div class="org-rate-person"><div><strong>{{ $member['name'] }}</strong><small>{{ $member['role'] ?: 'سهم از کل پورسانت' }}</small></div><div class="org-rate-value"><small>{{ $member['percentage'] !== null ? rtrim(rtrim($member['percentage'], '0'), '.').'٪' : 'تعیین نشده' }}</small><b class="calculated-share" data-rate="{{ $member['percentage'] ?? 0 }}">۰ ریال</b></div></div>
            @endforeach
            </section>
        @endforeach
        </div>
        <section class="org-rates-card mt-3"><label for="documentNotes">یادداشت سند (اختیاری)</label><textarea class="form-control mt-2" id="documentNotes" name="notes" rows="2" maxlength="2000">{{ old('notes', $document?->notes) }}</textarea></section>
        <footer class="org-rates-footer"><span id="calculationStatus" role="status">نتیجه نهایی هنگام ثبت، دوباره در سرور محاسبه می‌شود.</span><button class="btn btn-success" type="submit" @disabled(!$ready)>{{ $isEdit ? 'ذخیره سند' : 'ثبت سند ماهانه' }}</button></footer>
    </form>
</div>
@endsection
@push('scripts')
<script>
(function($){
    'use strict';
    var app=$('#orgCommissionForm'), base=Number(app.data('initial-total'))||0, serial=0;
    function money(n){return n.toLocaleString('fa-IR')+' ریال';}
    function render(){var total=0;$('.calculated-share').each(function(){var rate=Math.round(Number(this.dataset.rate)*10000), amount=Math.floor(base/1000000)*rate+Math.floor(((base%1000000)*rate+500000)/1000000);total+=amount;$(this).text(money(amount));});$('#sellerTotalDisplay').text(money(base));$('#allocationTotal').text(money(total));}
    $('#calculateTotalButton').on('click',function(){var from=$('#periodFrom').val(),to=$('#periodTo').val(),request=++serial;if(!from||!to)return;var button=$(this).prop('disabled',true);$('#calculationStatus').text('در حال محاسبه…');$.getJSON(app.data('seller-total-url'),{period_from:from,period_to:to}).done(function(r){if(request!==serial)return;base=Number(r.total)||0;if(base<0){$('#calculationStatus').text('مبنای پورسانت منفی است؛ اسناد فروشندگان را بررسی کنید.');return;}render();$('#calculationStatus').text('پیش‌نمایش محاسبه شد؛ هنگام ثبت دوباره بررسی می‌شود.');}).fail(function(){$('#calculationStatus').text('محاسبه ناموفق بود؛ بازه را بررسی کنید.');}).always(function(){button.prop('disabled',false);});});
    $('#periodFrom,#periodTo').on('input change',function(){serial++;base=0;render();$('#calculationStatus').text('بازه تغییر کرد؛ برای دیدن مبلغ‌ها «محاسبه سهم‌ها» را بزنید.');});
    render();
})(jQuery);
</script>
@endpush
