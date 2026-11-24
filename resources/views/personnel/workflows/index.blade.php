@extends('layouts.app')
@section('title',$kind==='leave'?'مرخصی‌ها':'گزارش کار')
@include('personnel.workflows.assets')
@section('content')
@php($prefix='personnel.'.($kind==='leave'?'leaves':'reports'))
<div class="hr hr-kind-{{ $kind }}" data-hr-workspace>
<header class="hr-heading"><div class="hr-heading-copy"><span class="hr-heading-icon">@include('personnel.workflows.icon',['name'=>$kind])</span><div><small>پرسنل و همکاری</small><h1>{{ $kind==='leave'?'مرخصی‌ها':'گزارش کار' }}</h1><p>{{ $kind==='leave'?'درخواست مرخصی و پیگیری مرحله‌به‌مرحلهٔ تأیید':'ثبت فعالیت‌ها، پیوست‌ها و گفت‌وگو با مسئول مربوطه' }}</p></div></div>
<div class="hr-heading-actions">@if($kind==='leave')<a class="hr-button hr-secondary" data-hr-export data-export-url="{{ route($prefix.'.export') }}" href="{{ route($prefix.'.export',request()->only(['tab','q','status','from','to'])) }}">@include('personnel.workflows.icon',['name'=>'download']) خروجی CSV</a><a class="hr-button hr-secondary" data-hr-export data-export-url="{{ route($prefix.'.print') }}" target="_blank" rel="noopener" href="{{ route($prefix.'.print',request()->only(['tab','q','status','from','to'])) }}">@include('personnel.workflows.icon',['name'=>'print']) چاپ / PDF</a>@endif
@if($canUse)<a class="hr-button" data-hr-compose href="{{ route($prefix.'.create') }}">@include('personnel.workflows.icon',['name'=>'plus']) {{ $kind==='leave'?'ثبت مرخصی':'ثبت گزارش کار' }}</a>@endif</div></header>
<nav class="hr-tabs" aria-label="نوع فهرست">
@if($canUse)<a href="{{ route($prefix.'.index',['tab'=>'mine']) }}" data-hr-tab="mine" @class(['is-current'=>!$team])>@include('personnel.workflows.icon',['name'=>'user']){{ $kind==='leave'?'مرخصی‌های من':'گزارش‌های من' }}</a>@endif
@if($canReview || ($kind==='leave' && $canUse))<a href="{{ route($prefix.'.index',['tab'=>'team']) }}" data-hr-tab="team" @class(['is-current'=>$team])>@include('personnel.workflows.icon',['name'=>'team']){{ $canReview?'بررسی زیرمجموعه':'درخواست‌های جایگزینی' }}</a>@endif
</nav>
<form class="hr-filters" data-hr-filters action="{{ route($prefix.'.index') }}" method="get">
<input type="hidden" name="tab" value="{{ $team?'team':'mine' }}">
<label class="hr-search">@include('personnel.workflows.icon',['name'=>'search'])<span class="visually-hidden">جستجوی عنوان یا نام پرسنل</span><input type="search" name="q" value="{{ request('q') }}" placeholder="جستجوی عنوان یا نام پرسنل…" maxlength="100"></label>
<label><span class="visually-hidden">وضعیت</span><select name="status"><option value="">همه وضعیت‌ها</option>@foreach(\App\Models\PersonnelEntry::STATUSES as $key=>$label)@if(in_array($key,$kind==='leave'?['pending','approved','rejected','cancelled']:['draft','submitted','read','needs_revision']))<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }}</option>@endif @endforeach</select></label>
@if($kind==='leave')<label class="hr-filter-date"><span class="visually-hidden">از تاریخ مرخصی</span><div class="hr-date-field"><input name="from" value="{{ request('from') }}" data-hr-date placeholder="از تاریخ شمسی" dir="ltr" maxlength="10"><button type="button" data-hr-calendar aria-label="انتخاب ابتدای بازه">@include('personnel.workflows.icon',['name'=>'calendar'])</button></div></label><label class="hr-filter-date"><span class="visually-hidden">تا تاریخ مرخصی</span><div class="hr-date-field"><input name="to" value="{{ request('to') }}" data-hr-date placeholder="تا تاریخ شمسی" dir="ltr" maxlength="10"><button type="button" data-hr-calendar aria-label="انتخاب انتهای بازه">@include('personnel.workflows.icon',['name'=>'calendar'])</button></div></label>@endif
<button class="hr-button hr-secondary" type="submit">@include('personnel.workflows.icon',['name'=>'search']) جستجو</button>
</form>
<div class="hr-feedback" role="status" aria-live="polite" data-hr-feedback></div>
@include('personnel.workflows.list')
<dialog class="hr-dialog hr-compose-dialog" id="hrComposeDialog" aria-labelledby="hrComposeTitle"><header><div class="hr-dialog-heading"><span class="hr-dialog-icon">@include('personnel.workflows.icon',['name'=>$kind])</span><div><small>پرسنل و همکاری</small><h2 id="hrComposeTitle">{{ $kind==='leave'?'ثبت مرخصی':'ثبت گزارش کار' }}</h2></div></div><button type="button" data-hr-compose-close aria-label="بستن فرم">@include('personnel.workflows.icon',['name'=>'close'])</button></header><div data-hr-compose-host></div></dialog>
<dialog class="hr-dialog" id="hrDetailDialog" aria-labelledby="hrDetailTitle"><header><div class="hr-dialog-heading"><span class="hr-dialog-icon">@include('personnel.workflows.icon',['name'=>'history'])</span><div><small>سوابق و گردش درخواست</small><h2 id="hrDetailTitle">جزئیات و پیگیری</h2></div></div><button type="button" data-hr-close aria-label="بستن جزئیات">@include('personnel.workflows.icon',['name'=>'close'])</button></header><div data-hr-detail-host></div></dialog>
@if($kind==='leave')@include('personnel.workflows.calendar')@endif
</div>
@endsection
