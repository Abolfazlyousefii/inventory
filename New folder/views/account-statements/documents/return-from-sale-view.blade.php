@extends('layouts.app')

@php
    use Morilog\Jalali\Jalalian;

    $customerFullName = trim(implode(' ', array_filter([
        $voucher->customer?->first_name,
        $voucher->customer?->last_name,
    ])));
    $returnerName = $customerFullName !== ''
        ? $customerFullName
        : ($voucher->beneficiary_name ?: ($voucher->customer?->display_name ?: '—'));

    $variantLabel = function ($item): string {
        $variant = $item->variant;
        $parts = collect([
            $variant?->variant_name,
            $variant?->modelList?->model_name,
            $variant?->color?->name,
            $variant?->variety_name,
            $item->variant_name,
        ])->filter(fn ($value) => filled($value) && $value !== '—')->unique()->values();

        return $parts->isNotEmpty() ? $parts->implode(' / ') : '—';
    };

    $variantCode = fn ($item): string => $item->variant?->variant_code
        ?: ($item->variant_code ?: '—');
    $returnReasonLabel = \App\Models\WarehouseTransfer::returnReasonOptions()[$voucher->return_reason] ?? '—';
    $healthyAmount = (int) $voucher->items->filter(fn ($item) => $item->effectiveReturnKind() === 'healthy')->sum('line_total');
    $damagedAmount = (int) $voucher->items->filter(fn ($item) => $item->effectiveReturnKind() === 'damaged')->sum('line_total');
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">↩️ نمایش فاکتور برگشت از فروش</h4>
        <div class="text-muted small">این صفحه فقط برای مشاهده است و امکان ویرایش ندارد.</div>
    </div>
    <a href="{{ url()->previous() }}" class="btn btn-outline-secondary">بازگشت</a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4"><strong>شماره سند:</strong> {{ $voucher->reference ?: ('TR-' . $voucher->id) }}</div>
            <div class="col-md-4"><strong>تاریخ:</strong> {{ $voucher->transferred_at ? Jalalian::fromDateTime($voucher->transferred_at)->format('Y/m/d H:i') : '—' }}</div>
            <div class="col-md-4"><strong>مبلغ برگشتی مشتری:</strong> {{ \App\Support\Currency::formatRial($voucher->total_amount) }}</div>
            <div class="col-md-4"><strong>نام و نام خانوادگی برگشت‌دهنده:</strong> {{ $returnerName }}</div>
            <div class="col-md-4"><strong>موبایل برگشت‌دهنده:</strong> {{ $voucher->customer?->mobile ?: '—' }}</div>
            <div class="col-md-4"><strong>نوع برگشت:</strong> {{ \App\Models\WarehouseTransfer::returnSourceLabel($voucher->return_type ?? null) }}</div>
            <div class="col-md-4"><strong>فاکتور مرجع:</strong> {{ $voucher->relatedInvoice?->uuid ?: '—' }}</div>
            <div class="col-md-4"><strong>شماره فاکتور سازه‌حساب:</strong> {{ $voucher->external_invoice_number ?: '—' }}</div>
            <div class="col-md-4"><strong>علت برگشت:</strong> {{ \App\Models\WarehouseTransfer::returnReasonOptions()[$voucher->return_reason] ?? '—' }}</div>
            <div class="col-md-6"><strong>انبار مبدا:</strong> {{ $voucher->fromWarehouse?->name ?: '—' }}</div>
            <div class="col-md-6"><strong>انبار مقصد:</strong> {{ $voucher->items->pluck('destinationWarehouse.name')->filter()->unique()->implode('، ') ?: ($voucher->toWarehouse?->name ?: 'نامشخص') }}</div>
            <div class="col-md-4"><strong>تعداد اقلام سالم:</strong> {{ number_format($voucher->items->filter(fn ($item) => $item->effectiveReturnKind() === 'healthy')->sum('quantity')) }}</div>
            <div class="col-md-4"><strong>مبلغ اقلام سالم:</strong> {{ \App\Support\Currency::formatRial($healthyAmount) }}</div>
            <div class="col-md-4"><strong>مبلغ اقلام مرجوعی:</strong> {{ \App\Support\Currency::formatRial($damagedAmount) }}</div>
            <div class="col-md-4"><strong>ثبت‌کننده سند:</strong> {{ $voucher->user?->name ?: '—' }}</div>
            <div class="col-md-12"><strong>توضیحات:</strong> {{ $voucher->note ?: '—' }}</div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">اقلام برگشتی</div>
    <div class="table-responsive">
        <table class="table table-striped align-middle mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>کالا</th>
                    <th>نوع / تنوع دقیق کالا</th>
                    <th>کد تنوع</th>
                    <th>تعداد برگشتی</th>
                    <th>واحد</th>
                    <th>علت برگشت</th>
                    <th>نوع برگشت</th>
                    <th>انبار مقصد</th>
                    <th>قیمت واحد</th>
                    <th>جمع</th>
                </tr>
            </thead>
            <tbody>
            @forelse($voucher->items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->product?->name ?? ('#' . $item->product_id) }}</td>
                    <td>{{ $variantLabel($item) }}</td>
                    <td dir="ltr">{{ $variantCode($item) }}</td>
                    <td>{{ number_format((int) $item->quantity) }} {{ $item->product?->unit ?: 'عدد' }}</td>
                    <td>{{ $item->product?->unit ?: 'عدد' }}</td>
                    <td>{{ $returnReasonLabel }}</td>
                    <td>{{ $item->returnKindLabel() }}</td>
                    <td>{{ $item->destinationWarehouse?->name ?: 'نامشخص' }}</td>
                    <td>{{ number_format((int) $item->unit_price) }}</td>
                    <td>{{ number_format((int) $item->line_total) }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="text-center text-muted py-4">آیتمی ثبت نشده است.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
