@extends('layouts.app')

@php
    use Morilog\Jalali\Jalalian;
    $audit = app(\App\Services\WarehouseReviewAuditService::class);
    $beforeSnapshot = $order->warehouseReviewSnapshots->where('type', \App\Models\WarehouseReviewSnapshot::TYPE_BEFORE)->sortByDesc('id')->first();
    $afterSnapshot = $order->warehouseReviewSnapshots->where('type', \App\Models\WarehouseReviewSnapshot::TYPE_AFTER)->sortByDesc('id')->first();
@endphp

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0">پرونده بازبینی انبار پیش‌فاکتور {{ $order->uuid }}</h4>
        <div class="d-flex gap-2"><a href="{{ route('warehouse.reviews.print', $order->uuid) }}" target="_blank" class="btn btn-outline-dark">چاپ گزارش بررسی انبار</a><a href="{{ route('warehouse.reviews.index') }}" class="btn btn-outline-secondary">بازگشت</a></div>
    </div>

    @unless($hasHistoricalSnapshot)
        <div class="alert alert-warning">برای این پیش‌فاکتور snapshot تاریخی ثبت نشده است؛ اطلاعات فعلی نمایش داده می‌شود.</div>
    @endunless

    <div class="card border-0 shadow-sm mb-3"><div class="card-body row g-3">
        <div class="col-md-3"><div class="text-muted small">شماره پیش‌فاکتور</div><strong>{{ $order->uuid }}</strong></div>
        <div class="col-md-3"><div class="text-muted small">مشتری</div><strong>{{ $order->customer_name ?: '—' }}</strong></div>
        <div class="col-md-3"><div class="text-muted small">فروشنده/ثبت‌کننده</div><strong>{{ $order->creator?->name ?? '—' }}</strong></div>
        <div class="col-md-3"><div class="text-muted small">وضعیت فعلی</div><span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">{{ $order->status_label }}</span></div>
        <div class="col-md-3"><div class="text-muted small">زمان ثبت</div>{{ $order->created_at ? Jalalian::fromDateTime($order->created_at)->format('Y/m/d H:i') : '—' }}</div>
        <div class="col-md-3"><div class="text-muted small">ورود به صف انبار</div>{{ $beforeSnapshot?->created_at ? Jalalian::fromDateTime($beforeSnapshot->created_at)->format('Y/m/d H:i') : '—' }}</div>
        <div class="col-md-3"><div class="text-muted small">آخرین انباردار</div>{{ $order->warehouseReviewer?->name ?? '—' }}</div>
        <div class="col-md-3"><div class="text-muted small">مبلغ اولیه / نهایی</div>{{ number_format((int)($beforeSnapshot?->payload['total_price'] ?? $order->total_price)) }} / {{ number_format((int)($afterSnapshot?->payload['total_price'] ?? $order->total_price)) }}</div>
    </div></div>

    <div class="card border-0 shadow-sm mb-3"><div class="card-header bg-white fw-bold">مقایسه اقلام قبل و بعد</div><div class="table-responsive"><table class="table table-striped align-middle mb-0"><thead><tr><th>کالا</th><th>تنوع</th><th>کد / بارکد</th><th>تعداد ثبت‌شده</th><th>تعداد تأییدشده</th><th>تغییر</th><th>قیمت واحد</th><th>مبلغ اولیه</th><th>مبلغ نهایی</th><th>وضعیت آیتم</th><th>دلیل تغییر</th></tr></thead><tbody>@foreach($comparisonRows as $row)<tr><td>{{ $row['product_name'] }}</td><td>{{ $row['variant_name'] }}</td><td dir="ltr">{{ $row['code'] }}</td><td>{{ number_format($row['old_quantity']) }}</td><td>{{ number_format($row['new_quantity']) }}</td><td>{{ $row['change_text'] }}</td><td>{{ number_format($row['new_price']) }}</td><td>{{ number_format($row['old_total']) }}</td><td>{{ number_format($row['new_total']) }}</td><td>{{ $row['item_status'] }}</td><td>{{ $row['reason'] ?: '—' }}@if($row['note'])<div class="small text-muted">{{ $row['note'] }}</div>@endif</td></tr>@endforeach</tbody></table></div></div>

    <div class="card border-0 shadow-sm mb-3"><div class="card-header bg-white fw-bold">Timeline فعالیت‌ها</div><div class="card-body">@forelse($timeline as $log)<div class="border-bottom pb-2 mb-2"><div class="small text-muted">{{ $log->created_at ? Jalalian::fromDateTime($log->created_at)->format('Y/m/d H:i') : '—' }} - {{ $log->user?->name ?? 'سیستم' }}</div><div>{{ $audit->timelineText($log) }}</div></div>@empty<div class="text-muted">رویدادی ثبت نشده است.</div>@endforelse</div></div>

    <div class="card border-0 shadow-sm"><div class="card-header bg-white fw-bold">یادداشت‌ها و دلایل</div><div class="card-body"><div><strong>یادداشت کلی انباردار:</strong> {{ $order->warehouse_review_note ?: '—' }}</div><div><strong>دلیل رد:</strong> {{ $order->warehouse_reject_reason ?: '—' }}</div>@foreach($order->warehouseReviewItemLogs as $itemLog)<div class="mt-2 border rounded p-2"><strong>{{ $itemLog->product_name_snapshot }}</strong> - {{ $audit->reasonLabel($itemLog->reason) ?? $itemLog->reason ?? '—' }} @if($itemLog->note)<div class="small text-muted">{{ $itemLog->note }}</div>@endif</div>@endforeach</div></div>
</div>
@endsection
