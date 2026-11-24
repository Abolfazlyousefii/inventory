@extends('layouts.app')
@section('title','مدیریت پرسنل')
@push('styles')<link rel="stylesheet" href="{{ asset('css/personnel-workspace.css') }}?v={{ filemtime(public_path('css/personnel-workspace.css')) }}">@endpush
@push('scripts')<script defer src="{{ asset('js/personnel-workspace.js') }}?v={{ filemtime(public_path('js/personnel-workspace.js')) }}"></script>@endpush
@section('content')
<div class="pw" data-personnel-workspace>
<header class="pw-heading"><div><span class="pw-eyebrow">پرسنل و همکاری / مدیریت پرسنل</span><h1>تیم ما، در یک نگاه</h1><p>افراد، جایگاه سازمانی و دسترسی‌ها؛ کنار هم و مرتب</p></div>@if($canManage)<button type="button" class="pw-button" data-create-staff>＋ حساب جدید</button>@endif<span class="pw-count" data-staff-count>{{ $directoryTab === 'accounts' ? $accounts->count().' حساب' : count($rows).' نفر در ساختار' }}</span></header>
<nav class="pw-directory-tabs" aria-label="نوع حساب">
<button type="button" data-directory-tab="organization" aria-pressed="{{ $directoryTab === 'organization' ? 'true' : 'false' }}">چارت سازمانی <span data-chart-count>{{ $chartCount }}</span></button>
<button type="button" data-directory-tab="accounts" aria-pressed="{{ $directoryTab === 'accounts' ? 'true' : 'false' }}">حساب‌های مدیریتی <span data-account-count>{{ $accountCount }}</span></button>
</nav>
<form class="pw-toolbar" id="staffFilters" action="{{ route('users.index') }}" method="get">
<input type="hidden" name="directory_tab" value="{{ $directoryTab }}">
<label class="pw-search"><span class="visually-hidden">جستجوی پرسنل</span><input type="search" name="q" placeholder="نام، موبایل، واحد یا کد پرسنلی…" value="{{ request('q') }}" maxlength="100"></label>
<label data-organization-filter @if($directoryTab === 'accounts') hidden @endif><span class="visually-hidden">نیروهای مدیر</span><select name="manager"><option value="">همه مدیران</option>@foreach($managers as $manager)<option value="{{ $manager->id }}" @selected((string)request('manager')===(string)$manager->id)>{{ $manager->name }}</option>@endforeach</select></label>
<label class="pw-check" data-organization-filter @if($directoryTab === 'accounts') hidden @endif><input type="checkbox" name="unassigned" value="1" @checked(request('unassigned'))>بدون مدیر</label>
<label class="pw-check pw-inactive-filter"><input type="checkbox" name="show_inactive" value="1" @checked(request()->boolean('show_inactive') || request('status')==='inactive')>نمایش غیرفعال‌ها</label>
<button class="pw-button" type="submit">جستجو</button><button class="pw-button pw-quiet" type="button" data-reset-filters>پاک‌کردن</button>
</form>
<div class="pw-tree-tools" data-organization-tools @if($directoryTab === 'accounts') hidden @endif><div class="pw-legend"><span class="pw-legend-manager">مدیریت</span><span class="pw-legend-supervisor">سرپرست</span><span class="pw-legend-staff">پرسنل</span></div><div><button type="button" data-expand-all>بازکردن شاخه‌ها</button><button type="button" data-collapse-all>جمع‌کردن شاخه‌ها</button></div></div>
<div id="staffFeedback" role="status" aria-live="polite"></div>
@include('users.partials.tree')
<p class="pw-directory-note" data-organization-tools @if($directoryTab === 'accounts') hidden @endif>مدیر مستقیم هر فرد با خط مشخص شده است. حساب‌های مدیریتی خارج از چارت‌اند؛ مسیر تأیید مرخصی و گزارش کار حفظ می‌شود.</p>
<dialog id="staffDialog" aria-labelledby="staffDialogTitle"><header class="pw-dialog-head"><h2 id="staffDialogTitle">ویرایش پرسنل</h2><button type="button" data-close-editor aria-label="بستن ویرایش پرسنل">×</button></header><div id="staffEditorHost"></div></dialog>
@if($canManage)@include('users.partials.create-account')@endif
</div>
@endsection
