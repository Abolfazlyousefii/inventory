@extends('layouts.app')
@section('title','مدیریت پرسنل')
@push('styles')<link rel="stylesheet" href="{{ asset('css/personnel-workspace.css') }}?v={{ filemtime(public_path('css/personnel-workspace.css')) }}">@endpush
@push('scripts')<script defer src="{{ asset('js/personnel-workspace.js') }}?v={{ filemtime(public_path('js/personnel-workspace.js')) }}"></script>@endpush
@section('content')
<div class="pw" data-personnel-workspace>
<header class="pw-heading"><div><span class="pw-eyebrow">پرسنل و همکاری</span><h1>مدیریت پرسنل</h1><p>ساختار سازمانی، مشخصات، نقش و دسترسی؛ در یکجا</p></div><span class="pw-count" data-staff-count>{{ count($rows) }} نفر در ساختار</span></header>
<form class="pw-toolbar" id="staffFilters" action="{{ route('users.index') }}" method="get">
<label class="pw-search"><span class="visually-hidden">جستجوی پرسنل</span><input type="search" name="q" placeholder="نام، موبایل، واحد یا کد پرسنلی…" value="{{ request('q') }}" maxlength="100"></label>
<label><span class="visually-hidden">نیروهای مدیر</span><select name="manager"><option value="">همه مدیران</option>@foreach($managers as $manager)<option value="{{ $manager->id }}" @selected((string)request('manager')===(string)$manager->id)>{{ $manager->name }}</option>@endforeach</select></label>
<label class="pw-check"><input type="checkbox" name="unassigned" value="1" @checked(request('unassigned'))>بدون مدیر</label>
<label class="pw-check pw-inactive-filter"><input type="checkbox" name="show_inactive" value="1" @checked(request()->boolean('show_inactive') || request('status')==='inactive')>نمایش غیرفعال‌ها</label>
<button class="pw-button" type="submit">جستجو</button><button class="pw-button pw-quiet" type="button" data-reset-filters>پاک‌کردن</button>
</form>
<div class="pw-tree-tools"><span>ساختار براساس مدیر مستقیم ثبت‌شده است.</span><div><button type="button" data-expand-all>بازکردن همه</button><button type="button" data-collapse-all>جمع‌کردن همه</button></div></div>
<div id="staffFeedback" role="status" aria-live="polite"></div>
@include('users.partials.tree')
<dialog id="staffDialog" aria-labelledby="staffDialogTitle"><header class="pw-dialog-head"><h2 id="staffDialogTitle">ویرایش پرسنل</h2><button type="button" data-close-editor aria-label="بستن ویرایش پرسنل">×</button></header><div id="staffEditorHost"></div></dialog>
</div>
@endsection
