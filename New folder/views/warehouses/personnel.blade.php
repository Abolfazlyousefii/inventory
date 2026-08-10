@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="page-title mb-0">زیرمجموعه پرسنل - {{ $warehouse->name }}</h4>
    <a class="btn btn-outline-secondary" href="{{ route('warehouses.index') }}">بازگشت</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="POST" action="{{ route('warehouses.personnel.store', $warehouse) }}" class="row g-3">
            @csrf
            <div class="col-md-6">
                <label class="form-label">نام پرسنل</label>
                <input class="form-control" name="name" required value="{{ old('name') }}" placeholder="مثلاً آقای یوسفی">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button class="btn btn-primary">افزودن پرسنل</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-striped align-middle mb-0">
            <thead>
                <tr>
                    <th>نام پرسنل</th>
                    <th>تعداد کالاهای دارای موجودی</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
            @forelse($personnels as $personnel)
                <tr>
                    <td class="fw-semibold">{{ $personnel->name }}</td>
                    <td>{{ $personnel->stocked_products_count }}</td>
                    <td class="d-flex gap-2">
                        <a class="btn btn-sm btn-outline-dark" href="{{ route('warehouses.personnel.show', [$warehouse, $personnel]) }}">مشاهده</a>
                        <form method="POST" action="{{ route('warehouses.destroy', $personnel) }}" onsubmit="return confirm('از حذف این پرسنل مطمئن هستید؟')">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">حذف</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-center text-muted py-4">پرسنلی ثبت نشده است.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
