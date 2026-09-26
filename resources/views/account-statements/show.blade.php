@extends('layouts.app')

@php
    use Morilog\Jalali\Jalalian;
    $persianDigits = static fn ($value) => str_replace(
        ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
        (string) $value
    );
    $canEditPayments = auth()->user()?->hasAnyRole(['admin', 'Admin', 'Manager', 'manager', 'finance', 'Accountant'])
        || auth()->user()?->can('finance.approve');
@endphp

@section('content')
<style>
    .account-statement-page{
        --as-navy: #123756;
        --as-blue: #eaf4f9;
        --as-line: #dce8ef;
        --as-green: #128269;
        color: #173650;
    }

    .account-statement-page .page-toolbar{
        background: linear-gradient(110deg, #f5fbfe, #edf7f5);
        border: 1px solid var(--as-line);
        border-radius: 16px;
        padding: 18px 20px;
    }

    .account-statement-page .page-toolbar h4,
    .account-statement-page .card-header{
        color: var(--as-navy);
        font-weight: 800;
    }

    .account-statement-page .card{
        border: 1px solid var(--as-line);
        border-radius: 16px;
        box-shadow: 0 5px 18px rgba(18, 55, 86, .045);
        overflow: hidden;
    }

    .account-statement-page .card-header{
        padding: 14px 18px;
        background: #f6fafc !important;
        border-bottom: 1px solid var(--as-line);
    }

    .account-statement-page .customer-summary .card-body{ padding: 20px; }
    .account-statement-page .customer-summary .badge{
        color: var(--as-navy);
        background: #f3f8fb !important;
        border-color: var(--as-line) !important;
        font-weight: 600;
        line-height: 1.7;
    }

    .account-statement-page .balance-panel{
        border-radius: 13px;
        padding: 16px 18px !important;
        min-height: 100%;
    }

    .account-statement-page .balance-panel .fs-5{ line-height: 1.8; }
    .account-statement-page .balance-panel summary{ padding-block: 5px; }
    .account-statement-page .balance-panel details[open]{
        border-top: 1px solid rgba(18, 55, 86, .12);
        padding-top: 8px;
    }

    .account-statement-page .ledger-table thead th{
        background: var(--as-blue);
        color: var(--as-navy);
        border-bottom: 1px solid #cbdfe9;
        padding: 13px 16px;
        font-weight: 700;
        white-space: nowrap;
    }

    .account-statement-page .ledger-table tbody td{
        padding: 12px 16px;
        border-color: #edf2f6;
        line-height: 1.75;
    }

    .account-statement-page .ledger-table tbody tr:hover{ background: #f8fcfd; }
    .account-statement-page .ledger-table .ledger-amount{ font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .account-statement-page .ledger-table .ledger-debit{ color: #a33f47; }
    .account-statement-page .ledger-table .ledger-credit{ color: #08765e; }

    .account-statement-page .btn-success,
    .payment-modal .btn-success{
        background: var(--as-green, #128269);
        border-color: var(--as-green, #128269);
        border-radius: 9px;
        font-weight: 700;
    }

    .account-statement-page .btn-success:hover,
    .payment-modal .btn-success:hover{ background: #0d6b58; border-color: #0d6b58; }
    .account-statement-page .btn-primary{ background: var(--as-navy); border-color: var(--as-navy); border-radius: 8px; }
    .account-statement-page .btn-outline-secondary{ border-color: #bed1dd; color: var(--as-navy); border-radius: 9px; }

    .payment-modal-backdrop{
        position: fixed;
        inset: 0;
        background: rgba(10, 32, 52, .58);
        opacity: 0;
        visibility: hidden;
        transition: .2s ease;
        z-index: 1050;
    }

    .payment-modal{
        position: fixed;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        opacity: 0;
        visibility: hidden;
        transition: .2s ease;
        z-index: 1060;
    }

    .payment-modal.is-open,
    .payment-modal-backdrop.is-open{
        opacity: 1;
        visibility: visible;
    }

    .payment-modal-dialog{
        width: 100%;
        max-width: 900px;
        max-height: calc(100vh - 32px);
        overflow: hidden;
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 24px 70px rgba(7, 31, 51, .24);
        transform: translateY(16px) scale(.98);
        transition: .2s ease;
    }

    .payment-modal.is-open .payment-modal-dialog{
        transform: translateY(0) scale(1);
    }

    .payment-modal-header{
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 17px 22px;
        border-bottom: 1px solid #dce8ef;
        border-right: 5px solid #128269;
        background: #f3f9fc;
    }

    .payment-modal-title{
        font-size: 18px;
        font-weight: 800;
        margin: 0;
        color: #123756;
    }

    .payment-modal-subtitle{
        color: #64748b;
        font-size: 13px;
        margin-top: 4px;
    }

    .payment-modal-close{
        border: 0;
        background: #fff;
        width: 42px;
        height: 42px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-shadow: inset 0 0 0 1px #e2e8f0;
        cursor: pointer;
        font-size: 20px;
        line-height: 1;
    }

    .payment-modal-body{
        padding: 20px 22px 0;
        max-height: calc(100vh - 125px);
        overflow-y: auto;
    }

    .payment-modal .form-label{ color: #31536c; font-size: 12px; font-weight: 700; margin-bottom: 6px; }
    .payment-modal .form-control,
    .payment-modal .form-select{
        min-height: 42px;
        border: 1px solid #cbdce7;
        border-radius: 9px;
        background-color: #fff;
        color: #173650;
    }
    .payment-modal .form-control:focus,
    .payment-modal .form-select:focus{
        border-color: #5c9fbb;
        box-shadow: 0 0 0 3px rgba(66, 153, 188, .13);
    }
    .payment-modal textarea.form-control{ min-height: 72px; }
    .payment-modal .as-cheque-fields{
        background: #f6fafc;
        border: 1px solid #e0edf3;
        border-radius: 12px;
        padding: 16px;
    }
    .payment-modal .payment-form-actions{
        position: sticky;
        bottom: 0;
        z-index: 2;
        margin-top: 4px;
        padding: 14px 0 16px;
        background: #fff;
        border-top: 1px solid #e4edf2;
    }
    .payment-modal .payment-form-actions .btn{ min-width: 104px; min-height: 40px; }
    .payment-modal-close{ color: #31536c; }
    .payment-modal-close:hover{ background: #e5f1f6; }

    .quick-actions-card{
        border: 0;
        box-shadow: 0 .125rem .25rem rgba(0,0,0,.075);
    }

    .page-toolbar{
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 1rem;
    }

    @media (max-width: 767.98px){
        .payment-modal{
            padding: 10px;
            align-items: flex-end;
        }

        .payment-modal-dialog{
            max-width: 100%;
            max-height: calc(100vh - 20px);
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
        }

        .payment-modal-body{
            max-height: calc(100vh - 120px);
        }
    }
</style>

<div class="account-statement-page">
<div class="page-toolbar">
    <div>
        <h4 class="mb-0">📑 گردش حساب {{ $customer->display_name ?: 'شخص' }}</h4>
        <div class="text-muted small">جزئیات کامل تراکنش‌ها، فاکتورها و اسناد مالی مشتری</div>
    </div>

    <div class="d-flex align-items-center gap-2 flex-wrap">
        <button type="button" class="btn btn-success" id="openPaymentModalBtn">
            ➕ افزودن پرداخت
        </button>
        <a href="{{ route('account-statements.index') }}" class="btn btn-outline-secondary">
            بازگشت به لیست اشخاص
        </a>
    </div>
</div>

@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card customer-summary mb-3">
    <div class="card-body">
        <div class="row g-3 align-items-center">
            <div class="col-12 col-lg-6">
                <h6 class="fw-bold mb-2">اطلاعات مشتری</h6>
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge text-bg-light border px-3 py-2">👤 {{ $customer->display_name ?: '—' }}</span>
                    <span class="badge text-bg-light border px-3 py-2">📱 {{ $persianDigits($customer->mobile ?: '—') }}</span>
                    <span class="badge text-bg-light border px-3 py-2 text-wrap">📍 {{ $customer->address ?: 'آدرس ثبت نشده' }}</span>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="balance-panel border {{ $netBalance > 0 ? 'border-danger-subtle bg-danger-subtle' : ($netBalance < 0 ? 'border-success-subtle bg-success-subtle' : 'border-secondary-subtle bg-light') }}">
                    <div class="text-muted small mb-1">وضعیت نهایی حساب</div>
                    <div class="fs-5 fw-bold {{ $netBalance > 0 ? 'text-danger' : ($netBalance < 0 ? 'text-success' : 'text-muted') }}">
                        {{ $netBalance > 0 ? 'بدهکار' : ($netBalance < 0 ? 'بستانکار' : 'تسویه') }}
                        {{ $netBalance === 0 ? '' : $persianDigits(\App\Support\Currency::formatRial(abs($netBalance))) }}
                    </div>
                    <div class="small text-muted mt-2">مانده اولیه: {{ $persianDigits(number_format((int) $customer->opening_balance)) }} | بدهکار: {{ $persianDigits(number_format($totalDebit)) }} | بستانکار: {{ $persianDigits(number_format($totalCredit)) }} ریال</div>
                    <details class="mt-2">
                        <summary class="small fw-semibold" style="cursor:pointer">اصلاح مانده حساب</summary>
                        <form method="POST" action="{{ route('account-statements.adjustments.store', $customer) }}" class="mt-3">
                            @csrf
                            <input type="hidden" name="expected_balance" value="{{ $netBalance }}">
                            <div class="mb-2">
                                <label for="balance_type" class="form-label small">وضعیت درست حساب</label>
                                <select id="balance_type" name="balance_type" class="form-select form-select-sm" required>
                                    <option value="debit" @selected(old('balance_type', $netBalance > 0 ? 'debit' : ($netBalance < 0 ? 'credit' : 'settled')) === 'debit')>بدهکار</option>
                                    <option value="credit" @selected(old('balance_type', $netBalance > 0 ? 'debit' : ($netBalance < 0 ? 'credit' : 'settled')) === 'credit')>بستانکار</option>
                                    <option value="settled" @selected(old('balance_type', $netBalance > 0 ? 'debit' : ($netBalance < 0 ? 'credit' : 'settled')) === 'settled')>تسویه</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label for="target_amount" class="form-label small">مبلغ ماندهٔ درست (ریال)</label>
                                <input id="target_amount" name="target_amount" class="form-control form-control-sm" inputmode="numeric" value="{{ $persianDigits(old('target_amount', abs($netBalance))) }}" required>
                            </div>
                            <div class="mb-2">
                                <label for="adjustment_reason" class="form-label small">دلیل اصلاح</label>
                                <textarea id="adjustment_reason" name="reason" class="form-control form-control-sm" rows="2" minlength="10" maxlength="1000" required>{{ old('reason') }}</textarea>
                            </div>
                            <div class="small text-muted mb-2">فقط اختلاف مبلغ به‌صورت سند جدید ثبت می‌شود؛ فاکتورها و پرداخت‌های قبلی تغییر نمی‌کنند.</div>
                            <button type="submit" class="btn btn-sm btn-outline-primary">ثبت سند اصلاحی</button>
                        </form>
                        <script>
                            document.getElementById('balance_type')?.addEventListener('change', function () {
                                if (this.value === 'settled') document.getElementById('target_amount').value = '۰';
                            });
                        </script>
                    </details>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card quick-actions-card mb-3">
    <div class="card-header bg-white fw-bold">سابقه اصلاح مانده</div>
    <div class="card-body">
        @forelse($adjustments as $adjustment)
            @php
                $properties = $adjustment->properties ?? [];
            @endphp
            <div class="border-bottom py-2">
                <div class="d-flex justify-content-between flex-wrap gap-2 small">
                    <strong>{{ $adjustment->user?->name ?: 'کاربر نامشخص' }}</strong>
                    <span class="text-muted">{{ $adjustment->occurred_at ? $persianDigits(Jalalian::fromDateTime($adjustment->occurred_at)->format('Y/m/d H:i')) : '—' }}</span>
                </div>
                <div class="small mt-1">از {{ ($properties['balance_before'] ?? 0) > 0 ? 'بدهکار' : (($properties['balance_before'] ?? 0) < 0 ? 'بستانکار' : 'تسویه') }} {{ $persianDigits(\App\Support\Currency::formatRial(abs($properties['balance_before'] ?? 0))) }}
                    به {{ ($properties['balance_after'] ?? 0) > 0 ? 'بدهکار' : (($properties['balance_after'] ?? 0) < 0 ? 'بستانکار' : 'تسویه') }} {{ $persianDigits(\App\Support\Currency::formatRial(abs($properties['balance_after'] ?? 0))) }}</div>
                <div class="small text-muted">دلیل: {{ $persianDigits($properties['reason'] ?? '—') }} | سند #{{ $persianDigits($properties['ledger_id'] ?? '—') }}</div>
            </div>
        @empty
            <div class="text-muted small">هنوز اصلاحی ثبت نشده است.</div>
        @endforelse
        {{ $adjustments->links() }}
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white">لیست گردش‌ها</div>
    <div class="table-responsive">
        <table class="table ledger-table align-middle mb-0">
            <thead>
                <tr>
                    <th>تاریخ</th>
                    <th>شرح</th>
                    <th>بدهکار</th>
                    <th>بستانکار</th>
                    <th class="text-end">عملیات</th>
                </tr>
            </thead>
            <tbody>
                @forelse($ledgers as $ledger)
                    @php
                        $invoice = $ledger->reference_type === \App\Models\Invoice::class ? ($invoices[$ledger->reference_id] ?? null) : null;
                        $payment = $ledger->reference_type === \App\Models\InvoicePayment::class ? ($payments[$ledger->reference_id] ?? null) : null;
                        $transfer = $ledger->reference_type === \App\Models\WarehouseTransfer::class ? ($transfers[$ledger->reference_id] ?? null) : null;
                        $salesReturn = $ledger->reference_type === \App\Models\SalesReturnDocument::class ? ($salesReturnDocuments[$ledger->reference_id] ?? null) : null;
                        $description = $ledger->note ?: '—';
                        $viewUrl = null;

                        if ($invoice) {
                            $description = "فاکتور #{$invoice->id} | مبلغ فاکتور ".\App\Support\Currency::formatRial($invoice->total)." | این شخص بدهکار شد";
                            $viewUrl = route('vouchers.sales.show', $invoice->uuid);
                        }

                        if ($payment) {
                            $creatorName = $payment->creator?->name ?: 'نامشخص';
                            $invoiceUuid = $payment->invoice?->uuid ?: ($invoices[$payment->invoice_id]->uuid ?? null);

                            if ($payment->method === 'cheque') {
                                $cheque = $payment->cheque;
                                $chNumber = $cheque?->cheque_number ?: '—';
                                $description = "پرداخت چکی شماره {$chNumber} | مبلغ ".\App\Support\Currency::formatRial($payment->amount)." | ثبت‌کننده: {$creatorName}";
                            } else {
                                $bankName = $payment->bank_name ?: '—';
                                $description = "پرداخت نقدی | مبلغ ".\App\Support\Currency::formatRial($payment->amount)." | بانک {$bankName} | ثبت‌کننده: {$creatorName}";
                            }

                            if ($invoiceUuid) {
                                $description .= " | فاکتور {$invoiceUuid}";
                            }

                            $viewUrl = route('account-statements.documents.payments.show', $payment->id);
                        }

                        if ($salesReturn) {
                            $source = \App\Models\SalesReturnDocument::sourceTypeLabels()[$salesReturn->source_type] ?? $salesReturn->source_type;
                            $description = "سند برگشت از فروش {$salesReturn->document_number} | نوع: {$source} | مبلغ " . \App\Support\Currency::formatRial($salesReturn->total_refund_amount);
                            $viewUrl = route('sales-returns.show', $salesReturn->id);
                        }

                        if ($transfer) {
                            $transferRef = $transfer->reference ?: ('TR-' . $transfer->id);
                            $transferTypeLabel = \App\Models\WarehouseTransfer::typeOptions()[$transfer->voucher_type] ?? $transfer->voucher_type;
                            $description = "سند {$transferRef} | نوع: {$transferTypeLabel} | مبلغ " . \App\Support\Currency::formatRial($ledger->amount);

                            if ($transfer->voucher_type === \App\Models\WarehouseTransfer::TYPE_CUSTOMER_RETURN) {
                                $viewUrl = route('account-statements.documents.returns.show', $transfer->id);
                            }
                        }
                    @endphp
                    <tr class="{{ $effectiveLedgerIds->has($ledger->id) ? '' : 'text-muted' }}">
                        <td class="text-nowrap">{{ $ledger->created_at ? $persianDigits(Jalalian::fromDateTime($ledger->created_at)->format('Y/m/d H:i')) : '—' }}</td>
                        <td>{{ $persianDigits($description) }} @unless($effectiveLedgerIds->has($ledger->id))<span class="badge text-bg-secondary">در مانده محاسبه نمی‌شود</span>@endunless</td>
                        <td class="ledger-amount ledger-debit">{{ $ledger->type === 'debit' ? $persianDigits(number_format((int) $ledger->amount)) : '—' }}</td>
                        <td class="ledger-amount ledger-credit">{{ $ledger->type === 'credit' ? $persianDigits(number_format((int) $ledger->amount)) : '—' }}</td>
                        <td class="text-end text-nowrap">
                            @if($viewUrl)
                                <a href="{{ $viewUrl }}" class="btn btn-sm btn-primary">مشاهده</a>
                                @if($payment && $canEditPayments && (int) $payment->customer_id === (int) $customer->id)
                                    @php
                                        $editPayment = [
                                            'id' => $payment->id,
                                            'invoice_id' => $payment->invoice_id,
                                            'method' => $payment->method,
                                            'amount' => $payment->amount,
                                            'paid_at' => $payment->paid_at,
                                            'bank_name' => $payment->bank_name,
                                            'note' => $payment->note,
                                            'cheque_number' => $payment->cheque?->cheque_number,
                                            'cheque_bank_name' => $payment->cheque?->bank_name,
                                            'cheque_branch_name' => $payment->cheque?->branch_name,
                                            'cheque_due_date' => $payment->cheque?->due_date?->toDateString(),
                                            'cheque_received_at' => $payment->cheque?->received_at?->toDateString(),
                                            'cheque_customer_name' => $payment->cheque?->customer_name,
                                            'cheque_account_number' => $payment->cheque?->account_number,
                                            'cheque_account_holder' => $payment->cheque?->account_holder,
                                            'cheque_status' => $payment->cheque?->status,
                                        ];
                                    @endphp
                                    <button type="button" class="btn btn-sm btn-outline-primary edit-payment-btn" data-payment="{{ json_encode($editPayment) }}" data-update-url="{{ route('account-statements.payments.update', [$customer, $payment]) }}">ویرایش</button>
                                    <button type="button" class="btn btn-sm btn-outline-danger delete-payment-btn" data-delete-url="{{ route('account-statements.payments.destroy', [$customer, $payment]) }}" data-payment-label="{{ $persianDigits(number_format((int) $payment->amount)) }} ریال">حذف</button>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center py-4 text-muted">گردشی برای این شخص ثبت نشده است.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="card-footer bg-white">{{ $ledgers->links() }}</div>
</div>
</div>

<div class="payment-modal-backdrop" id="paymentModalBackdrop"></div>

<div class="payment-modal" id="paymentModal" aria-hidden="true">
    <div class="payment-modal-dialog">
        <div class="payment-modal-header">
            <div>
                <h5 class="payment-modal-title" id="paymentModalTitle">➕ افزودن پرداخت</h5>
                <div class="payment-modal-subtitle">ثبت پرداخت نقدی یا چکی برای {{ $customer->display_name ?: 'این مشتری' }}</div>
            </div>

            <button type="button" class="payment-modal-close" id="closePaymentModalBtn" aria-label="بستن">
                ×
            </button>
        </div>

        <div class="payment-modal-body">
            <form method="POST" action="{{ route('account-statements.payments.store', $customer->id) }}" data-store-url="{{ route('account-statements.payments.store', $customer->id) }}" id="accountStatementPaymentForm">
                @csrf
                <input type="hidden" name="_method" id="paymentFormMethod" value="PUT" disabled>
                <input type="hidden" name="editing_payment_id" id="editingPaymentId" disabled>

                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">نوع پرداخت</label>
                        <select name="method" id="as_payment_method" class="form-select" required>
                            <option value="cash" @selected(old('method', 'cash') === 'cash')>نقدی</option>
                            <option value="cheque" @selected(old('method') === 'cheque')>چکی</option>
                        </select>
                    </div>

                    <div class="col-md-5">
                        <label class="form-label">فاکتور مرتبط</label>
                        <select name="invoice_id" class="form-select" required>
                            <option value="">انتخاب فاکتور</option>
                            @foreach($customerInvoices as $invoiceOption)
                                <option value="{{ $invoiceOption->id }}" @selected((string) old('invoice_id') === (string) $invoiceOption->id)>
                                    {{ $persianDigits($invoiceOption->uuid) }} | {{ $persianDigits(\App\Support\Currency::formatRial($invoiceOption->total)) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">مبلغ</label>
                        <input
                            type="text"
                            inputmode="numeric"
                            autocomplete="off"
                            class="form-control"
                            name="amount"
                            value="{{ $persianDigits(old('amount')) }}"
                            placeholder="مبلغ پرداخت"
                            required
                        >
                    </div>

                    <div class="col-md-6 as-cash-fields">
                        <label class="form-label">تاریخ پرداخت</label>
                        <input
                            type="date"
                            class="form-control"
                            name="paid_at"
                            value="{{ old('paid_at', now()->toDateString()) }}"
                            required
                        >
                    </div>

                    <div class="col-md-6 as-cash-fields">
                        <label class="form-label">اسم بانک</label>
                        <input
                            type="text"
                            class="form-control"
                            name="bank_name"
                            value="{{ old('bank_name') }}"
                            placeholder="مثال: ملی"
                        >
                    </div>

                    <div class="col-12 as-cheque-fields d-none">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">شماره چک</label>
                                <input type="text" class="form-control" name="cheque_number" value="{{ $persianDigits(old('cheque_number')) }}">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">نام بانک</label>
                                <input type="text" class="form-control" name="cheque_bank_name" value="{{ old('cheque_bank_name') }}">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">نام شعبه</label>
                                <input type="text" class="form-control" name="cheque_branch_name" value="{{ old('cheque_branch_name') }}">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">تاریخ سررسید</label>
                                <input type="date" class="form-control" name="cheque_due_date" value="{{ old('cheque_due_date') }}">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">تاریخ دریافت چک</label>
                                <input type="date" class="form-control" name="cheque_received_at" value="{{ old('cheque_received_at') }}">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">نام مشتری</label>
                                <input type="text" class="form-control" name="cheque_customer_name" value="{{ $persianDigits(old('cheque_customer_name', $customer->display_name)) }}">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">شماره حساب/شبا</label>
                                <input type="text" class="form-control" name="cheque_account_number" value="{{ $persianDigits(old('cheque_account_number')) }}">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">صاحب حساب</label>
                                <input type="text" class="form-control" name="cheque_account_holder" value="{{ old('cheque_account_holder') }}">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">وضعیت چک</label>
                                <select name="cheque_status" class="form-select">
                                    <option value="unregistered" @selected(old('cheque_status', 'unregistered') === 'unregistered')>ثبت‌نشده</option>
                                    <option value="registered" @selected(old('cheque_status') === 'registered')>ثبت‌شده</option>
                                </select>
                            </div>

                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">یادداشت</label>
                        <textarea name="note" class="form-control" rows="2" placeholder="اختیاری">{{ old('note') }}</textarea>
                    </div>

                    <div class="col-12 d-none" id="paymentEditReasonWrap">
                        <label class="form-label">دلیل ویرایش</label>
                        <textarea name="reason" class="form-control" rows="2" minlength="5" maxlength="1000" placeholder="دلیل اصلاح پرداخت را بنویسید" disabled></textarea>
                    </div>

                    <div class="col-12 payment-form-actions d-flex justify-content-end gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-secondary" id="cancelPaymentModalBtn">انصراف</button>
                        <button type="submit" class="btn btn-success" id="paymentSubmitButton">ثبت پرداخت</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="payment-modal-backdrop" id="deletePaymentBackdrop"></div>
<div class="payment-modal" id="deletePaymentModal" aria-hidden="true">
    <div class="payment-modal-dialog" style="max-width: 480px">
        <div class="payment-modal-header">
            <div>
                <h5 class="payment-modal-title">حذف پرداخت</h5>
                <div class="payment-modal-subtitle" id="deletePaymentLabel"></div>
            </div>
            <button type="button" class="payment-modal-close" id="closeDeletePaymentBtn" aria-label="بستن">×</button>
        </div>
        <div class="payment-modal-body">
            <form method="POST" id="deletePaymentForm">
                @csrf
                @method('DELETE')
                <label for="deletePaymentReason" class="form-label">دلیل حذف</label>
                <textarea id="deletePaymentReason" name="reason" class="form-control" rows="2" minlength="5" maxlength="1000" required></textarea>
                <div class="small text-muted mt-2">پرداخت و ردیف بستانکار مربوط حذف می‌شوند و جزئیات در سابقه فعالیت باقی می‌ماند.</div>
                <div class="payment-form-actions d-flex justify-content-end gap-2 mt-3">
                    <button type="button" class="btn btn-outline-secondary" id="cancelDeletePaymentBtn">انصراف</button>
                    <button type="submit" class="btn btn-danger">حذف پرداخت</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    (function () {
        const body = document.body;
        const modal = document.getElementById('paymentModal');
        const backdrop = document.getElementById('paymentModalBackdrop');
        const openButton = document.getElementById('openPaymentModalBtn');
        const closeButton = document.getElementById('closePaymentModalBtn');
        const cancelButton = document.getElementById('cancelPaymentModalBtn');
        const methodSelect = document.getElementById('as_payment_method');

        if (!modal || !backdrop) return;

        function openModal() {
            modal.classList.add('is-open');
            backdrop.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            body.classList.add('overflow-hidden');
        }

        function closeModal() {
            modal.classList.remove('is-open');
            backdrop.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            body.classList.remove('overflow-hidden');
        }

        closeButton && closeButton.addEventListener('click', closeModal);
        cancelButton && cancelButton.addEventListener('click', closeModal);
        backdrop.addEventListener('click', closeModal);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('is-open')) {
                closeModal();
            }
        });

        if (methodSelect) {
            const chequeBlocks = document.querySelectorAll('.as-cheque-fields');
            const cashBlocks = document.querySelectorAll('.as-cash-fields');

            const toggleMethodFields = () => {
                const isCheque = methodSelect.value === 'cheque';

                chequeBlocks.forEach((item) => item.classList.toggle('d-none', !isCheque));
                cashBlocks.forEach((item) => item.classList.toggle('d-none', isCheque));
                const paidAt = document.querySelector('#accountStatementPaymentForm [name="paid_at"]');
                if (paidAt) { paidAt.required = !isCheque; paidAt.disabled = isCheque; }
                for (const name of ['cheque_due_date', 'cheque_received_at']) {
                    const field = document.querySelector(`#accountStatementPaymentForm [name="${name}"]`);
                    if (field) field.required = isCheque;
                }
            };

            methodSelect.addEventListener('change', toggleMethodFields);
            toggleMethodFields();
        }

        const form = document.getElementById('accountStatementPaymentForm');
        const toEnglishDigits = (value) => value.replace(/[۰-۹]/g, digit => '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit))
            .replace(/[٠-٩]/g, digit => '٠١٢٣٤٥٦٧٨٩'.indexOf(digit));
        const toPersianDigits = (value) => value.replace(/[0-9]/g, digit => '۰۱۲۳۴۵۶۷۸۹'[digit]);
        const amountInput = form?.querySelector('[name="amount"]');
        const formatAmount = () => {
            if (!amountInput) return;
            const digits = toEnglishDigits(amountInput.value).replace(/\D/g, '');
            amountInput.value = toPersianDigits(digits.replace(/\B(?=(\d{3})+(?!\d))/g, ','));
        };
        amountInput?.addEventListener('input', formatAmount);
        formatAmount();

        const editMethod = document.getElementById('paymentFormMethod');
        const editId = document.getElementById('editingPaymentId');
        const editReasonWrap = document.getElementById('paymentEditReasonWrap');
        const editReason = form?.elements.namedItem('reason');
        const modalTitle = document.getElementById('paymentModalTitle');
        const submitButton = document.getElementById('paymentSubmitButton');

        function setEditMode(button, preserveValues = false) {
            const payment = JSON.parse(button.dataset.payment);
            if (!preserveValues) {
                form.reset();
                for (const [name, value] of Object.entries(payment)) {
                    const field = form.elements.namedItem(name);
                    if (field && value !== null && value !== undefined) field.value = value;
                }
            }
            form.action = button.dataset.updateUrl;
            editMethod.disabled = false;
            editId.disabled = false;
            editId.value = payment.id;
            editReasonWrap.classList.remove('d-none');
            editReason.disabled = false;
            editReason.required = true;
            modalTitle.textContent = 'ویرایش پرداخت';
            submitButton.textContent = 'ذخیره تغییرات';
            methodSelect.dispatchEvent(new Event('change'));
            formatAmount();
            openModal();
        }

        openButton?.addEventListener('click', () => {
            form.reset();
            form.action = form.dataset.storeUrl;
            editMethod.disabled = true;
            editId.disabled = true;
            editReasonWrap.classList.add('d-none');
            editReason.disabled = true;
            editReason.required = false;
            modalTitle.textContent = '➕ افزودن پرداخت';
            submitButton.textContent = 'ثبت پرداخت';
            methodSelect.dispatchEvent(new Event('change'));
            openModal();
        });

        document.querySelectorAll('.edit-payment-btn').forEach((button) => {
            button.addEventListener('click', () => setEditMode(button));
        });

        const deleteModal = document.getElementById('deletePaymentModal');
        const deleteBackdrop = document.getElementById('deletePaymentBackdrop');
        const deleteForm = document.getElementById('deletePaymentForm');
        function closeDeleteModal() {
            deleteModal.classList.remove('is-open');
            deleteBackdrop.classList.remove('is-open');
            deleteModal.setAttribute('aria-hidden', 'true');
            body.classList.remove('overflow-hidden');
        }
        document.querySelectorAll('.delete-payment-btn').forEach((button) => {
            button.addEventListener('click', () => {
                deleteForm.reset();
                deleteForm.action = button.dataset.deleteUrl;
                document.getElementById('deletePaymentLabel').textContent = button.dataset.paymentLabel;
                deleteModal.classList.add('is-open');
                deleteBackdrop.classList.add('is-open');
                deleteModal.setAttribute('aria-hidden', 'false');
                body.classList.add('overflow-hidden');
            });
        });
        document.getElementById('closeDeletePaymentBtn')?.addEventListener('click', closeDeleteModal);
        document.getElementById('cancelDeletePaymentBtn')?.addEventListener('click', closeDeleteModal);
        deleteBackdrop?.addEventListener('click', closeDeleteModal);

        for (const name of ['cheque_number', 'cheque_account_number']) {
            const field = form?.elements.namedItem(name);
            field?.addEventListener('input', () => {
                const start = field.selectionStart;
                const end = field.selectionEnd;
                field.value = toPersianDigits(toEnglishDigits(field.value));
                field.setSelectionRange(start, end);
            });
        }

        form?.addEventListener('submit', () => {
            for (const name of ['amount', 'cheque_number', 'cheque_account_number']) {
                const field = form.elements.namedItem(name);
                if (field) field.value = toEnglishDigits(field.value);
            }
        });

        const balanceForm = document.querySelector('#target_amount')?.form;
        const balanceAmount = balanceForm?.elements.namedItem('target_amount');
        balanceAmount?.addEventListener('input', () => {
            balanceAmount.value = toPersianDigits(toEnglishDigits(balanceAmount.value));
        });
        balanceForm?.addEventListener('submit', () => {
            balanceAmount.value = toEnglishDigits(balanceAmount.value);
        });

        @if ($errors->any())
            const failedEditId = @json(old('editing_payment_id'));
            const failedEditButton = failedEditId && [...document.querySelectorAll('.edit-payment-btn')]
                .find(button => JSON.parse(button.dataset.payment).id == failedEditId);
            if (failedEditButton) setEditMode(failedEditButton, true);
            else openModal();
        @endif
    })();
</script>
@endsection
