@extends('layouts.app')

@php
    use Morilog\Jalali\Jalalian;
@endphp

@section('content')
<style>
    .payment-modal-backdrop{
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, .45);
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
        max-width: 980px;
        max-height: calc(100vh - 32px);
        overflow: hidden;
        border-radius: 18px;
        background: #fff;
        box-shadow: 0 20px 60px rgba(15, 23, 42, .18);
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
        padding: 16px 18px;
        border-bottom: 1px solid #eef2f7;
        background: #f8fafc;
    }

    .payment-modal-title{
        font-size: 18px;
        font-weight: 800;
        margin: 0;
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
        padding: 18px;
        max-height: calc(100vh - 140px);
        overflow-y: auto;
    }

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

<div class="page-toolbar">
    <div>
        <h4 class="mb-0">📑 گردش حساب {{ $customer->display_name ?: 'شخص' }}</h4>
        <div class="text-muted small">جزئیات کامل تراکنش‌ها، فاکتورها و اسناد مالی مشتری</div>
    </div>

    <div class="d-flex align-items-center gap-2 flex-wrap">
        @canPermission('account_statements.adjust')
            <button type="button" class="btn btn-outline-warning" id="openAdjustmentModalBtn">
                تنظیم دستی مانده
            </button>
        @endcanPermission
        <button type="button" class="btn btn-success" id="openPaymentModalBtn">
            ➕ افزودن پرداخت
        </button>
        <a href="{{ route('account-statements.index') }}" class="btn btn-outline-secondary">
            بازگشت به لیست اشخاص
        </a>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-3 align-items-center">
            <div class="col-12 col-lg-8">
                <h6 class="fw-bold mb-2">اطلاعات مشتری</h6>
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge text-bg-light border px-3 py-2">👤 {{ $customer->display_name ?: '—' }}</span>
                    <span class="badge text-bg-light border px-3 py-2">📱 {{ $customer->mobile ?: '—' }}</span>
                    <span class="badge text-bg-light border px-3 py-2 text-wrap">📍 {{ $customer->address ?: 'آدرس ثبت نشده' }}</span>
                </div>
            </div>

            <div class="col-12 col-lg-4">
                <div class="rounded-3 p-3 border {{ $netBalance > 0 ? 'border-danger-subtle bg-danger-subtle' : ($netBalance < 0 ? 'border-success-subtle bg-success-subtle' : 'border-secondary-subtle bg-light') }}">
                    <div class="text-muted small mb-1">وضعیت نهایی حساب</div>
                    <div class="fs-5 fw-bold {{ $netBalance > 0 ? 'text-danger' : ($netBalance < 0 ? 'text-success' : 'text-muted') }}">
                        {{ $netBalance > 0 ? 'بدهکار' : ($netBalance < 0 ? 'بستانکار' : 'تسویه') }}
                        {{ $netBalance === 0 ? '' : \App\Support\Currency::formatRial(abs($netBalance)) }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card quick-actions-card mb-3">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="fw-bold">ثبت پرداخت جدید</div>
            <div class="text-muted small">برای این مشتری پرداخت نقدی یا چکی ثبت کن</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @canPermission('account_statements.adjust')
                <button type="button" class="btn btn-outline-warning" id="openAdjustmentModalBtnSecondary">
                    تنظیم دستی مانده
                </button>
            @endcanPermission
            <button type="button" class="btn btn-success" id="openPaymentModalBtnSecondary">
                افزودن پرداخت
            </button>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white">لیست گردش‌ها</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>تاریخ</th>
                    <th>شرح</th>
                    <th>بدهکار</th>
                    <th>بستانکار</th>
                    <th class="text-end">مشاهده</th>
                </tr>
            </thead>
            <tbody>
                @forelse($ledgers as $ledger)
                    @php
                        $invoice = $ledger->reference_type === \App\Models\Invoice::class ? ($invoices[$ledger->reference_id] ?? null) : null;
                        $payment = $ledger->reference_type === \App\Models\InvoicePayment::class ? ($payments[$ledger->reference_id] ?? null) : null;
                        $transfer = $ledger->reference_type === \App\Models\WarehouseTransfer::class ? (($transfers[$ledger->reference_id] ?? null) ?: ($legacyReturnTransfers[$ledger->id] ?? null)) : ($legacyReturnTransfers[$ledger->id] ?? null);
                        $purchase = $ledger->reference_type === \App\Models\Purchase::class ? ($purchases[$ledger->reference_id] ?? null) : null;
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

                        if ($transfer) {
                            $transferRef = $transfer->reference ?: ('TR-' . $transfer->id);
                            $transferTypeLabel = \App\Models\WarehouseTransfer::typeOptions()[$transfer->voucher_type] ?? $transfer->voucher_type;
                            $amount = $transfer->total_amount ?: $ledger->amount;
                            $description = "سند {$transferRef} | نوع: {$transferTypeLabel} | مبلغ " . \App\Support\Currency::formatRial($amount);

                            if ($transfer->voucher_type === \App\Models\WarehouseTransfer::TYPE_CUSTOMER_RETURN) {
                                $viewUrl = route('account-statements.documents.returns.show', $transfer->id);
                            }
                        }

                        if ($purchase) {
                            $purchaseDate = $purchase->purchased_at ? Jalalian::fromDateTime($purchase->purchased_at)->format('Y/m/d') : '—';
                            $supplierName = $purchase->supplier?->name ?: '—';
                            $description = "خرید کالا PUR-{$purchase->id} | فروشنده: {$supplierName} | تاریخ خرید: {$purchaseDate} | مبلغ " . \App\Support\Currency::formatRial($purchase->total_amount);
                            $viewUrl = route('purchases.show', $purchase->id);
                        }

                        if (! $viewUrl && str_contains((string) $ledger->note, 'برگشت از فروش')) {
                            $viewUrl = route('vouchers.section.index', ['type' => 'return-from-sale', 'customer_id' => $customer->id]);
                        }
                    @endphp
                    <tr>
                        <td class="text-nowrap">{{ $ledger->created_at ? Jalalian::fromDateTime($ledger->created_at)->format('Y/m/d H:i') : '—' }}</td>
                        <td>{{ $description }}</td>
                        <td>{{ $ledger->type === 'debit' ? number_format((int) $ledger->amount) : '—' }}</td>
                        <td>{{ $ledger->type === 'credit' ? number_format((int) $ledger->amount) : '—' }}</td>
                        <td class="text-end">
                            @if($viewUrl)
                                <a href="{{ $viewUrl }}" class="btn btn-sm btn-primary">مشاهده</a>
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


@canPermission('account_statements.adjust')
<div class="payment-modal-backdrop" id="adjustmentModalBackdrop"></div>

<div class="payment-modal" id="adjustmentModal" aria-hidden="true">
    <div class="payment-modal-dialog" style="max-width: 620px;">
        <div class="payment-modal-header">
            <div>
                <h5 class="payment-modal-title">تنظیم دستی مانده</h5>
                <div class="payment-modal-subtitle">تنظیم مانده نهایی گردش حساب {{ $customer->display_name ?: 'این مشتری' }}</div>
            </div>

            <button type="button" class="payment-modal-close" id="closeAdjustmentModalBtn" aria-label="بستن">
                ×
            </button>
        </div>

        <div class="payment-modal-body">
            <form method="POST" action="{{ route('account-statements.adjustment.store', $customer->id) }}" id="accountStatementAdjustmentForm">
                @csrf

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">نوع مانده نهایی</label>
                        <select name="balance_type" id="manualAdjustmentBalanceType" class="form-select @error('balance_type', 'manualAdjustment') is-invalid @enderror" required>
                            <option value="debit" @selected(old('balance_type') === 'debit')>بدهکار</option>
                            <option value="credit" @selected(old('balance_type') === 'credit')>بستانکار</option>
                        </select>
                        @error('balance_type', 'manualAdjustment')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">مبلغ مانده نهایی</label>
                        <input type="text" inputmode="numeric" dir="ltr" class="form-control text-start @error('amount', 'manualAdjustment') is-invalid @enderror" id="manualAdjustmentAmount" name="amount" value="{{ old('amount') }}" required autocomplete="off">
                        @error('amount', 'manualAdjustment')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <div class="alert alert-info small mb-0">عدد واردشده به مانده فعلی اضافه نمی‌شود؛ مانده حساب شخص دقیقاً روی همین مبلغ تنظیم می‌شود.</div>
                    </div>

                    <div class="col-12">
                        <div class="text-muted small" id="manualAdjustmentPreview">بعد از ثبت، مانده نهایی این شخص: —</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">توضیحات اختیاری</label>
                        <textarea name="note" class="form-control @error('note', 'manualAdjustment') is-invalid @enderror" rows="3" maxlength="1000" placeholder="اختیاری">{{ old('note') }}</textarea>
                        @error('note', 'manualAdjustment')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12 d-flex justify-content-end gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-secondary" id="cancelAdjustmentModalBtn">انصراف</button>
                        <button type="submit" class="btn btn-warning">ثبت اصلاح دستی</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endcanPermission

<div class="payment-modal-backdrop" id="paymentModalBackdrop"></div>

<div class="payment-modal" id="paymentModal" aria-hidden="true">
    <div class="payment-modal-dialog">
        <div class="payment-modal-header">
            <div>
                <h5 class="payment-modal-title">➕ افزودن پرداخت</h5>
                <div class="payment-modal-subtitle">ثبت پرداخت نقدی یا چکی برای {{ $customer->display_name ?: 'این مشتری' }}</div>
            </div>

            <button type="button" class="payment-modal-close" id="closePaymentModalBtn" aria-label="بستن">
                ×
            </button>
        </div>

        <div class="payment-modal-body">
            <form method="POST" action="{{ route('account-statements.payments.store', $customer->id) }}" enctype="multipart/form-data" id="accountStatementPaymentForm">
                @csrf

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
                                    {{ $invoiceOption->uuid }} | {{ \App\Support\Currency::formatRial($invoiceOption->total) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">مبلغ</label>
                        <input
                            type="number"
                            min="1"
                            step="1"
                            class="form-control"
                            name="amount"
                            value="{{ old('amount') }}"
                            placeholder="مبلغ پرداخت"
                            required
                        >
                    </div>

                    <div class="col-md-4 as-cash-fields">
                        <label class="form-label">تاریخ پرداخت</label>
                        <input
                            type="date"
                            class="form-control"
                            name="paid_at"
                            value="{{ old('paid_at') }}"
                        >
                    </div>

                    <div class="col-md-4 as-cash-fields">
                        <label class="form-label">اسم بانک</label>
                        <input
                            type="text"
                            class="form-control"
                            name="bank_name"
                            value="{{ old('bank_name') }}"
                            placeholder="مثال: ملی"
                        >
                    </div>

                    <div class="col-md-4 as-cash-fields">
                        <label class="form-label">رسید پرداخت</label>
                        <input type="file" class="form-control" name="receipt_image" accept="image/*">
                    </div>

                    <div class="col-12 as-cheque-fields d-none">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">شماره سریال چک</label>
                                <input type="text" class="form-control" name="cheque_number" value="{{ old('cheque_number') }}">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">تاریخ ثبت چک</label>
                                <input type="date" class="form-control" name="received_at" value="{{ old('received_at') }}">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">تاریخ سررسید چک</label>
                                <input type="date" class="form-control" name="due_date" value="{{ old('due_date') }}">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">وضعیت چک</label>
                                <select name="status" class="form-select">
                                    <option value="registered" @selected(old('status') === 'registered')>ثبت‌شده</option>
                                    <option value="unregistered" @selected(old('status') === 'unregistered')>ثبت‌نشده</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 as-cash-fields">
                        <label class="form-label">یادداشت</label>
                        <textarea name="note" class="form-control" rows="3" placeholder="اختیاری">{{ old('note') }}</textarea>
                    </div>

                    <div class="col-12 d-flex justify-content-end gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-secondary" id="cancelPaymentModalBtn">انصراف</button>
                        <button type="submit" class="btn btn-success">ثبت پرداخت</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>

    (function () {
        const body = document.body;
        const modal = document.getElementById('adjustmentModal');
        const backdrop = document.getElementById('adjustmentModalBackdrop');
        const openButtons = [
            document.getElementById('openAdjustmentModalBtn'),
            document.getElementById('openAdjustmentModalBtnSecondary')
        ].filter(Boolean);
        const closeButton = document.getElementById('closeAdjustmentModalBtn');
        const cancelButton = document.getElementById('cancelAdjustmentModalBtn');
        const amountInput = document.getElementById('manualAdjustmentAmount');
        const balanceTypeSelect = document.getElementById('manualAdjustmentBalanceType');
        const preview = document.getElementById('manualAdjustmentPreview');
        const form = document.getElementById('accountStatementAdjustmentForm');

        if (!modal || !backdrop) return;

        function normalizeDigits(value) {
            const map = {
                '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
                '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
                '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
                '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9'
            };

            return String(value || '').replace(/[۰-۹٠-٩]/g, (char) => map[char] || char);
        }

        function rawAmount() {
            return normalizeDigits(amountInput ? amountInput.value : '').replace(/[^0-9]/g, '');
        }

        function formatAmount(value) {
            const raw = normalizeDigits(value).replace(/[^0-9]/g, '');
            return raw === '' ? '' : raw.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        function updatePreview() {
            if (!preview || !balanceTypeSelect) return;

            const raw = rawAmount();
            const label = balanceTypeSelect.value === 'credit' ? 'بستانکار' : 'بدهکار';

            preview.textContent = raw === ''
                ? 'بعد از ثبت، مانده نهایی این شخص: —'
                : `بعد از ثبت، مانده نهایی این شخص: ${label} ${formatAmount(raw)} ریال خواهد شد.`;
        }

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

        if (amountInput) {
            amountInput.value = formatAmount(amountInput.value);
            amountInput.addEventListener('input', function () {
                amountInput.value = formatAmount(amountInput.value);
                updatePreview();
            });
        }

        balanceTypeSelect && balanceTypeSelect.addEventListener('change', updatePreview);
        form && form.addEventListener('submit', function () {
            if (amountInput) amountInput.value = rawAmount();
        });
        updatePreview();

        openButtons.forEach((btn) => btn.addEventListener('click', openModal));
        closeButton && closeButton.addEventListener('click', closeModal);
        cancelButton && cancelButton.addEventListener('click', closeModal);
        backdrop.addEventListener('click', closeModal);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('is-open')) {
                closeModal();
            }
        });

        @if ($errors->manualAdjustment->any())
            openModal();
        @endif
    })();

    (function () {
        const body = document.body;
        const modal = document.getElementById('paymentModal');
        const backdrop = document.getElementById('paymentModalBackdrop');
        const openButtons = [
            document.getElementById('openPaymentModalBtn'),
            document.getElementById('openPaymentModalBtnSecondary')
        ].filter(Boolean);
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

        openButtons.forEach((btn) => {
            btn.addEventListener('click', openModal);
        });

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
            };

            methodSelect.addEventListener('change', toggleMethodFields);
            toggleMethodFields();
        }

        @if ($errors->any() && ! $errors->manualAdjustment->any())
            openModal();
        @endif
    })();
</script>
@endsection