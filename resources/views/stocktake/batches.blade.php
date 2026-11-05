@extends('layouts.app')
@section('title', 'آرشیو ثبت‌های یکجای انبارگردانی')
@section('content')
<div class="container-fluid px-3 py-4" dir="rtl" style="max-width:1500px">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-2">آرشیو ثبت‌های یکجای انبارگردانی</h3>
            <p class="text-muted mb-0">هر عملیات ثبت نهایی یکجا در یک گروه نمایش داده می‌شود. اسناد اصلی و سوابق تعدیل موجودی بدون تغییر باقی مانده‌اند.</p>
        </div>
        <a class="btn btn-outline-primary" href="{{ route('stocktake.index') }}">بازگشت به آرشیو همه اسناد</a>
    </div>

    @forelse($batches as $batch)
        <section class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div>
                        <h5 class="fw-bold mb-2">ثبت نهایی یکجا ـ عملیات شماره {{ $batch->id }}</h5>
                        <div class="text-muted small">تاریخ ثبت: <bdi>{{ $batch->created_at }}</bdi></div>
                        <div class="small mt-2">تعداد اسناد مرتبط: <strong>{{ $batch->document_count }}</strong> | جمع افزایش: <strong>{{ number_format($batch->total_increase) }}</strong> | جمع کاهش: <strong>{{ number_format($batch->total_decrease) }}</strong></div>
                    </div>
                    <span class="badge bg-primary">یک عملیات، {{ $batch->document_count }} سند اصلی</span>
                </div>
                @if($batch->missing_count > 0)
                    <div class="alert alert-warning mt-3 mb-0">{{ $batch->missing_count }} شناسه از پاسخ ثبت نهایی در جدول اسناد پیدا نشد؛ برای حفظ سوابق هیچ رکوردی تغییر نکرد.</div>
                @endif
                <details class="mt-3">
                    <summary class="btn btn-outline-secondary">مشاهده اسناد اصلی این عملیات</summary>
                    <div class="table-responsive mt-3">
                        <table class="table table-striped table-sm align-middle">
                            <thead><tr><th>شماره سند</th><th>محصول</th><th>وضعیت فعلی</th><th>افزایش</th><th>کاهش</th><th>مشاهده</th></tr></thead>
                            <tbody>
                            @foreach($batch->documents as $document)
                                <tr>
                                    <td><bdi>{{ $document->document_number }}</bdi></td>
                                    <td>{{ $document->product?->name ?? '—' }}</td>
                                    <td>{{ ['draft' => 'پیش‌نویس', 'finalized' => 'نهایی‌شده', 'cancelled' => 'لغوشده'][$document->status] ?? $document->status }}</td>
                                    <td>{{ number_format((int) $document->total_increase) }}</td>
                                    <td>{{ number_format((int) $document->total_decrease) }}</td>
                                    <td><a class="btn btn-sm btn-outline-primary" href="{{ route('stock-count-documents.view', $document) }}">مشاهده سند</a></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        </section>
    @empty
        <div class="alert alert-info">هیچ عملیات ثبت نهایی یکجا در سابقه درخواست‌ها ثبت نشده است.</div>
    @endforelse
    {{ $batches->links() }}
</div>
@endsection
