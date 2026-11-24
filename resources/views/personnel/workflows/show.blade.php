@extends('layouts.app')
@section('title',$kind==='leave'?'پیگیری مرخصی':'جزئیات گزارش کار')
@include('personnel.workflows.assets')
@section('content')
<div class="hr hr-form-page hr-kind-{{ $kind }}" data-hr-workspace><header class="hr-heading"><div><small>پرسنل و همکاری</small><h1>{{ $kind==='leave'?'پیگیری مرخصی':'جزئیات گزارش کار' }}</h1></div><a class="hr-button hr-secondary" href="{{ route('personnel.'.($kind==='leave'?'leaves':'reports').'.index') }}">بازگشت به سوابق</a></header><div class="hr-card">@include('personnel.workflows.detail')</div></div>
@endsection
