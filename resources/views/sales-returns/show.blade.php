@extends('layouts.app')
@section('content')
<div class="container-fluid" dir="rtl">
    @include('sales-returns.partials.flash')
    <div class="d-flex justify-content-between mb-3"><h3>نمایش سند {{ $document->document_number }}</h3><a class="btn btn-outline-secondary" href="{{ route('sales-returns.index') }}">بازگشت</a></div>
    @if($document->isDraft())<div class="alert alert-warning">این سند هنوز پیش‌نویس است و هیچ اثر مالی یا انباری ندارد.</div>@endif
    <div class="card mb-3"><div class="card-body row g-2"><div class="col-md-3"><strong>نوع:</strong> {{ $document->sourceTypeLabel() }}</div><div class="col-md-3"><strong>وضعیت:</strong> {{ $document->statusLabel() }}</div><div class="col-md-3"><strong>مشتری:</strong> {{ $document->customer?->display_name }}</div><div class="col-md-3"><strong>فاکتور:</strong> {{ $document->invoice?->uuid ?: $document->external_invoice_number ?: '—' }}</div><div class="col-md-3"><strong>جمع:</strong> {{ number_format($document->refund_total) }}</div><div class="col-md-9"><strong>توضیح:</strong> {{ $document->description ?: '—' }}</div></div></div>
    <div class="card"><div class="table-responsive"><table class="table table-bordered mb-0"><thead><tr><th>#</th><th>کالا</th><th>تنوع/SKU</th><th>وضعیت</th><th>مقصد</th><th>فروخته</th><th>قبلاً برگشتی</th><th>تعداد</th><th>مبلغ</th></tr></thead><tbody>@foreach($document->items as $item)<tr><td>{{ $loop->iteration }}</td><td>{{ $item->product_name_snapshot ?: $item->product?->name }}</td><td>{{ $item->variant_name_snapshot ?: $item->sku_snapshot }}</td><td>{{ $item->item_condition === 'damaged' ? 'معیوب' : 'سالم' }}</td><td>{{ $item->destinationWarehouse?->name }}</td><td>{{ $item->sold_quantity_snapshot ?: '—' }}</td><td>{{ $item->previous_returned_quantity_snapshot }}</td><td>{{ $item->return_quantity }}</td><td>{{ number_format($item->refund_amount) }}</td></tr>@endforeach</tbody></table></div></div>
</div>
@endsection
