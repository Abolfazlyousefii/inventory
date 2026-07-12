@extends('layouts.app')
@section('content')
<div class="container-fluid" dir="rtl"><div class="d-flex justify-content-between mb-3"><h3>ویرایش پیش‌نویس {{ $document->document_number }}</h3><form method="POST" action="{{ route('sales-returns.cancel',$document) }}" onsubmit="return confirm('سند لغو شود؟')">@csrf<input type="hidden" name="cancel_reason" value="لغو توسط کاربر"><button class="btn btn-outline-danger">لغو پیش‌نویس</button></form></div>@include('sales-returns.partials.form')</div>
@endsection
