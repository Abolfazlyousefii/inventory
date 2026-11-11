@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <a href="{{ route('persons.index') }}" class="text-decoration-none small">← بازگشت به اشخاص</a>
        <h4 class="mb-1 mt-2">{{ $person['name'] ?: 'شخص بدون نام' }}</h4>
        <div class="text-muted small">{{ $person['mobile'] ?: 'بدون شماره تماس' }}</div>
        <div class="mt-2 d-flex gap-2">
            @if($person['is_customer']) <span class="badge text-bg-primary">مشتری</span> @endif
            @if($person['is_supplier']) <span class="badge text-bg-success">تأمین‌کننده</span> @endif
        </div>
    </div>
    <a href="{{ route('persons.index') }}" class="btn btn-outline-secondary">فهرست اشخاص</a>
</div>

<div class="row g-3 mb-4">
    @if($person['is_customer'])
        <div class="col-md-6">
            <div class="card h-100 border-primary-subtle">
                <div class="card-body">
                    <div class="text-muted small">مطالبات ما از شخص (حساب مشتری)</div>
                    <div class="fs-4 fw-bold">{{ number_format($customerReceivableBalance) }} <small class="fs-6 text-muted">ریال</small></div>
                    <div class="small {{ $customerReceivableBalance > 0 ? 'text-danger' : ($customerReceivableBalance < 0 ? 'text-success' : 'text-muted') }}">
                        {{ $customerReceivableBalance > 0 ? 'شخص بدهکار ماست' : ($customerReceivableBalance < 0 ? 'شخص بستانکار است' : 'حساب تسویه است') }}
                    </div>
                </div>
            </div>
        </div>
    @endif
    @if($person['is_supplier'])
        <div class="col-md-6">
            <div class="card h-100 border-success-subtle">
                <div class="card-body">
                    <div class="text-muted small">بدهی ما به شخص (حساب تأمین‌کننده)</div>
                    <div class="fs-4 fw-bold">{{ number_format($supplierPayableBalance) }} <small class="fs-6 text-muted">ریال</small></div>
                    <div class="small {{ $supplierPayableBalance > 0 ? 'text-danger' : ($supplierPayableBalance < 0 ? 'text-success' : 'text-muted') }}">
                        {{ $supplierPayableBalance > 0 ? 'ما به شخص بدهکاریم' : ($supplierPayableBalance < 0 ? 'شخص به ما بدهکار است' : 'حساب تسویه است') }}
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

@if($person['is_customer'])
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>گردش حساب مشتری</strong>
        <span class="small text-muted">مطالبات ما</span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead><tr><th>تاریخ ثبت</th><th>شرح</th><th>بدهکار</th><th>بستانکار</th></tr></thead>
                <tbody>
                @forelse($customerEntries as $entry)
                    <tr>
                        <td class="text-nowrap">{{ $entry->created_at?->format('Y/m/d H:i') ?: '—' }}</td>
                        <td>{{ $entry->note ?: class_basename($entry->reference_type ?: 'سند') }}</td>
                        <td class="text-danger">{{ $entry->type === 'debit' ? number_format($entry->amount) : '—' }}</td>
                        <td class="text-success">{{ $entry->type === 'credit' ? number_format($entry->amount) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-3">گردش مالی مشتری ثبت نشده است.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $customerEntries->links() }}
    </div>
</div>
@endif

@if($person['is_supplier'])
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>گردش حساب تأمین‌کننده</strong>
        <span class="small text-muted">بدهی ما</span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead><tr><th>تاریخ ثبت</th><th>شرح</th><th>پرداخت / بدهکار</th><th>خرید / بستانکار</th></tr></thead>
                <tbody>
                @forelse($supplierEntries as $entry)
                    <tr>
                        <td class="text-nowrap">{{ $entry->created_at?->format('Y/m/d H:i') ?: '—' }}</td>
                        <td>{{ $entry->note ?: class_basename($entry->reference_type ?: 'سند') }}</td>
                        <td class="text-danger">{{ $entry->type === 'debit' ? number_format($entry->amount) : '—' }}</td>
                        <td class="text-success">{{ $entry->type === 'credit' ? number_format($entry->amount) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-3">گردش مالی تأمین‌کننده ثبت نشده است.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $supplierEntries->links() }}
    </div>
</div>
@endif
@endsection
