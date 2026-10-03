@extends('layouts.app')

@section('content')
@php
    $sortUrl = function (string $column) use ($q, $sort, $direction) {
        $nextDirection = $sort === $column ? ($direction === 'desc' ? 'asc' : null) : 'desc';
        return route('account-statements.index', array_filter([
            'q' => $q,
            'sort' => $nextDirection ? $column : null,
            'direction' => $nextDirection,
        ], fn ($value) => $value !== null && $value !== ''));
    };
    $sortLabel = fn (string $column, string $label) => $label . ($sort === $column ? ($direction === 'desc' ? ' ↓' : ' ↑') : ' ⇅');
@endphp
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">📒 گردش حساب اشخاص</h4>
        <div class="text-muted small">لیست کامل اشخاص (مشتریان) و وضعیت حساب هر شخص</div>
    </div>
    <a class="btn btn-outline-primary" href="{{ route('account-statements.import.create') }}">ورود اکسل مانده‌ها</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form id="accountStatementSearch" class="d-flex gap-2" method="GET" action="{{ route('account-statements.index') }}">
            @if($sort)<input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="direction" value="{{ $direction }}">@endif
            <input class="form-control" type="search" name="q" value="{{ $q ?? '' }}" autocomplete="off" aria-label="جستجوی اشخاص" placeholder="جستجو با نام، نام خانوادگی، شماره تماس، کد مشتری یا شهر">
            <button class="btn btn-outline-secondary">جستجو</button>
        </form>
        <div id="accountStatementSearchStatus" class="small text-muted mt-2" role="status" aria-live="polite"></div>
    </div>
</div>

<div id="accountStatementResults" class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th><a class="link-body-emphasis text-decoration-none" href="{{ $sortUrl('name') }}" aria-label="مرتب‌سازی نام شخص">{{ $sortLabel('name', 'نام شخص') }}</a></th>
                        <th><a class="link-body-emphasis text-decoration-none" href="{{ $sortUrl('mobile') }}" aria-label="مرتب‌سازی موبایل">{{ $sortLabel('mobile', 'موبایل') }}</a></th>
                        <th><a class="link-body-emphasis text-decoration-none" href="{{ $sortUrl('debt') }}" aria-label="مرتب‌سازی بدهکاری">{{ $sortLabel('debt', 'بدهکاری') }}</a></th>
                        <th><a class="link-body-emphasis text-decoration-none" href="{{ $sortUrl('credit') }}" aria-label="مرتب‌سازی بستانکاری">{{ $sortLabel('credit', 'بستانکاری') }}</a></th>
                        <th><a class="link-body-emphasis text-decoration-none" href="{{ $sortUrl('status') }}" aria-label="مرتب‌سازی وضعیت نهایی">{{ $sortLabel('status', 'وضعیت نهایی') }}</a></th>
                        <th class="text-end">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($customers as $customer)
                        @php
                            $balance = (int) $customer->balance;
                            $statusLabel = $balance > 0 ? 'بدهکار' : ($balance < 0 ? 'بستانکار' : 'تسویه');
                            $statusClass = $balance > 0 ? 'text-danger' : ($balance < 0 ? 'text-success' : 'text-muted');
                        @endphp
                        <tr>
                            <td>{{ $customer->display_name ?: '-' }}</td>
                            <td>{{ $customer->mobile ?: '-' }}</td>
                            <td>{{ \App\Support\Currency::formatRial($customer->debt) }}</td>
                            <td>{{ \App\Support\Currency::formatRial($customer->credit) }}</td>
                            <td class="fw-semibold {{ $statusClass }}">{{ $statusLabel }} {{ $balance === 0 ? '' : \App\Support\Currency::formatRial(abs($balance)) }}</td>
                            <td class="text-end">
                                <a href="{{ route('account-statements.show', $customer->id) }}" class="btn btn-sm btn-primary">مشاهده گردش حساب</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center py-4 text-muted">موردی با این مشخصات پیدا نشد.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $customers->links() }}</div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const form = document.getElementById('accountStatementSearch');
    const input = form?.querySelector('[name="q"]');
    const results = document.getElementById('accountStatementResults');
    const status = document.getElementById('accountStatementSearchStatus');
    if (!form || !input || !results || !status) return;

    let timer;
    let controller;
    let sequence = 0;

    const load = async (url, push = false) => {
        controller?.abort();
        controller = new AbortController();
        const current = ++sequence;
        status.textContent = 'در حال جستجو...';
        results.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, {signal: controller.signal, headers: {'X-Requested-With': 'XMLHttpRequest'}});
            if (!response.ok) throw new Error('Request failed');
            const documentResult = new DOMParser().parseFromString(await response.text(), 'text/html');
            const nextResults = documentResult.getElementById('accountStatementResults');
            if (!nextResults || current !== sequence) throw new Error('Invalid result');
            results.innerHTML = nextResults.innerHTML;
            const nextUrl = new URL(url, window.location.href);
            window.history[push ? 'pushState' : 'replaceState']({}, '', nextUrl);
            status.textContent = '';
        } catch (error) {
            if (error.name !== 'AbortError' && current === sequence) status.textContent = 'دریافت نتایج ناموفق بود؛ دوباره جستجو کنید.';
        } finally {
            if (current === sequence) results.removeAttribute('aria-busy');
        }
    };

    const searchUrl = () => {
        const url = new URL(form.action, window.location.href);
        const value = input.value.trim();
        if (value) url.searchParams.set('q', value);
        for (const field of form.querySelectorAll('input[type="hidden"]')) url.searchParams.set(field.name, field.value);
        return url;
    };

    input.addEventListener('input', () => {
        clearTimeout(timer);
        controller?.abort();
        sequence++;
        timer = setTimeout(() => load(searchUrl()), 350);
    });
    form.addEventListener('submit', event => {
        event.preventDefault();
        clearTimeout(timer);
        load(searchUrl(), true);
    });
    results.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || !link.closest('thead, .pagination') || event.ctrlKey || event.metaKey || event.shiftKey) return;
        event.preventDefault();
        clearTimeout(timer);
        const url = new URL(link.href);
        form.querySelectorAll('input[type="hidden"]').forEach(field => field.remove());
        for (const key of ['sort', 'direction']) {
            if (url.searchParams.has(key)) {
                const field = document.createElement('input');
                field.type = 'hidden'; field.name = key; field.value = url.searchParams.get(key);
                form.append(field);
            }
        }
        load(url, true);
    });
    window.addEventListener('popstate', () => {
        const url = new URL(window.location.href);
        input.value = url.searchParams.get('q') || '';
        form.querySelectorAll('input[type="hidden"]').forEach(field => field.remove());
        for (const key of ['sort', 'direction']) {
            if (url.searchParams.has(key)) {
                const field = document.createElement('input');
                field.type = 'hidden'; field.name = key; field.value = url.searchParams.get(key);
                form.append(field);
            }
        }
        load(url);
    });
})();
</script>
@endpush
