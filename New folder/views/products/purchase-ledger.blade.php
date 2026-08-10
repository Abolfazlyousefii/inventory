@extends('layouts.app')

@section('content')
@php use Morilog\Jalali\Jalalian; @endphp
<div class="container">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
        <div>
            <h4 class="mb-1">کارتکس خرید کالا</h4>
            <div class="text-muted">کالا: <strong>{{ $product->name }}</strong>@if($selectedVariant) <span class="mx-1">|</span> تنوع: <strong>{{ $selectedVariant->variant_name }}</strong> @endif</div>
        </div>
        <a class="btn btn-outline-dark" href="{{ route('products.index') }}">بازگشت به کالاها</a>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4 col-lg-2"><div class="card"><div class="card-body"><div class="small text-muted">مجموع تعداد خرید</div><div class="fw-bold">{{ number_format((int)($summary->total_quantity ?? 0)) }}</div></div></div></div>
        <div class="col-md-4 col-lg-2"><div class="card"><div class="card-body"><div class="small text-muted">مجموع مبلغ خرید</div><div class="fw-bold">{{ number_format((int)($summary->total_amount ?? 0)) }}</div></div></div></div>
        <div class="col-md-4 col-lg-2"><div class="card"><div class="card-body"><div class="small text-muted">میانگین قیمت خرید</div><div class="fw-bold">{{ number_format((int)($summary->avg_buy_price ?? 0)) }}</div></div></div></div>
        <div class="col-md-4 col-lg-2"><div class="card"><div class="card-body"><div class="small text-muted">آخرین قیمت خرید</div><div class="fw-bold">{{ number_format((int)($lastItem?->buy_price ?? 0)) }}</div></div></div></div>
        <div class="col-md-4 col-lg-2"><div class="card"><div class="card-body"><div class="small text-muted">آخرین تاریخ خرید</div><div class="fw-bold">{{ $lastItem?->purchase?->purchased_at ? Jalalian::fromDateTime($lastItem->purchase->purchased_at)->format('Y/m/d') : '—' }}</div></div></div></div>
        <div class="col-md-4 col-lg-2"><div class="card"><div class="card-body"><div class="small text-muted">آخرین تامین‌کننده</div><div class="fw-bold">{{ $lastItem?->purchase?->supplier?->name ?? '—' }}</div></div></div></div>
    </div>

    <div class="card mb-3"><div class="card-body">
        <form class="row g-3" method="GET">
            <div class="col-md-3"><label class="form-label">تاریخ از</label><input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">تاریخ تا</label><input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">تنوع</label><select name="variant_id" class="form-select"><option value="">همه</option>@foreach($variants as $variant)<option value="{{ $variant->id }}" @selected((int)request('variant_id')===$variant->id)>{{ $variant->variant_name }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">جستجو</label><input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="شماره سند/متن"></div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-primary">اعمال فیلتر</button><a href="{{ route('products.purchase-ledger', $product) }}" class="btn btn-outline-secondary">حذف فیلتر</a></div>
        </form>
    </div></div>

    <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>تاریخ</th><th>شماره سند خرید</th><th>تامین‌کننده</th><th>تنوع</th><th>انبار مقصد</th><th class="text-end">تعداد</th><th class="text-end">قیمت خرید واحد</th><th class="text-end">مبلغ کل</th><th>ثبت‌کننده</th><th>سند</th></tr></thead><tbody>
    @forelse($ledgerItems as $item)
    <tr>
        <td>{{ $item->purchase?->purchased_at ? Jalalian::fromDateTime($item->purchase->purchased_at)->format('Y/m/d H:i') : '—' }}</td>
        <td>{{ $item->purchase_id ?? '—' }}</td>
        <td>{{ $item->purchase?->supplier?->name ?? '—' }}</td>
        <td>{{ $item->variant?->variant_name ?? $item->variant_name ?? '—' }}</td>
        <td>—</td>
        <td class="text-end">{{ number_format((int)$item->quantity) }}</td>
        <td class="text-end">{{ number_format((int)$item->buy_price) }}</td>
        <td class="text-end fw-bold">{{ number_format((int)$item->line_total) }}</td>
        <td>{{ $item->purchase?->user?->name ?? '—' }}</td>
        <td>@if(Route::has('purchases.show') && $item->purchase_id)<a class="btn btn-sm btn-outline-primary" href="{{ route('purchases.show', $item->purchase_id) }}">مشاهده</a>@else — @endif</td>
    </tr>
    @empty
    <tr><td colspan="10" class="text-center text-muted py-4">برای این کالا هنوز سابقه خرید ثبت نشده است</td></tr>
    @endforelse
    </tbody></table></div></div>
    <div class="mt-3">{{ $ledgerItems->links() }}</div>
</div>
@endsection
