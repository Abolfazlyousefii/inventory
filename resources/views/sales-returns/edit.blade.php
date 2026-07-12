@extends('layouts.app')
@section('content')
<div class="container-fluid" dir="rtl">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div><h3 class="mb-1">ادامه ویرایش {{ $document->document_number }}</h3><div class="text-muted small">Draft قابل ویرایش است و هنوز هیچ اثر مالی یا انباری ندارد.</div></div>
        <form method="POST" action="{{ route('sales-returns.cancel',$document) }}" onsubmit="return confirm('سند لغو شود؟')">@csrf<input type="hidden" name="cancel_reason" value="لغو توسط کاربر"><button class="btn btn-outline-danger btn-sm">لغو پیش‌نویس</button></form>
    </div>
    @include('sales-returns.partials.form')
</div>
@endsection
