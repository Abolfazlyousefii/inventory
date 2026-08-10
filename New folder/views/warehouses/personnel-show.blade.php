@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="page-title mb-0">موجودی پرسنل: {{ $personnel->name }}</h4>
    <a class="btn btn-outline-secondary" href="{{ route('warehouses.personnel.index', $warehouse) }}">بازگشت</a>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-striped align-middle mb-0">
            <thead>
                <tr>
                    <th>دسته‌بندی</th>
                    <th>نام کالا</th>
                    <th>SKU</th>
                    <th>تعداد</th>
                </tr>
            </thead>
            <tbody>
            @forelse($stocks as $stock)
                <tr>
                    <td>{{ $stock->product?->category?->name }}</td>
                    <td class="fw-semibold">{{ $stock->product?->name }}</td>
                    <td class="text-muted">{{ $stock->product?->sku }}</td>
                    <td class="fw-bold">{{ $stock->quantity }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted py-4">کالایی برای این پرسنل ثبت نشده است.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
