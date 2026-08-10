@extends('layouts.app')

@section('title', 'صف ارسال بار')

@section('content')
@php
    $rial = fn($amount) => number_format((int) $amount) . ' ریال';
    $date = function ($value) {
        if (! $value) return '—';
        try { return \Morilog\Jalali\Jalalian::fromDateTime($value)->format('Y/m/d H:i'); } catch (\Throwable) { return (string) $value; }
    };
@endphp
<style>
.shipping-page{max-width:100%;overflow-x:hidden}.shipping-hero{background:linear-gradient(135deg,#eff6ff,#fff);border:1px solid #dbeafe;border-radius:22px;padding:22px;box-shadow:0 14px 36px rgba(37,99,235,.08)}.shipping-stat{border:1px solid #dbeafe;border-radius:18px;background:#fff;padding:16px}.shipping-table-card{border:1px solid #e5e7eb;border-radius:20px;overflow:hidden;background:#fff}.shipping-table{margin:0;table-layout:fixed}.shipping-table th{background:#f8fafc;color:#475569;font-size:.82rem}.shipping-table td{vertical-align:middle}.shipping-actions{display:flex;flex-wrap:wrap;gap:.35rem}.shipping-mobile-card{border:1px solid #dbeafe;border-radius:18px;background:#fff;box-shadow:0 8px 22px rgba(15,23,42,.05)}@media(max-width:767.98px){.shipping-desktop{display:none!important}.shipping-mobile{display:block!important}}@media(min-width:768px){.shipping-mobile{display:none!important}}
</style>
<div class="container-fluid py-4 shipping-page">
    <div class="shipping-hero mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
            <div>
                <h1 class="h4 fw-bold text-primary mb-2">صف ارسال بار</h1>
                <p class="text-muted mb-0">فاکتورهای آماده ارسال که باید روش ارسال، هزینه و توضیحات ارسال برای آن‌ها ثبت شود.</p>
            </div>
            <a class="btn btn-outline-primary align-self-start" href="{{ route('vouchers.sales.queue') }}">بازگشت به صف جمع‌آوری</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="shipping-stat"><div class="text-muted small">در انتظار ارسال</div><div class="fs-4 fw-bold text-primary">{{ number_format($summary['ready_count']) }}</div></div></div>
        <div class="col-md-4"><div class="shipping-stat"><div class="text-muted small">ارسال‌شده امروز</div><div class="fs-4 fw-bold text-success">{{ number_format($summary['shipped_today']) }}</div></div></div>
        <div class="col-md-4"><div class="shipping-stat"><div class="text-muted small">مجموع مبلغ آماده ارسال</div><div class="fs-5 fw-bold">{{ $rial($summary['ready_total']) }}</div></div></div>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="shipping-table-card shipping-desktop">
        <table class="table shipping-table align-middle">
            <thead><tr><th>فاکتور</th><th>مشتری</th><th>خلاصه</th><th>فروشنده / اپراتور</th><th>عملیات</th></tr></thead>
            <tbody>
            @forelse($invoices as $invoice)
                <tr>
                    <td><div class="fw-bold" dir="ltr">{{ $invoice->uuid }}</div><div class="small text-muted">{{ $date($invoice->status_changed_at ?? $invoice->updated_at) }}</div></td>
                    <td><div class="fw-semibold">{{ $invoice->customer_name ?: '—' }}</div><div class="small text-muted" dir="ltr">{{ $invoice->customer_mobile ?: '—' }}</div></td>
                    <td><div>{{ number_format((int) $invoice->items->sum('quantity')) }} قلم</div><div class="small fw-semibold">{{ $rial($invoice->total) }}</div></td>
                    <td>{{ $invoice->preinvoiceOrder?->creator?->name ?: '—' }}</td>
                    <td><div class="shipping-actions"><a class="btn btn-sm btn-outline-secondary" href="{{ route('vouchers.sales.show', $invoice->uuid) }}">مشاهده</a><a class="btn btn-sm btn-outline-dark" target="_blank" href="{{ route('vouchers.sales.print', $invoice->uuid) }}">چاپ</a><button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#shipModal{{ $invoice->id }}">ثبت ارسال</button></div></td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-5">هیچ فاکتور آماده ارسالی وجود ندارد.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="shipping-mobile">
        @forelse($invoices as $invoice)
            <div class="shipping-mobile-card p-3 mb-3">
                <div class="d-flex justify-content-between gap-2"><strong dir="ltr">{{ $invoice->uuid }}</strong><span class="badge text-bg-primary">آماده ارسال</span></div>
                <div class="text-muted small mt-1">{{ $date($invoice->status_changed_at ?? $invoice->updated_at) }}</div>
                <hr><div class="fw-semibold">{{ $invoice->customer_name ?: '—' }}</div><div class="small text-muted" dir="ltr">{{ $invoice->customer_mobile ?: '—' }}</div>
                <div class="mt-2 small">{{ number_format((int) $invoice->items->sum('quantity')) }} قلم | {{ $rial($invoice->total) }}</div>
                <div class="mt-3 shipping-actions"><a class="btn btn-sm btn-outline-secondary" href="{{ route('vouchers.sales.show', $invoice->uuid) }}">مشاهده</a><a class="btn btn-sm btn-outline-dark" target="_blank" href="{{ route('vouchers.sales.print', $invoice->uuid) }}">چاپ</a><button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#shipModal{{ $invoice->id }}">ثبت ارسال</button></div>
            </div>
        @empty
            <div class="alert alert-info">هیچ فاکتور آماده ارسالی وجود ندارد.</div>
        @endforelse
    </div>

    <div class="mt-3">{{ $invoices->links() }}</div>

    @foreach($invoices as $invoice)
        <div class="modal fade" id="shipModal{{ $invoice->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                <form method="POST" action="{{ route('warehouse.shipping.ship', $invoice->uuid) }}" class="shipping-ship-form">@csrf
                    <div class="modal-header"><h5 class="modal-title">ثبت ارسال فاکتور {{ $invoice->uuid }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="alert alert-info small">هزینه ارسال در این مرحله فقط روی فاکتور ثبت می‌شود و فعلاً وارد حساب مشتری نمی‌شود.</div>
                        <div class="mb-3"><label class="form-label">روش ارسال</label><select name="shipping_method_id" class="form-select js-shipping-method" required><option value="">انتخاب روش ارسال...</option>@foreach($shippingMethods as $method)<option value="{{ $method->id }}" data-price="{{ (int) $method->price }}">{{ $method->name }} @if((int)$method->price > 0)- {{ $rial($method->price) }}@endif</option>@endforeach</select></div>
                        <div class="mb-3"><label class="form-label">هزینه ارسال</label><input name="shipping_cost" class="form-control js-shipping-cost" inputmode="numeric" value="0" placeholder="0"></div>
                        <div class="mb-0"><label class="form-label">توضیحات ارسال</label><textarea name="shipping_note" class="form-control" rows="4" maxlength="2000" placeholder="توضیحات اختیاری ارسال..."></textarea></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button><button class="btn btn-primary">ثبت ارسال</button></div>
                </form>
            </div></div>
        </div>
    @endforeach
</div>
<script>
document.querySelectorAll('.js-shipping-method').forEach((select)=>{select.addEventListener('change',()=>{const price=select.options[select.selectedIndex]?.dataset.price||'0';const input=select.closest('form')?.querySelector('.js-shipping-cost');if(input){input.value=Number(price||0).toLocaleString('en-US');}});});
document.querySelectorAll('.js-shipping-cost').forEach((input)=>{input.addEventListener('input',()=>{const raw=input.value.replace(/[۰-۹٠-٩]/g,(d)=>'۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩'.indexOf(d)%10).replace(/[^0-9]/g,'');input.value=raw ? Number(raw).toLocaleString('en-US') : '';});});
</script>
@endsection
