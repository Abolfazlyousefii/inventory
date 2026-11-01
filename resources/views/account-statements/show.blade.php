@extends('layouts.app')
@section('title', 'گردش حساب '.($customer->display_name ?: 'شخص'))
@push('styles')
<link rel="stylesheet" href="{{ asset('css/account-statement-document.css') }}?v=20261015-payment-actions">
@endpush
@section('content')
@php
    $rial = fn ($value) => \App\Support\Currency::formatRial(abs((int) $value));
    $financeActor = auth()->user();
    $canAddPayment = $financeActor && \App\Support\PageAccessCatalog::userCanRoute($financeActor, 'account-statements.payments.store');
    $canManagePayments = $financeActor && ($financeActor->hasAnyRole(['admin','Admin','Manager','manager','finance','Accountant']) || $financeActor->can('finance.approve')) && \App\Support\PageAccessCatalog::userCanRoute($financeActor, 'account-statements.payments.edit');
    $balanceText = fn ($value) => $value > 0 ? 'بدهکار' : ($value < 0 ? 'بستانکار' : 'تسویه');
@endphp
<div class="as-document">
    <header class="asd-toolbar">
        <div><span class="asd-eyebrow">دفتر حساب شخص</span><h1>{{ $customer->display_name ?: 'شخص' }}</h1><div class="asd-identity"><span>کد: {{ $customer->crm_customer_id ?: $customer->id }}</span><span dir="ltr">{{ $customer->mobile ?: '—' }}</span></div></div>
        <div class="asd-actions">
            @if($canAddPayment)<button type="button" class="btn asd-primary" id="openPaymentModalBtn" data-bs-toggle="modal" data-bs-target="#paymentModal">+ افزودن پرداخت</button>@endif
            @if($financeActor && \App\Support\AccountStatementAccess::readOnly($financeActor))<span class="badge bg-light text-secondary">فقط مشاهده</span>@endif
            @if($canAdjustBalance)<button type="button" class="btn asd-secondary" data-bs-toggle="modal" data-bs-target="#balanceAdjustmentModal">اصلاح مانده</button>@endif
            <a href="{{ route('account-statements.index') }}" class="btn asd-secondary">بازگشت</a>
        </div>
    </header>
    @if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    <section class="asd-summary" aria-label="خلاصه حساب">
        <div><span>ماندهٔ اولیه</span><strong>{{ $rial($statementTotals['opening_balance']) }}</strong><small>{{ $balanceText($statementTotals['opening_balance']) }}</small></div>
        <div class="asd-summary-debt"><span>جمع بدهکار</span><strong>{{ $rial($totalDebit) }}</strong><small>کل گردش مؤثر</small></div>
        <div class="asd-summary-credit"><span>جمع بستانکار</span><strong>{{ $rial($totalCredit) }}</strong><small>کل گردش مؤثر</small></div>
        <div class="asd-current {{ $netBalance > 0 ? 'asd-debt' : ($netBalance < 0 ? 'asd-credit' : '') }}"><span>ماندهٔ فعلی حساب</span><strong>{{ $rial($netBalance) }}</strong><small>{{ $balanceText($netBalance) }}</small></div>
    </section>
    <section class="asd-book">
        <div class="asd-book-heading"><h2>گردش حساب</h2><span>بر اساس تاریخ سند، جدیدترین ابتدا · مبالغ به ریال</span></div>
        <div class="asd-table-wrap">
            <table class="asd-table">
                <thead><tr><th>تاریخ سند</th><th>شماره سند</th><th>شرح</th><th>بدهکار</th><th>بستانکار</th><th>مانده پس از سند</th>@if($canManagePayments)<th class="asd-operations-heading"><span class="visually-hidden">عملیات پرداخت</span></th>@endif</tr></thead>
                <tbody>
                @forelse($ledgers as $ledger)
                    @php
                        $invoice = $ledger->reference_type === \App\Models\Invoice::class ? ($invoices[$ledger->reference_id] ?? null) : null;
                        $payment = $ledger->reference_type === \App\Models\InvoicePayment::class ? ($payments[$ledger->reference_id] ?? null) : null;
                        $transfer = $ledger->reference_type === \App\Models\WarehouseTransfer::class ? ($transfers[$ledger->reference_id] ?? null) : null;
                        $salesReturn = $ledger->reference_type === \App\Models\SalesReturnDocument::class ? ($salesReturnDocuments[$ledger->reference_id] ?? null) : null;
                        $effective = $effectiveLedgerIds->has($ledger->id);
                        $rowBalance = $ledgerBalances[$ledger->id] ?? $netBalance;
                        $reference = 'GL-'.$ledger->id;
                        $description = $ledger->note ?: ($ledger->type === 'debit' ? 'ثبت بدهکار' : 'ثبت بستانکار');
                        $url = null;
                        if ($invoice) {
                            $reference = $invoice->uuid;
                            $description = 'فاکتور فروش';
                            $url = route(\App\Support\AccountStatementAccess::readOnly(auth()->user())
                                ? 'account-statements.documents.invoices.show' : 'vouchers.sales.show', $invoice->uuid);
                            $invoiceCustomerName = trim((string) ($invoice->customer_name ?? ''));
                            if ($invoiceCustomerName === '') {
                                $invoiceCustomerName = trim((string) ($invoice->customer?->display_name ?? ''));
                            }
                        } elseif ($payment) {
                            $reference = 'PAY-'.$payment->id;
                            $description = match($payment->method) {'cash'=>'دریافت نقدی', 'cheque'=>'دریافت چک', 'online'=>'دریافت آنلاین', 'card'=>'دریافت کارت', 'bank_transfer'=>'دریافت حواله', default=>'دریافت وجه'};
                            $invoiceUuid = $payment->invoice?->uuid ?: ($invoices[$payment->invoice_id]->uuid ?? null);
                            if ($invoiceUuid) $description .= ' · فاکتور '.$invoiceUuid;
                            if ($payment->method === 'cheque' && $payment->cheque?->cheque_number) $description .= ' · چک '.$payment->cheque->cheque_number;
                            $url = route('account-statements.documents.payments.show', $payment->id);
                        } elseif ($salesReturn) {
                            $reference = $salesReturn->document_number;
                            $description = 'برگشت از فروش';
                            $url = route('sales-returns.show', $salesReturn->id);
                        } elseif ($transfer) {
                            $reference = $transfer->reference ?: 'TR-'.$transfer->id;
                            $description = \App\Models\WarehouseTransfer::typeOptions()[$transfer->voucher_type] ?? 'سند انتقال';
                            if ($transfer->voucher_type === \App\Models\WarehouseTransfer::TYPE_CUSTOMER_RETURN) $url = route('account-statements.documents.returns.show', $transfer->id);
                        }
                    @endphp
                    <tr class="asd-entry {{ !$effective ? 'asd-ineffective' : ($ledger->type === 'debit' ? 'asd-entry-debit' : 'asd-entry-credit') }}" data-ledger-id="{{ $ledger->id }}">
                        @include('account-statements.partials.entry-date', ['ledger' => $ledger, 'payment' => $payment])
                        <td class="asd-reference" data-label="شماره سند">
                            @if($invoice)
                                <a href="{{ $url }}" class="asd-invoice-link" title="مشاهده فاکتور {{ $reference }}" aria-label="مشاهده فاکتور {{ $reference }}">
                                    <span>{{ $reference }}</span>
                                    <span class="asd-invoice-eye" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12Z"/>
                                            <circle cx="12" cy="12" r="3"/>
                                        </svg>
                                    </span>
                                </a>
                            @elseif($url)
                                <a href="{{ $url }}" aria-label="مشاهده سند {{ $reference }}">{{ $reference }} ↗</a>
                            @else
                                <span>{{ $reference }}</span>
                            @endif
                        </td>
                        <td class="asd-description" data-label="شرح">
                            <span>{{ $description }}</span>
                            @if($invoice)
                                <small class="asd-invoice-customer">مشتری فاکتور: {{ $invoiceCustomerName !== '' ? $invoiceCustomerName : 'نام مشتری ثبت نشده' }}</small>
                            @endif
                            @if(!$effective)<small class="asd-excluded">بدون اثر در مانده</small>@endif
                        </td>
                        <td class="asd-money" data-label="بدهکار">{{ $ledger->type === 'debit' ? $rial($ledger->amount) : '—' }}</td>
                        <td class="asd-money" data-label="بستانکار">{{ $ledger->type === 'credit' ? $rial($ledger->amount) : '—' }}</td>
                        <td class="asd-row-balance {{ $rowBalance > 0 ? 'asd-debt' : ($rowBalance < 0 ? 'asd-credit' : '') }}" data-label="مانده پس از سند"><strong>{{ $rial($rowBalance) }}</strong><small>{{ $balanceText($rowBalance) }}</small></td>
                        @if($canManagePayments)<td class="asd-operations" data-label="عملیات">@if($payment) @include('payments.manage-link', ['payment' => $payment, 'compactPaymentLink' => true]) @endif</td>@endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canManagePayments ? 7 : 6 }}" class="asd-empty">گردشی برای این شخص ثبت نشده است.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <footer class="asd-book-footer"><span>{{ number_format($ledgers->total()) }} ثبت</span>{{ $ledgers->links() }}</footer>
    </section>
    @include('account-statements.partials.balance-adjustment', ['collapseBalanceHistory' => true])
</div>
@if($canAddPayment)@include('payments.entry-modal', ['paymentModalId' => 'paymentModal', 'paymentAction' => route('account-statements.payments.store', $customer->id), 'paymentInvoiceOptions' => $paymentInvoiceOptions])@endif
@endsection

@push('scripts')<script src="{{ asset('js/account-statement-display.js') }}?v=20261011" defer></script>@endpush
