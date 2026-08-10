@extends('layouts.app')

@section('content')
<div class="container py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">حواله بین انبار</h4>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('vouchers.index') }}">بازگشت</a>
            <a class="btn btn-primary" href="{{ route('vouchers.section.create', 'transfer') }}">+ ثبت جدید</a>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-striped mb-0">
                <thead><tr><th>#</th><th>شماره</th><th>تاریخ</th><th>مبدا</th><th>مقصد</th><th>کاربر</th><th></th></tr></thead>
                <tbody>
                @forelse($vouchers as $voucher)
                    <tr>
                        <td>{{ $voucher->id }}</td>
                        <td>{{ $voucher->reference ?: ('TR-'.$voucher->id) }}</td>
                        <td>{{ $voucher->transferred_at?->format('Y/m/d H:i') }}</td>
                        <td>{{ $voucher->fromWarehouse?->name ?: '—' }}</td>
                        <td>{{ $voucher->toWarehouse?->name ?: '—' }}</td>
                        <td>{{ $voucher->user?->name ?: '—' }}</td>
                        <td><a class="btn btn-sm btn-outline-primary" href="{{ route('vouchers.edit', $voucher) }}">ویرایش</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center py-4 text-muted">موردی ثبت نشده است.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $vouchers->links() }}</div>
</div>
@endsection
