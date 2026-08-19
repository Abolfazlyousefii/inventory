@extends('layouts.app')
@section('title', 'رسید ورودی '.$receipt->receipt_number)
@section('page-title', 'رسید ورودی '.$receipt->receipt_number)
@push('styles')<link rel="stylesheet" href="{{ asset('css/warehouse-inbound-queue.css') }}">@endpush
@section('content')
<div class="wiq wiq-standalone" dir="rtl">
    <div class="wiq-table-card">@include('warehouse.inbound-queue.partials.receipt')</div>
</div>
@endsection
