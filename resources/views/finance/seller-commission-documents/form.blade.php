@extends('layouts.app')

@php
    $editing = (bool) $document;
    $initialItems = $editing
        ? $document->items->map(fn ($item) => [
            'id' => (int) $item->invoice_id,
            'number' => $item->invoice_number_snapshot,
            'date' => $item->invoice_date_snapshot?->format('Y-m-d'),
            'date_display' => App\Support\JalaliDate::date($item->invoice_date_snapshot),
            'customer' => $item->customer_name_snapshot,
            'total' => (int) $item->invoice_total_snapshot,
        ])->values()
        : collect(old('invoice_ids', []))->map(fn ($id) => ['id' => (int) $id, 'number' => '…', 'date' => null, 'date_display' => '—', 'customer' => 'در انتظار بارگذاری', 'total' => 0])->values();
@endphp

@section('title', $editing ? 'ویرایش سند '.$document->document_number : 'ثبت سند فروش جدید')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/seller-commission-documents.css') }}?v={{ filemtime(public_path('css/seller-commission-documents.css')) }}">
@endpush

@section('content')
<div class="seller-commission-page" id="sellerCommissionApp"
     data-endpoint="{{ route('finance.seller-sales.available-invoices') }}"
     data-document-id="{{ $document?->id }}">
    <div class="seller-commission-header">
        <div>
            <h1>{{ $editing ? 'ویرایش '.$document->document_number : 'ثبت سند فروش جدید' }}</h1>
            <p>فاکتورهای ثبت‌شده توسط یک کاربر را انتخاب و در یک سند مالی مستقل نگهداری کنید.</p>
        </div>
        <a class="btn btn-light" href="{{ route('finance.seller-sales.index') }}">بازگشت به اسناد</a>
    </div>

    <form method="POST" action="{{ $editing ? route('finance.seller-sales.update', $document) : route('finance.seller-sales.store') }}" id="documentForm">
        @csrf
        @if($editing) @method('PUT') @endif

        <div class="seller-commission-card p-3 mb-3">
            <div class="row g-3 align-items-end">
                <div class="col-lg-3 col-md-6">
                    <label class="form-label" for="sellerUserId">کاربر فروشنده</label>
                    <select class="form-select" id="sellerUserId" name="user_id" required>
                        <option value="">انتخاب کنید</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}" @selected((string) old('user_id', $document?->seller_id) === (string) $user->id)>{{ $user->name }} (#{{ $user->id }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label" for="dateFrom">از تاریخ ثبت پیش‌فاکتور</label>
                    <input class="form-control" id="dateFrom" name="date_from" data-jdp autocomplete="off" value="{{ old('date_from', $document ? App\Support\JalaliDate::date($document->period_from) : '') }}" required>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label" for="dateTo">تا تاریخ ثبت پیش‌فاکتور</label>
                    <input class="form-control" id="dateTo" name="date_to" data-jdp autocomplete="off" value="{{ old('date_to', $document ? App\Support\JalaliDate::date($document->period_to) : '') }}" required>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label" for="notes">توضیحات (اختیاری)</label>
                    <input class="form-control" id="notes" name="notes" maxlength="2000" value="{{ old('notes', $document?->notes) }}">
                </div>
                <div class="col-lg-2">
                    <button type="button" class="btn btn-primary w-100" id="loadInvoices">نمایش فاکتورها</button>
                </div>
            </div>
        </div>

        <div class="seller-commission-card overflow-hidden">
            <div class="p-3 border-bottom d-flex flex-wrap align-items-end justify-content-between gap-3">
                <div class="flex-grow-1" style="max-width:420px">
                    <label class="form-label" for="invoiceSearch">جست‌وجوی شماره فاکتور یا مشتری</label>
                    <div class="input-group"><input class="form-control" id="invoiceSearch"><button class="btn btn-outline-secondary" type="button" id="searchInvoices">جست‌وجو</button></div>
                </div>
                <div class="d-flex flex-wrap gap-3">
                    <label class="form-check"><input class="form-check-input" type="checkbox" id="selectPage"> <span class="form-check-label">انتخاب همه این صفحه</span></label>
                    <span>یافت‌شده: <strong id="foundCount">۰</strong></span>
                    <span>انتخاب‌شده: <strong id="selectedCount">۰</strong></span>
                </div>
            </div>
            <div id="loadError" class="alert alert-danger m-3" hidden></div>
            <div id="loading" class="p-4 text-center" hidden>در حال دریافت فاکتورها…</div>
            <div class="table-responsive">
                <table class="table seller-commission-table mb-0">
                    <thead><tr><th></th><th>ردیف</th><th>شماره فاکتور</th><th>تاریخ ثبت اولیه پیش‌فاکتور</th><th>نام مشتری</th><th>مبلغ نهایی فاکتور</th></tr></thead>
                    <tbody id="invoiceRows"><tr><td colspan="6" class="seller-commission-empty">کاربر و بازه تاریخی را انتخاب کنید.</td></tr></tbody>
                </table>
            </div>
            <div class="p-3 border-top d-flex justify-content-between align-items-center">
                <button type="button" class="btn btn-sm btn-light" id="prevPage">قبلی</button>
                <span id="pageLabel">—</span>
                <button type="button" class="btn btn-sm btn-light" id="nextPage">بعدی</button>
            </div>
        </div>

        <div id="hiddenInvoices"></div>
        <div class="seller-commission-summary">
            <div class="seller-commission-summary__numbers"><span>تعداد: <strong id="selectedSummary">۰ فاکتور</strong></span><span>جمع کل فروش: <strong id="totalSummary">۰ ریال</strong></span></div>
            <button class="btn btn-success" id="submitButton">{{ $editing ? 'ذخیره تغییرات' : 'ثبت سند' }}</button>
        </div>
    </form>

    <script type="application/json" id="initialItems">{!! json_encode($initialItems, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('sellerCommissionApp');
    if (!app) return;
    const byId = id => document.getElementById(id);
    const selected = new Map(JSON.parse(byId('initialItems').textContent || '[]').map(item => [Number(item.id), item]));
    const numberFormat = new Intl.NumberFormat('fa-IR');
    let rows = [], page = 1, lastPage = 1, perPage = 20;
    let initialUser = byId('sellerUserId').value;

    const escapeHtml = value => String(value ?? '—').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
    const resetSelection = () => { selected.clear(); rows = []; page = lastPage = 1; byId('invoiceRows').innerHTML = '<tr><td colspan="6" class="seller-commission-empty">برای مشاهده فاکتورها دکمه نمایش را بزنید.</td></tr>'; sync(); };
    const sync = () => {
        byId('hiddenInvoices').innerHTML = [...selected.keys()].map(id => `<input type="hidden" name="invoice_ids[]" value="${id}">`).join('');
        const total = [...selected.values()].reduce((sum, item) => sum + Number(item.total || 0), 0);
        byId('selectedCount').textContent = numberFormat.format(selected.size);
        byId('selectedSummary').textContent = `${numberFormat.format(selected.size)} فاکتور`;
        byId('totalSummary').textContent = `${numberFormat.format(total)} ریال`;
        document.querySelectorAll('.invoice-choice').forEach(input => input.checked = selected.has(Number(input.value)));
        byId('selectPage').checked = rows.length > 0 && rows.every(item => selected.has(Number(item.id)));
    };
    const render = () => {
        byId('invoiceRows').innerHTML = rows.length ? rows.map((item, index) => `<tr>
            <td><input class="form-check-input invoice-choice" type="checkbox" value="${Number(item.id)}"></td>
            <td>${numberFormat.format(((page - 1) * perPage) + index + 1)}</td>
            <td class="fw-bold">${escapeHtml(item.number)}</td><td>${escapeHtml(item.date_display)}</td><td>${escapeHtml(item.customer)}</td>
            <td>${numberFormat.format(Number(item.total))} ریال</td></tr>`).join('') : '<tr><td colspan="6" class="seller-commission-empty">فاکتور آزادی در این بازه پیدا نشد.</td></tr>';
        document.querySelectorAll('.invoice-choice').forEach(input => input.addEventListener('change', () => {
            const item = rows.find(row => Number(row.id) === Number(input.value));
            input.checked ? selected.set(Number(input.value), item) : selected.delete(Number(input.value));
            sync();
        }));
        byId('pageLabel').textContent = `صفحه ${numberFormat.format(page)} از ${numberFormat.format(lastPage)}`;
        byId('prevPage').disabled = page <= 1;
        byId('nextPage').disabled = page >= lastPage;
        sync();
    };
    const load = async (requestedPage = 1) => {
        if (!byId('sellerUserId').value || !byId('dateFrom').value || !byId('dateTo').value) {
            byId('loadError').textContent = 'ابتدا کاربر و بازه تاریخی را کامل کنید.'; byId('loadError').hidden = false; return;
        }
        byId('loading').hidden = false; byId('loadError').hidden = true;
        const query = new URLSearchParams({user_id: byId('sellerUserId').value, date_from: byId('dateFrom').value, date_to: byId('dateTo').value, search: byId('invoiceSearch').value, page: requestedPage});
        if (app.dataset.documentId) query.set('document_id', app.dataset.documentId);
        try {
            const response = await fetch(`${app.dataset.endpoint}?${query}`, {headers: {'Accept': 'application/json'}});
            const payload = await response.json();
            if (!response.ok) {
                const validation = payload.errors ? Object.values(payload.errors).flat().join(' ') : payload.message;
                throw new Error(validation || 'دریافت فاکتورها ناموفق بود.');
            }
            rows = payload.data; page = payload.current_page; lastPage = payload.last_page; perPage = payload.per_page;
            rows.forEach(item => { if (selected.has(Number(item.id))) selected.set(Number(item.id), item); });
            byId('foundCount').textContent = numberFormat.format(payload.total); render();
        } catch (error) { byId('loadError').textContent = error.message; byId('loadError').hidden = false; }
        finally { byId('loading').hidden = true; }
    };

    byId('loadInvoices').addEventListener('click', () => load(1));
    byId('searchInvoices').addEventListener('click', () => load(1));
    byId('invoiceSearch').addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); load(1); } });
    byId('prevPage').addEventListener('click', () => page > 1 && load(page - 1));
    byId('nextPage').addEventListener('click', () => page < lastPage && load(page + 1));
    byId('selectPage').addEventListener('change', event => { rows.forEach(item => event.target.checked ? selected.set(Number(item.id), item) : selected.delete(Number(item.id))); sync(); });
    byId('sellerUserId').addEventListener('change', () => {
        if (selected.size && !window.confirm('با تغییر فروشنده، فاکتورهای انتخاب‌شده فعلی پاک خواهند شد.')) { byId('sellerUserId').value = initialUser; return; }
        initialUser = byId('sellerUserId').value; resetSelection(); byId('foundCount').textContent = '۰';
        if (initialUser && byId('dateFrom').value && byId('dateTo').value) load(1);
    });
    byId('documentForm').addEventListener('submit', event => {
        if (!selected.size) { event.preventDefault(); window.alert('حداقل یک فاکتور انتخاب کنید.'); return; }
        byId('submitButton').disabled = true;
    });
    sync();
    if (byId('sellerUserId').value && byId('dateFrom').value && byId('dateTo').value) load(1);
});
</script>
@endpush
