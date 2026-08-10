@extends('layouts.app')

@section('content')
<div class="container-fluid py-4" style="max-width:100%;overflow-x:hidden">
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h4 class="fw-bold mb-1">جستجوی سریع</h4>
                    <div class="text-muted small">نتایج جستجو برای: {{ $q ?: '—' }}</div>
                </div>
                <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary">بازگشت به داشبورد</a>
            </div>
            <form method="GET" action="{{ route('global-search') }}" class="row g-2">
                <div class="col-md-10"><input name="q" value="{{ $q }}" class="form-control form-control-lg" placeholder="جستجوی کالا، مشتری، فاکتور یا بارکد..."></div>
                <div class="col-md-2 d-grid"><button class="btn btn-primary btn-lg">جستجو</button></div>
            </form>
        </div>
    </div>

    @if($q === '')
        <div class="alert alert-info">برای شروع، عبارت جستجو را وارد کنید.</div>
    @else
        <div class="row g-3">
            <div class="col-lg-6"><div class="card rounded-4 h-100"><div class="card-body"><h6 class="fw-bold">کالاها</h6>@forelse($results['products'] as $product)<div class="border-top py-2 d-flex justify-content-between"><span>{{ $product->name }}</span><small class="text-muted">{{ $product->sku ?: $product->code ?: $product->barcode }}</small></div>@empty<div class="text-muted small">نتیجه‌ای یافت نشد.</div>@endforelse</div></div></div>
            <div class="col-lg-6"><div class="card rounded-4 h-100"><div class="card-body"><h6 class="fw-bold">تنوع‌ها / بارکدها</h6>@forelse($results['variants'] as $variant)<div class="border-top py-2 d-flex justify-content-between"><span>{{ $variant->product?->name }} {{ $variant->variant_name }}</span><small class="text-muted">{{ $variant->sku ?: $variant->barcode }}</small></div>@empty<div class="text-muted small">نتیجه‌ای یافت نشد.</div>@endforelse</div></div></div>
            <div class="col-lg-6"><div class="card rounded-4 h-100"><div class="card-body"><h6 class="fw-bold">فاکتورها</h6>@forelse($results['invoices'] as $invoice)<a class="border-top py-2 d-flex justify-content-between text-decoration-none" href="{{ route('invoices.show', $invoice->uuid) }}"><span>{{ $invoice->uuid }}</span><small class="text-muted">{{ $invoice->customer_name }}</small></a>@empty<div class="text-muted small">نتیجه‌ای یافت نشد.</div>@endforelse</div></div></div>
            <div class="col-lg-6"><div class="card rounded-4 h-100"><div class="card-body"><h6 class="fw-bold">پیش‌فاکتورها</h6>@forelse($results['preinvoices'] as $preinvoice)<a class="border-top py-2 d-flex justify-content-between text-decoration-none" href="{{ route('preinvoice.my.show', $preinvoice->uuid) }}"><span>{{ $preinvoice->uuid }}</span><small class="text-muted">{{ $preinvoice->customer_name }}</small></a>@empty<div class="text-muted small">نتیجه‌ای یافت نشد.</div>@endforelse</div></div></div>
            <div class="col-lg-6"><div class="card rounded-4 h-100"><div class="card-body"><h6 class="fw-bold">مشتریان</h6>@forelse($results['customers'] as $customer)<div class="border-top py-2 d-flex justify-content-between"><span>{{ $customer->display_name ?: 'بدون نام' }}</span><small class="text-muted">{{ $customer->mobile }}</small></div>@empty<div class="text-muted small">نتیجه‌ای یافت نشد.</div>@endforelse</div></div></div>
        </div>
    @endif
</div>
@endsection
