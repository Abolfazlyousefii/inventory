@extends('layouts.app')
@section('content')
<div class="container-fluid" dir="rtl">
    <div class="mb-3"><h3 class="mb-1">ثبت برگشت از فروش</h3><div class="text-muted small">انتخاب نوع برگشت، مشتری، فاکتور و اقلام برگشتی</div></div>
    @include('sales-returns.partials.form')
</div>
@endsection
