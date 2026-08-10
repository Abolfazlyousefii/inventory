@extends('layouts.app')

@section('content')
<div class="container py-4" dir="rtl">
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h4 fw-bold text-primary mb-1">تغییرات قیمت کالا</h1>
            <p class="text-muted mb-0">مدیریت سندی افزایش یا کاهش قیمت کالاها و تنوع‌ها</p>
        </div>
        <a class="btn btn-primary align-self-start" href="{{ route('products.price-changes.create') }}">ثبت سند جدید</a>
    </div>

    @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
    @if (session('status')) <div class="alert alert-info">{{ session('status') }}</div> @endif
    @if ($errors->any()) <div class="alert alert-danger">{{ $errors->first() }}</div> @endif

    <div class="row g-3 mb-4">
        @foreach ([['کل اسناد',$stats['total']], ['پیش‌نویس‌ها',$stats['draft']], ['اعمال‌شده‌ها',$stats['applied']], ['اسناد امروز',$stats['today']]] as [$label,$value])
            <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">{{ $label }}</div><div class="fs-4 fw-bold text-primary">{{ number_format($value) }}</div></div></div></div>
        @endforeach
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if ($documents->isEmpty())
                <div class="text-center p-5">
                    <div class="fs-5 fw-bold mb-2">هنوز سند تغییر قیمتی ثبت نشده است.</div>
                    <p class="text-muted">اولین سند را بسازید، پیش‌نمایش بگیرید و سپس در صورت تأیید اعمال کنید.</p>
                    <a class="btn btn-primary" href="{{ route('products.price-changes.create') }}">ثبت اولین سند تغییر قیمت</a>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>کد سند</th><th>عنوان</th><th>محدوده</th><th>نوع تغییر</th><th>تعداد آیتم</th><th>وضعیت</th><th>ثبت‌کننده</th><th>تاریخ ثبت</th><th>تاریخ اعمال</th><th>عملیات</th></tr></thead>
                        <tbody>
                            @foreach ($documents as $document)
                                <tr>
                                    <td class="fw-bold">{{ $document->code ?? $document->id }}</td>
                                    <td>{{ $document->title ?: '—' }}</td>
                                    <td>{{ $document->scopeLabel() }}</td>
                                    <td>{{ $document->changeTypeLabel() }}</td>
                                    <td>{{ number_format($document->items_count) }}</td>
                                    <td><span class="badge {{ $document->status === 'applied' ? 'bg-success' : ($document->status === 'draft' ? 'bg-warning text-dark' : 'bg-secondary') }}">{{ $document->statusLabel() }}</span></td>
                                    <td>{{ $document->createdBy?->name ?: '—' }}</td>
                                    <td>{{ optional($document->created_at)->format('Y-m-d H:i') }}</td>
                                    <td>{{ optional($document->applied_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                    <td><div class="d-flex flex-wrap gap-1"><a class="btn btn-sm btn-outline-primary" href="{{ route('products.price-changes.show', $document) }}">مشاهده</a>@if($document->status === 'draft')<form method="post" action="{{ route('products.price-changes.apply', $document) }}">@csrf<button class="btn btn-sm btn-success">اعمال</button></form><form method="post" action="{{ route('products.price-changes.cancel', $document) }}">@csrf<button class="btn btn-sm btn-outline-danger">لغو</button></form>@endif</div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="p-3">{{ $documents->links() }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
