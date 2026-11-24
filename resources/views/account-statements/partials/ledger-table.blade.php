        <div class="asd-table-wrap">
            <table class="asd-table">
                <thead><tr><th>تاریخ سند</th><th>شماره سند</th><th>شرح</th><th>بدهکار</th><th>بستانکار</th><th>مانده پس از سند</th>@if($canManageDocuments)<th class="asd-operations-heading"><span class="visually-hidden">عملیات سند</span></th>@endif</tr></thead>
                <tbody>
                @forelse($ledgers as $ledger)
                    @php
                        $invoice = $ledger->reference_type === \App\Models\Invoice::class ? ($invoices[$ledger->reference_id] ?? null) : null;
                        $payment = $ledger->reference_type === \App\Models\InvoicePayment::class ? ($payments[$ledger->reference_id] ?? null) : null;
                        $adjustmentDocument = $ledger->reference_type === \App\Models\ActivityLog::class ? ($adjustmentDocuments[$ledger->reference_id] ?? null) : null;
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
                        $personnelDocument = $ledger->reference_type === \App\Models\PersonnelAccountDocument::class ? ($personnelDocuments[$ledger->reference_id] ?? null) : null;
                        if ($personnelDocument) {
                            $reference = 'PER-'.$personnelDocument->id;
                            $description = 'سند پرسنلی · '.$personnelDocument->description;
                        }
                        $financialDocument = $ledger->reference_type === \App\Models\FinancialAccountDocument::class ? ($financialDocuments[$ledger->reference_id] ?? null) : null;
                        if ($financialDocument) {
                            $reference = 'FIN-'.$financialDocument->id;
                            $description = $financialDocument->title;
                        }
                        if (isset($statementDocumentUrl)) $url = $statementDocumentUrl($ledger, $invoice, $salesReturn);
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
                            @if($financialDocument)<small>{{ $financialDocument->description }}</small><small>ثبت‌کننده: {{ $financialDocument->creator?->name ?: '—' }}</small>@if($financialDocument->cancelled_at)<small class="text-danger">سند حذف شده — بدون اثر در مانده</small>@endif @endif
                            @if($personnelDocument)
                                @if($personnelDocument->reference)<small>ارجاع سازه‌حساب: {{ $personnelDocument->reference }}</small>@endif
                                <small>ثبت‌کننده: {{ $personnelDocument->creator?->name ?: '—' }}</small>
                            @endif
                            @if($invoice)
                                <small class="asd-invoice-customer">مشتری فاکتور: {{ $invoiceCustomerName !== '' ? $invoiceCustomerName : 'نام مشتری ثبت نشده' }}</small>
                            @endif
                            @if(!$effective)<small class="asd-excluded">بدون اثر در مانده</small>@endif
                        </td>
                        <td class="asd-money" data-label="بدهکار">{{ $ledger->type === 'debit' ? $rial($ledger->amount) : '—' }}</td>
                        <td class="asd-money" data-label="بستانکار">{{ $ledger->type === 'credit' ? $rial($ledger->amount) : '—' }}</td>
                        <td class="asd-row-balance {{ $rowBalance > 0 ? 'asd-debt' : ($rowBalance < 0 ? 'asd-credit' : '') }}" data-label="مانده پس از سند"><strong>{{ $rial($rowBalance) }}</strong><small>{{ $balanceText($rowBalance) }}</small></td>
                        @if($canManageDocuments)<td class="asd-operations" data-label="عملیات">
                            @if($financialDocument && !$financialDocument->cancelled_at && $canAdjustBalance)<a class="asd-payment-action" href="{{ route('financial-documents.edit',$financialDocument) }}">ویرایش / حذف</a>@endif
                            @if($payment && $canManagePayments) @include('payments.manage-link', ['payment' => $payment, 'compactPaymentLink' => true]) @endif
                            @if($adjustmentDocument && $canAdjustBalance && !data_get($adjustmentDocument->properties, 'deleted_at'))<a class="asd-payment-action" href="{{ route('account-statements.adjustments.edit', [$customer, $adjustmentDocument]) }}">ویرایش / حذف</a>@endif
                        </td>@endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canManageDocuments ? 7 : 6 }}" class="asd-empty">گردشی برای این شخص ثبت نشده است.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
