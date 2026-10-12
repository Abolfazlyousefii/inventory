@extends('layouts.app')
@section('title', 'تنظیمات پورسانت اداری')
@section('page-title', 'تنظیمات پورسانت اداری')
@push('styles')<link rel="stylesheet" href="{{ asset('css/pages/org-commission.css') }}?v={{ filemtime(public_path('css/pages/org-commission.css')) }}">@endpush
@section('content')
<div class="org-rates-page">
    <header class="org-rates-header"><div><span>پورسانت اداری</span><h1>درصد ثابت افراد</h1><p>سهم هر شخص از کل پورسانت خالص فروشندگان محاسبه می‌شود.</p></div><a class="btn btn-outline-secondary" href="{{ route('finance.org-commission.index') }}">بازگشت</a></header>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<p class="mb-1">{{ $error }}</p>@endforeach</div>@endif
    <div class="org-rates-note">تغییر درصد فقط روی سندهای جدید اثر دارد. درصد و مبلغ سندهای قبلی حفظ می‌شود. برای عضو بدون سهم، صفر وارد کنید.</div>
    <form method="POST" action="{{ route('finance.org-commission.settings.update') }}">
        @csrf @method('PUT')
        <input type="hidden" name="settings_version" value="{{ $snapshot['version'] }}">
        <div class="org-rates-grid">
        @foreach($snapshot['departments'] as $department)
            <section class="org-rates-card"><h2>{{ $department['name'] }}</h2>
                @forelse($department['members'] as $member)
                    <div class="org-rate-person"><label for="rate{{ $member['id'] }}"><strong>{{ $member['name'] }}</strong><small>{{ $member['role'] ?: 'سهم از کل پورسانت' }}</small></label><div class="org-rate-input"><input id="rate{{ $member['id'] }}" name="rates[{{ $member['id'] }}]" type="text" inputmode="decimal" dir="ltr" maxlength="10" value="{{ old('rates.'.$member['id'], $member['percentage'] !== null ? rtrim(rtrim($member['percentage'], '0'), '.') : '') }}" required aria-label="درصد {{ $member['name'] }}"><span>٪</span></div></div>
                @empty<p>عضو فعالی در این واحد نیست.</p>@endforelse
            </section>
        @endforeach
        </div>
        <footer class="org-rates-footer"><span>مجموع درصد افراد فعال باید حداکثر ۱۰۰٪ باشد.</span><button class="btn btn-success" type="submit">ذخیره درصدها</button></footer>
    </form>
</div>
@endsection
