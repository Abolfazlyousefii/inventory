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
                        <td class="text-nowrap">{{ $entry->statement_date ? date('Y/m/d H:i', strtotime($entry->statement_date)) : '—' }}</td>
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
        <form method="POST" action="{{ route('persons.supplier-ledgers.store', request()->route('personKey')) }}" class="row g-2 align-items-end border rounded p-3 mb-3">
            @csrf
            <div class="col-md-3">
                <label class="form-label">نوع عملیات</label>
                <select name="direction" class="form-select" required>
                    <option value="payment">پرداخت به تأمین‌کننده</option>
                    <option value="receipt">دریافت از تأمین‌کننده</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">مبلغ (ریال)</label>
                <input type="number" name="amount" min="1" max="9000000000000000" class="form-control" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">تاریخ سند</label>
                <input type="datetime-local" name="transaction_date" class="form-control" value="{{ now()->format('Y-m-d\\TH:i') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label">روش</label>
                <input type="text" name="method" maxlength="50" class="form-control" placeholder="نقدی / کارت">
            </div>
            <div class="col-md-2">
                <label class="form-label">شماره پیگیری</label>
                <input type="text" name="reference" maxlength="100" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label">شرح</label>
                <input type="text" name="note" maxlength="255" class="form-control" placeholder="مثلاً پرداخت نقدی">
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100">ثبت سند</button></div>
            @if($errors->any())
                <div class="col-12 text-danger small">{{ $errors->first() }}</div>
            @endif
        </form>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead><tr><th>تاریخ ثبت</th><th>شرح</th><th>پرداخت / بدهکار</th><th>خرید / بستانکار</th></tr></thead>
                <tbody>
                @forelse($supplierEntries as $entry)
                    <tr>
                        <td class="text-nowrap">{{ $entry->reference?->transaction_date?->format('Y/m/d H:i') ?: $entry->created_at?->format('Y/m/d H:i') ?: '—' }}</td>
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
