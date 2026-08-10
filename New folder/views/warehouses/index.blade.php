@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="page-title mb-0">تعریف انبارها</h4>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-striped align-middle mb-0">
            <thead>
                <tr>
                    <th>نام انبار</th>
                    <th>نوع</th>
                    <th>تعداد آیتم موجودی</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
            @forelse($warehouses as $warehouse)
                <tr>
                    <td class="fw-semibold">{{ $warehouse->name }}</td>
                    <td>
                        @switch($warehouse->type)
                            @case('central') انبار مرکزی @break
                            @case('return') انبار مرجوعی @break
                            @case('scrap') انبار ضایعات @break
                            @default انبار پرسنل
                        @endswitch
                    </td>
                    <td>{{ $warehouse->stocks_count }}</td>
                    <td class="d-flex gap-2">
                        @if($warehouse->isPersonnelRoot())
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('warehouses.personnel.index', $warehouse) }}">مدیریت پرسنل</a>
                        @else
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('warehouses.edit', $warehouse) }}">ویرایش</a>
                        @endif

                        <form method="POST" action="{{ route('warehouses.destroy', $warehouse) }}" onsubmit="return confirm('از حذف این انبار مطمئن هستید؟')">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">حذف</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center text-muted py-4">انباری ثبت نشده است.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
