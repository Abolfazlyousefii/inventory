@extends('layouts.app')
@section('title','مشتریان من')
@include('commercial.customer-workspace.assets')
@section('content')
<div class="ce-workspace" data-ce-list>
<div class="page-head"><div><div class="eyebrow"><span class="dot"></span> بازرگانی و فروش</div><h1>مشتریان من</h1><p class="ce-muted">{{ $broad?'پرونده‌ها در محدودهٔ دسترسی شما':'پروندهٔ مشتریان تخصیص‌یافته به شما' }}</p></div></div>
<form class="toolbar" data-ce-filters method="get" action="{{ route('commercial.customers.index') }}"><div class="search-row"><label class="search-box">@include('commercial.customer-workspace.icon',['name'=>'search'])<input type="search" name="q" value="{{ request('q') }}" placeholder="نام، موبایل یا کد مشتری…" aria-label="جستجوی مشتری" maxlength="100"></label><button class="tool-button" type="reset">بازنشانی</button><button class="tool-button" type="submit">جستجو</button></div>
<div class="filter-row"><div class="chips" role="group" aria-label="وضعیت حساب">@foreach(['all'=>'همهٔ حساب‌ها','debt'=>'بدهکار','credit'=>'بستانکار','settled'=>'تسویه‌شده'] as $key=>$label)<button type="button" class="chip {{ request('balance','all')===$key?'active':'' }}" data-balance="{{ $key }}" aria-pressed="{{ request('balance','all')===$key?'true':'false' }}">{{ $label }}</button>@endforeach<input type="hidden" name="balance" value="{{ request('balance','all') }}"></div>
<div class="dropdowns"><label class="select-wrap"><select name="status" aria-label="وضعیت مشتری">@foreach(['active'=>'مشتریان فعال','inactive'=>'مشتریان غیرفعال','all'=>'همهٔ مشتریان'] as $key=>$label)<option value="{{ $key }}" @selected(request('status','active')===$key)>{{ $label }}</option>@endforeach</select></label>
@if($broad)<label class="select-wrap"><select name="seller_id" aria-label="مسئول مشتری"><option value="">همهٔ مسئولان</option><option value="0" @selected(request('seller_id')==='0')>بدون مسئول</option>@foreach($sellers as $seller)<option value="{{ $seller->id }}" @selected(request('seller_id')==$seller->id)>{{ $seller->name }}</option>@endforeach</select></label>@endif
<label class="select-wrap"><select name="sort" aria-label="مرتب‌سازی">@foreach(['recent'=>'جدیدترین پرونده','name'=>'نام مشتری','balance'=>'بیشترین بدهی'] as $key=>$label)<option value="{{ $key }}" @selected(request('sort','recent')===$key)>{{ $label }}</option>@endforeach</select></label></div></div></form>
<p class="ce-error" data-ce-error role="alert"></p>@include('commercial.customer-workspace.list')
</div>
@endsection
