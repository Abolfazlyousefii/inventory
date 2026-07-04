@extends('layouts.app')

@php
  $formatWarehouseLocation = function ($item): string {
      $variant = $item->variant;
      if (! $variant) {
          return '—';
      }

      $warehouseId = (int) ($item->transfer?->from_warehouse_id ?? 0);
      $stocks = $variant->locationStocks()
          ->with('location')
          ->when($warehouseId > 0, fn ($q) => $q->where('warehouse_id', $warehouseId))
          ->where('quantity', '>', 0)
          ->get();

      if ($stocks->isEmpty()) {
          return 'بدون نقشه';
      }

      return $stocks->map(fn ($stock) => ($stock->location?->code ?? '—') . ' (' . number_format((int) $stock->quantity) . ')')->implode(' / ');
  };
@endphp

@section('content')
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">جزئیات حواله انبار</h4>
    <div class="d-flex gap-2">
      <button type="button" class="btn btn-dark" onclick="window.print()">چاپ</button>
      <a href="{{ route('vouchers.index') }}" class="btn btn-outline-secondary">بازگشت</a>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body row g-3">
      <div class="col-md-4"><strong>شماره حواله:</strong> {{ $voucher->reference ?: ('TR-' . $voucher->id) }}</div>
      <div class="col-md-4"><strong>تاریخ:</strong> {{ optional($voucher->transferred_at)->format('Y/m/d H:i') }}</div>
      <div class="col-md-4"><strong>نوع/علت:</strong> {{ $reasonLabel }}</div>
      <div class="col-md-4"><strong>ورودی/خروجی:</strong> {{ $directionLabel }}</div>
      <div class="col-md-4"><strong>انبار مبدا:</strong> {{ $voucher->fromWarehouse?->name ?? '—' }}</div>
      <div class="col-md-4"><strong>انبار مقصد:</strong> {{ $voucher->toWarehouse?->name ?? '—' }}</div>
      <div class="col-md-4"><strong>ثبت‌کننده:</strong> {{ $voucher->user?->name ?? '—' }}</div>
      <div class="col-md-6"><strong>فاکتور مرجع:</strong> {{ $voucher->relatedInvoice?->uuid ?? '—' }}</div>
      <div class="col-md-6"><strong>تحویل‌گیرنده/ذی‌نفع:</strong> {{ $voucher->receiverDisplayName() }}</div>
      <div class="col-12"><strong>توضیحات:</strong> {{ $voucher->note ?: '—' }}</div>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white">اقلام حواله</div>
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>نام کالا</th>
            <th>کد محصول</th>
            <th>موقعیت Z/R/B</th>
            <th>تنوع/مدل/سریال</th>
            <th>تعداد</th>
            <th>واحد</th>
            <th>توضیحات ردیف</th>
          </tr>
        </thead>
        <tbody>
          @forelse($voucher->items as $item)
            @php
              $productCode = $item->product?->code
                  ?: ($item->variant_code
                      ?: ($item->variant?->variant_code
                          ?: ($item->product?->sku ?: '—')));
              $warehouseLocation = $formatWarehouseLocation($item);
            @endphp
            <tr>
              <td>{{ $loop->iteration }}</td>
              <td>{{ $item->product?->name ?? '—' }}</td>
              <td dir="ltr" class="text-nowrap">{{ $productCode }}</td>
              <td dir="ltr" class="text-nowrap">{{ $warehouseLocation }}</td>
              <td>{{ $item->variant_name ?: ($item->variant?->variant_name ?? '—') }}{{ $item->personnel_asset_code ? ' | کد اموال: '.$item->personnel_asset_code : '' }}</td>
              <td>{{ number_format((int) $item->quantity) }} {{ $item->product?->unit ?: 'عدد' }}</td>
              <td>{{ $item->product?->unit ?: 'عدد' }}</td>
              <td>{{ $item->line_total ? 'مبلغ ردیف: '.number_format((int)$item->line_total) : '—' }}</td>
            </tr>
          @empty
            <tr><td colspan="8" class="text-center text-muted py-4">آیتمی ثبت نشده است.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>
@if(request()->boolean('print'))
  <script>window.print();</script>
@endif
@endsection
