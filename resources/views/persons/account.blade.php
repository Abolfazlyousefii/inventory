@extends('layouts.app')
@section('title', 'گردش حساب '.$person['name'])
@push('styles')
<link rel="stylesheet" href="{{ asset('css/account-statement-document.css') }}?v=20261114-shared-book">
@endpush
@section('content')
@php
    $rial = fn ($value) => \App\Support\Currency::formatRial(abs((int) $value));
    $balanceText = fn ($value) => $value > 0 ? 'بدهکار' : ($value < 0 ? 'بستانکار' : 'تسویه');
    $canAddPayment = $person['is_supplier'] && \App\Support\PageAccessCatalog::userCanRoute(auth()->user(), 'persons.supplier-transactions.store');
@endphp
<div class="as-document">
    <header class="asd-toolbar">
        <div>
            <span class="asd-eyebrow">دفتر حساب شخص</span><h1>{{ $person['name'] ?: 'شخص' }}</h1>
            <div class="asd-identity">
                <span>کد: {{ $person['supplier']?->id ?? $person['customer']?->id }}</span>
                <span dir="ltr">{{ $person['mobile'] ?: '—' }}</span>
                @if($person['is_customer'])<span>مشتری</span>@endif
                @if($person['is_supplier'])<span>تأمین‌کننده</span>@endif
            </div>
        </div>
        <div class="asd-actions">
            @if($canAddPayment)<button type="button" class="btn asd-primary" id="openSupplierPaymentModalBtn" data-bs-toggle="modal" data-bs-target="#supplierPaymentModal">+ افزودن پرداخت / دریافت</button>@endif
            @if(\App\Support\AccountStatementAccess::readOnly(auth()->user()))<span class="badge bg-light text-secondary">فقط مشاهده</span>@endif
            <a href="{{ route('account-statements.index') }}" class="btn asd-secondary">بازگشت</a>
        </div>
    </header>
    @if($person['link_warning'] ?? null)<div class="alert alert-warning">{{ $person['link_warning'] }}</div>@endif
    @if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    @include('account-statements.partials.summary')
    <section class="asd-book">
        <div class="asd-book-heading"><h2>گردش حساب</h2><span>بر اساس تاریخ سند، جدیدترین ابتدا · مبالغ به ریال</span></div>
        <div class="asd-table-wrap">
            <table class="asd-table">
                <thead><tr><th>تاریخ سند</th><th>شماره سند</th><th>شرح</th><th>بدهکار</th><th>بستانکار</th><th>مانده پس از سند</th></tr></thead>
                <tbody>
                    @forelse($ledgers as $ledger)
                        @php
                            $source = $sources[$ledger->ledger_key] ?? [];
                            $rowBalance = $ledgerBalances[$ledger->ledger_key] ?? $netBalance;
                            $description = $source['description'] ?? ($ledger->note ?: ($ledger->type === 'debit' ? 'ثبت بدهکار' : 'ثبت بستانکار'));
                            $effective = (int) $ledger->is_effective === 1;
                        @endphp
                        <tr class="asd-entry {{ !$effective ? 'asd-ineffective' : ($ledger->type === 'debit' ? 'asd-entry-debit' : 'asd-entry-credit') }}" data-ledger-key="{{ $ledger->ledger_key }}">
                            <td class="asd-date" data-label="تاریخ سند"><span>{{ $ledger->statement_date !== '1970-01-01' ? \App\Support\JalaliDate::date($ledger->statement_date) : '—' }}</span><small>{{ \App\Support\JalaliDate::format($ledger->created_at, 'H:i') }}</small></td>
                            <td class="asd-reference" data-label="شماره سند">
                                @if(!empty($source['url']))<a href="{{ $source['url'] }}" aria-label="مشاهده سند {{ $source['label'] }}">{{ $source['label'] }} ↗</a>
                                @else<span>{{ $source['label'] ?? 'GL-'.$ledger->id }}</span>@endif
                            </td>
                            <td class="asd-description" data-label="شرح">
                                <span>{{ $description }}</span>
                                @if($ledger->note && $ledger->note !== $description)<small>{{ $ledger->note }}</small>@endif
                                @if(!$effective)<small class="asd-excluded">بدون اثر در مانده</small>@endif
                            </td>
                            <td class="asd-money" data-label="بدهکار">{{ $ledger->type === 'debit' ? $rial($ledger->amount) : '—' }}</td>
                            <td class="asd-money" data-label="بستانکار">{{ $ledger->type === 'credit' ? $rial($ledger->amount) : '—' }}</td>
                            <td class="asd-row-balance {{ $rowBalance > 0 ? 'asd-debt' : ($rowBalance < 0 ? 'asd-credit' : '') }}" data-label="مانده پس از سند"><strong>{{ $rial($rowBalance) }}</strong><small>{{ $balanceText($rowBalance) }}</small></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="asd-empty">گردشی برای این شخص ثبت نشده است.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <footer class="asd-book-footer"><span>{{ number_format($ledgers->total()) }} ثبت</span>{{ $ledgers->links() }}</footer>
    </section>
</div>
@if($canAddPayment)@include('persons.partials.supplier-payment-modal')@endif
@endsection
