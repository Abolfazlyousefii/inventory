@extends('layouts.app')
@section('title', 'گردش حساب اشخاص')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/account-statements-list.css') }}?v=20261011">
@endpush

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
<div class="as-list">
<header class="as-heading">
    <span class="as-heading-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 3h12a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M8 8h8M8 12h8M8 16h4"/></svg></span>
    <div><h1>گردش حساب اشخاص</h1><p>ماندهٔ حساب مشتریان، در یک نگاه</p></div>
    <span class="as-unit">مبالغ به ریال</span>
</header>

<div class="card as-search-card mb-3">
    <div class="card-body">
        <form id="accountStatementSearch" class="d-flex gap-2" method="GET" action="{{ route('account-statements.index') }}">
            @if($sort)<input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="direction" value="{{ $direction }}">@endif
            <input class="form-control" type="search" name="q" value="{{ $q ?? '' }}" autocomplete="off" aria-label="جستجوی اشخاص" placeholder="نام مشتری، موبایل، کد یا شهر…">
            <button class="btn as-search-button" type="submit"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/></svg><span>جستجو</span></button>
        </form>
        <div id="accountStatementSearchStatus" class="small text-muted mt-2" role="status" aria-live="polite"></div>
    </div>
</div>

<div id="accountStatementResults" class="card as-results">
    <div class="card-body">
        <div class="as-list-toolbar">
            <div><strong>اشخاص</strong><span class="as-result-count">{{ number_format($customers->total()) }} نفر{{ $q !== '' ? ' در نتایج جستجو' : '' }}</span></div>
            <nav class="as-sort" aria-label="مرتب‌سازی اشخاص">
                <span>مرتب‌سازی:</span>
                @foreach(['name' => 'نام', 'debt' => 'بدهکاری', 'credit' => 'بستانکاری'] as $column => $label)
                    <a href="{{ $sortUrl($column) }}" class="{{ $sort === $column ? 'is-active' : '' }}" @if($sort === $column) aria-current="true" @endif>{{ $label }}@if($sort === $column) {{ $direction === 'desc' ? '↓' : '↑' }}@endif</a>
                @endforeach
            </nav>
        </div>
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
                        <tr class="as-person-row {{ $balance > 0 ? 'as-person-debt' : ($balance < 0 ? 'as-person-credit' : 'as-person-settled') }}">
                            <td class="as-person"><a href="{{ route('account-statements.show', $customer->id) }}">{{ $customer->display_name ?: '-' }}</a></td>
                            <td class="as-mobile" data-label="موبایل"><span dir="ltr">{{ $customer->mobile ?: '-' }}</span></td>
                            <td class="as-amount" data-label="بدهکاری">{{ \App\Support\Currency::formatRial($customer->debt) }}</td>
                            <td class="as-amount" data-label="بستانکاری">{{ \App\Support\Currency::formatRial($customer->credit) }}</td>
                            <td class="as-balance {{ $statusClass }}" data-label="ماندهٔ حساب"><span class="as-status {{ $balance > 0 ? 'is-debt' : ($balance < 0 ? 'is-credit' : 'is-settled') }}">{{ $statusLabel }}</span><strong>{{ $balance === 0 ? '—' : \App\Support\Currency::formatRial(abs($balance)) }}</strong></td>
                            <td class="as-action text-end">
                                <a href="{{ route('account-statements.show', $customer->id) }}" class="as-open" aria-label="مشاهده گردش حساب {{ $customer->display_name }}">گردش حساب <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M19 12H5m7-7-7 7 7 7"/></svg></a>
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
        if (!link || !link.closest('thead, .pagination, .as-sort') || event.ctrlKey || event.metaKey || event.shiftKey) return;
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
