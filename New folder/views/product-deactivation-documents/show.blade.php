@extends('layouts.app')

@section('content')
@php
    $toJalali = function ($date) {
        if (!$date) {
            return '-';
        }

        if (class_exists(\Hekmatinasser\Verta\Verta::class)) {
            return \Hekmatinasser\Verta\Verta::instance($date)->format('Y/m/d H:i');
        }

        return optional($date)->format('Y/m/d H:i');
    };
@endphp
<div class="card">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">جزئیات سند غیرفعال‌سازی</h4>
            <a href="{{ route('product-deactivation-documents.index') }}" class="btn btn-outline-secondary">بازگشت</a>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-3"><b>شناسه سند:</b> {{ $document->document_number }}</div>
            <div class="col-md-3"><b>تاریخ ثبت:</b> {{ $toJalali($document->created_at) }}</div>
            <div class="col-md-3"><b>تعداد آیتم‌ها:</b> {{ (int) ($document->items_count ?: max(1, $document->items->count())) }}</div>
            <div class="col-md-3"><b>ثبت‌کننده:</b> {{ $document->creator?->name ?? '-' }}</div>
            <div class="col-md-12">
                <b>دلیل غیرفعال‌سازی:</b>
                <div class="mt-1 border rounded p-2 bg-light">{{ $document->reason_text }}</div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>دسته‌بندی</th>
                        <th>زیر دسته‌بندی</th>
                        <th>کالا</th>
                        <th>تنوع</th>
                        <th>نوع غیرفعال‌سازی</th>
                        <th>وضعیت</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($document->items as $item)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ $item->category_name_snapshot ?: '-' }}</td>
                            <td>{{ $item->subcategory_name_snapshot ?: '-' }}</td>
                            <td>{{ $item->product_name_snapshot ?: ($item->product?->name ?? '-') }}</td>
                            <td>{{ $item->variant_name_snapshot ?: ($item->variant?->variant_name ?? '—') }}</td>
                            <td>{{ $typeLabels[$item->deactivation_type] ?? $item->deactivation_type }}</td>
                            <td><span class="badge bg-secondary">غیرفعال شد</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td>1</td>
                            <td>-</td>
                            <td>-</td>
                            <td>{{ $document->product_name_snapshot ?: '-' }}</td>
                            <td>{{ $document->variant_name_snapshot ?: '—' }}</td>
                            <td>{{ $typeLabels[$document->deactivation_type] ?? $document->deactivation_type }}</td>
                            <td><span class="badge bg-secondary">غیرفعال شد</span></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
