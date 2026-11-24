@extends('layouts.app')
@section('title',$kind==='leave'?'درخواست مرخصی':'ثبت گزارش کار')
@include('personnel.workflows.assets')
@section('content')
@php($prefix='personnel.'.($kind==='leave'?'leaves':'reports'))
<div class="hr hr-form-page hr-kind-{{ $kind }}" data-hr-workspace>
<header class="hr-heading"><div><small>پرسنل و همکاری</small><h1>{{ $kind==='leave'?'درخواست مرخصی':($entry?'ویرایش گزارش کار':'ثبت گزارش کار') }}</h1><p>{{ $kind==='leave'?'اطلاعات مرخصی را وارد کنید؛ مسیر تأیید به‌صورت خودکار مشخص می‌شود.':'فعالیت‌های خود را بنویسید و برای مسئول مربوطه بفرستید.' }}</p></div><a class="hr-button hr-secondary" href="{{ route($prefix.'.index') }}">بازگشت به سوابق</a></header>
@include('personnel.workflows.entry-form')
@include('personnel.workflows.calendar')
</div>
@endsection
