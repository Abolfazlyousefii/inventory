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
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">لیست اسناد غیرفعال‌سازی کالا</h4>
    <a href="{{ route('product-deactivation-documents.create') }}" class="btn btn-primary">ثبت جدید غیرفعال‌سازی</a>
</div>

@if ($errors->any())
    <div class="alert alert-danger">
        <div class="fw-bold mb-2">خطاهای فرم:</div>
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card">
    <div class="card-body">
        <form method="GET" class="row g-2">
            <div class="col-md-3">
                <label class="form-label">تاریخ از</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label">تاریخ تا</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label">بازه زمانی</label>
                <select name="time_range" class="form-select">
                    <option value="">همه</option>
                    <option value="today" @selected(request('time_range')==='today')>امروز</option>
                    <option value="7d" @selected(request('time_range')==='7d')>۷ روز اخیر</option>
                    <option value="30d" @selected(request('time_range')==='30d')>۳۰ روز اخیر</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button class="btn btn-outline-primary">اعمال فیلتر</button>
                <a href="{{ route('product-deactivation-documents.index') }}" class="btn btn-outline-secondary">حذف فیلتر</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead>
                <tr>
                    <th>شناسه سند</th>
                    <th>تاریخ ثبت</th>
                    <th>تعداد کل آیتم‌ها</th>
                    <th>دلیل غیرفعال‌سازی</th>
                    <th>ثبت‌کننده</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($documents as $doc)
                <tr>
                    <td class="fw-bold">{{ $doc->document_number }}</td>
                    <td>{{ $toJalali($doc->created_at) }}</td>
                    <td><span class="badge bg-info-subtle text-dark">{{ (int) ($doc->items_count ?: 1) }}</span></td>
                    <td class="text-wrap" style="min-width: 240px;">{{ $doc->reason_text }}</td>
                    <td>{{ $doc->creator?->name ?? '-' }}</td>
                    <td><a href="{{ route('product-deactivation-documents.show', $doc) }}" class="btn btn-sm btn-outline-dark">مشاهده</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center py-4 text-muted">هنوز سندی ثبت نشده است.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body">{{ $documents->links() }}</div>
</div>
@endsection
