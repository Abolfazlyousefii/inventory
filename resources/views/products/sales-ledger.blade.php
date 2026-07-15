@extends('layouts.app')

@section('content')
@php
    use Morilog\Jalali\Jalalian;

    $toFa = static function ($value) {
        return strtr((string) $value, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    };
@endphp

<div class="container">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
        <div>
            <h4 class="mb-1">کارتکس فروش کالا</h4>
            <div class="text-muted">
                کالا: <strong>{{ $product->name }}</strong>
                @if($selectedVariant)
                    <span class="mx-1">|</span> تنوع: <strong>{{ $selectedVariant->variant_name }}</strong>
                @endif
            </div>
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">چاپ</button>
            <a class="btn btn-outline-success" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}">خروجی اکسل (CSV)</a>
            <a class="btn btn-outline-dark" href="{{ route('products.index') }}">بازگشت به کالاها</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">تعداد کل فروش در بازه</div>
                    <div class="h5 mb-0 mt-1">{{ $toFa(number_format((int) ($summary->total_quantity ?? 0))) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">جمع مبلغ فروش در بازه</div>
                    <div class="h5 mb-0 mt-1">{{ $toFa(\App\Support\Currency::formatRial($summary->total_amount ?? 0)) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">تاریخ از</label>
                    <input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">تاریخ تا</label>
                    <input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">مشتری</label>
                    <select name="customer_id" class="form-select">
                        <option value="">همه</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}" @selected((int) request('customer_id') === $customer->id)>
                                {{ trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) ?: 'مشتری #' . $customer->id }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">شماره فاکتور</label>
                    <input type="text" name="invoice_uuid" value="{{ request('invoice_uuid') }}" class="form-control" placeholder="مثال: INV-...">
                </div>

                @if($variants->isNotEmpty())
                    <div class="col-md-4">
                        <label class="form-label">تنوع</label>
                        <select name="variant_id" class="form-select">
                            <option value="">همه تنوع‌های کالا</option>
                            @foreach($variants as $variant)
                                <option value="{{ $variant->id }}" @selected((int) request('variant_id') === $variant->id)>
                                    {{ $variant->variant_name }} ({{ $variant->variant_code }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="col-md-4">
                    <label class="form-label">ثبت‌کننده</label>
                    <select name="creator_id" class="form-select">
                        <option value="">همه</option>
                        @foreach($creators as $creator)
                            <option value="{{ $creator->id }}" @selected((int) request('creator_id') === $creator->id)>
                                {{ $creator->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary">اعمال فیلتر</button>
                    <a class="btn btn-outline-secondary" href="{{ route('products.sales-ledger', $product) }}">حذف فیلتر</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>تاریخ فروش</th>
                        <th>شماره فاکتور</th>
                        <th>نام مشتری</th>
                        <th>نام کالا</th>
                        <th>تنوع / مدل</th>
                        <th class="text-end">تعداد</th>
                        <th class="text-end">قیمت واحد</th>
                        <th class="text-end">مبلغ کل ردیف</th>
                        <th>ثبت‌کننده</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($ledgerItems as $item)
                        <tr>
                            <td>{{ $item->invoice ? \App\Support\JalaliDate::dateTime($item->invoice->display_document_date) : '—' }}</td>
                            <td>{{ $item->invoice?->uuid ?? '—' }}</td>
                            <td>{{ $item->invoice?->customer_name ?? '—' }}</td>
                            <td>{{ $item->product?->name ?? $product->name }}</td>
                            <td>{{ $item->variant?->variant_name ?? '—' }}</td>
                            <td class="text-end">{{ number_format((int) $item->quantity) }}</td>
                            <td class="text-end">{{ number_format((int) $item->price) }}</td>
                            <td class="text-end fw-bold">{{ number_format((int) $item->line_total) }}</td>
                            <td>{{ $item->invoice?->preinvoiceOrder?->creator?->name ?? '—' }}</td>
                            <td>
                                @if($item->invoice?->uuid)
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('invoices.show', $item->invoice->uuid) }}">مشاهده فاکتور</a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">فروشی برای این فیلترها پیدا نشد.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $ledgerItems->links() }}
    </div>
</div>
@endsection
