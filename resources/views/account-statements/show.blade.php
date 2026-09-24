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

		.statement-metric{
			height: 100%;
			border: 1px solid #e9eef5;
			border-radius: 14px;
			background: #fff;
			padding: 14px 16px;
		}

		.statement-metric__label{
			color: #64748b;
			font-size: 12px;
			margin-bottom: 6px;
		}

		.statement-metric__value{
			font-size: 17px;
			font-weight: 800;
		}

		.invoice-settlement-table th{
			white-space: nowrap;
			font-size: 12px;
			color: #64748b;
			font-weight: 700;
		}

		.invoice-settlement-table td{
			vertical-align: middle;
		}

		.invoice-ref{
			font-weight: 800;
			direction: ltr;
			display: inline-block;
		}

		.finance-badge{
			display: inline-flex;
			align-items: center;
			gap: 4px;
			border-radius: 999px;
			padding: 5px 9px;
			font-size: 11px;
			font-weight: 800;
			white-space: nowrap;
		}

		.finance-badge--debit{ background:#fee2e2; color:#991b1b; }
		.finance-badge--credit{ background:#dcfce7; color:#166534; }
		.finance-badge--settled{ background:#e2e8f0; color:#334155; }
		.finance-badge--online{ background:#dbeafe; color:#1d4ed8; }
		.finance-badge--adjustment{ background:#fef3c7; color:#92400e; }

		.invoice-details{
			min-width: 260px;
		}

		.invoice-details summary{
			cursor: pointer;
			color: #2563eb;
			font-size: 12px;
			font-weight: 700;
			list-style: none;
		}

		.invoice-details summary::-webkit-details-marker{
			display:none;
		}

		.invoice-details__box{
			margin-top: 8px;
			padding: 10px 12px;
			border: 1px solid #e5e7eb;
			background: #f8fafc;
			border-radius: 10px;
			font-size: 12px;
			line-height: 1.9;
		}

		.invoice-details__section + .invoice-details__section{
			margin-top: 8px;
			padding-top: 8px;
			border-top: 1px dashed #cbd5e1;
		}

		.ledger-description{
			min-width: 340px;
			line-height: 1.8;
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


	<div class="row g-3 mb-3">
		<div class="col-6 col-xl-3">
			<div class="statement-metric">
				<div class="statement-metric__label">مانده اول دوره</div>
				<div class="statement-metric__value {{ $statementTotals['opening_balance'] > 0 ? 'text-danger' : ($statementTotals['opening_balance'] < 0 ? 'text-success' : '') }}">
					{{ \App\Support\Currency::formatRial(abs($statementTotals['opening_balance'])) }}
				</div>
			</div>
		</div>
		<div class="col-6 col-xl-3">
			<div class="statement-metric">
				<div class="statement-metric__label">جمع بدهکار</div>
				<div class="statement-metric__value text-danger">{{ \App\Support\Currency::formatRial($statementTotals['total_debit']) }}</div>
			</div>
		</div>
		<div class="col-6 col-xl-3">
			<div class="statement-metric">
				<div class="statement-metric__label">جمع بستانکار / پرداخت‌ها</div>
				<div class="statement-metric__value text-success">{{ \App\Support\Currency::formatRial($statementTotals['total_credit']) }}</div>
			</div>
		</div>
		<div class="col-6 col-xl-3">
			<div class="statement-metric">
				<div class="statement-metric__label">تخفیف ثبت‌شده در فاکتورها</div>
				<div class="statement-metric__value">{{ \App\Support\Currency::formatRial($statementTotals['invoice_discount_total']) }}</div>
			</div>
		</div>
	</div>

	<div class="card border-0 shadow-sm mb-3">
		<div class="card-header bg-white d-flex justify-content-between align-items-center gap-2 flex-wrap">
			<div>
				<div class="fw-bold">وضعیت فاکتورها و تسویه‌ها</div>
				<div class="text-muted small">
					تخفیف و اصلاح مبلغ فاکتور در این بخش به‌صورت توضیح محاسباتی نمایش داده می‌شود و به‌عنوان بستانکار جداگانه دوباره در دفتر حساب ثبت نمی‌شود.
				</div>
			</div>
			<span class="badge text-bg-light border">آخرین {{ $invoiceSummaries->count() }} فاکتور</span>
		</div>

		<div class="table-responsive">
			<table class="table align-middle mb-0 invoice-settlement-table">
				<thead>
				<tr>
					<th>فاکتور</th>
					<th>قبل از تخفیف</th>
					<th>تخفیف</th>
					<th>مبلغ نهایی</th>
					<th>پرداخت‌شده</th>
					<th>مانده / اضافه‌پرداخت</th>
					<th>وضعیت</th>
					<th>جزئیات</th>
				</tr>
				</thead>
				<tbody>
				@if($invoiceSummaries->isEmpty())
					<tr>
						<td colspan="8" class="text-center py-4 text-muted">فاکتوری برای این مشتری ثبت نشده است.</td>
					</tr>
				@else
					@foreach($invoiceSummaries as $summary)
						@php
							$invoiceRow = $summary['invoice'];
							$isOverpaid = $summary['overpayment'] > 0;
							$hasRemaining = $summary['remaining'] > 0;
						@endphp
						<tr>
							<td class="text-nowrap">
								<a class="invoice-ref text-decoration-none" href="{{ route('vouchers.sales.show', $invoiceRow->uuid) }}">
									{{ $invoiceRow->uuid }}
								</a>
								@if($invoiceRow->external_order_id)
									<div class="small text-muted mt-1">سفارش سایت #{{ $invoiceRow->external_order_id }}</div>
								@endif
							</td>

							<td class="text-nowrap">{{ \App\Support\Currency::formatRial($summary['gross_before_discount']) }}</td>

							<td class="text-nowrap">
								@if($summary['total_discount'] > 0)
									<span class="text-danger fw-bold">- {{ \App\Support\Currency::formatRial($summary['total_discount']) }}</span>
								@else
									<span class="text-muted">—</span>
								@endif
							</td>

							<td class="text-nowrap fw-bold">{{ \App\Support\Currency::formatRial($summary['final_total']) }}</td>
							<td class="text-nowrap text-success fw-bold">{{ \App\Support\Currency::formatRial($summary['paid_total']) }}</td>

							<td class="text-nowrap">
								@if($isOverpaid)
									<span class="text-success fw-bold">+ {{ \App\Support\Currency::formatRial($summary['overpayment']) }}</span>
									<div class="small text-muted">اعتبار اضافه مشتری</div>
								@elseif($hasRemaining)
									<span class="text-danger fw-bold">{{ \App\Support\Currency::formatRial($summary['remaining']) }}</span>
									<div class="small text-muted">باقی‌مانده بدهی</div>
								@else
									<span class="text-muted">۰</span>
								@endif
							</td>

							<td>
								@if($isOverpaid)
									<span class="finance-badge finance-badge--credit">تسویه + اضافه‌پرداخت</span>
								@elseif($hasRemaining)
									<span class="finance-badge finance-badge--debit">بدهکار</span>
								@else
									<span class="finance-badge finance-badge--settled">تسویه</span>
								@endif

								@if($summary['has_adjustment'])
									<div class="mt-1">
										<span class="finance-badge finance-badge--adjustment">اصلاح‌شده توسط انبار</span>
									</div>
								@endif
							</td>

							<td>
								<details class="invoice-details">
									<summary>مشاهده جزئیات مالی</summary>

									<div class="invoice-details__box">

										<div class="invoice-details__section">
											<div>
												جمع اقلام قبل از تخفیف:
												<strong>
													{{ \App\Support\Currency::formatRial((int) $summary['subtotal']) }}
												</strong>
											</div>

											<div>
												هزینه ارسال:
												<strong>
													{{ \App\Support\Currency::formatRial((int) $summary['shipping']) }}
												</strong>
											</div>

											<div>
												تخفیف اقلام:
												<strong class="text-danger">
													{{ \App\Support\Currency::formatRial((int) $summary['product_discount']) }}
												</strong>
											</div>

											<div>
												تخفیف فاکتور:
												<strong class="text-danger">
													{{ \App\Support\Currency::formatRial((int) $summary['invoice_discount']) }}
												</strong>
											</div>

											<div>
												مبلغ نهایی:
												<strong>
													{{ \App\Support\Currency::formatRial((int) $summary['final_total']) }}
												</strong>
											</div>
										</div>


										@if($summary['revisions']->isNotEmpty())

											<div class="invoice-details__section">

												<div class="fw-bold mb-1">
													اصلاحات انبار
												</div>

												@foreach($summary['revisions'] as $revision)

													@php
														$revisionDiff =
															(int) $revision->new_total
															- (int) $revision->old_total;
													@endphp

													<div class="mb-1">

														اصلاح #{{ $revision->revision_number }}:

														{{ \App\Support\Currency::formatRial((int) $revision->old_total) }}

														←

														{{ \App\Support\Currency::formatRial((int) $revision->new_total) }}

														<span class="{{ $revisionDiff < 0 ? 'text-success' : ($revisionDiff > 0 ? 'text-danger' : 'text-muted') }}">

                            (
                            {{ $revisionDiff > 0 ? '+' : '' }}
															{{ \App\Support\Currency::formatRial($revisionDiff) }}
                            )

                        </span>

														@if(!empty($revision->reason_type))
															<span class="text-muted">
                                | دلیل: {{ $revision->reason_type }}
                            </span>
														@endif

														@if(!empty($revision->reason_note))
															<span class="text-muted">
                                | {{ $revision->reason_note }}
                            </span>
														@endif

													</div>

												@endforeach

											</div>

										@endif


										<div class="invoice-details__section">

											<div class="fw-bold mb-1">
												پرداخت‌ها
											</div>

											@php
												$invoicePayments = $summary['payments'] ?? collect();
											@endphp


											@if($invoicePayments->isEmpty())

												<div class="text-muted">
													پرداختی برای این فاکتور ثبت نشده است.
												</div>

											@endif


											@foreach($invoicePayments as $paymentRow)

												@php
													$paymentMethodLabels = [
														'online' => 'آنلاین / سایت',
														'cash' => 'نقدی',
														'cheque' => 'چک',
														'card' => 'کارت',
														'bank_transfer' => 'حواله بانکی',
													];

													$methodLabel =
														$paymentMethodLabels[$paymentRow->method]
														?? ($paymentRow->method ?: 'نامشخص');
												@endphp

												<div class="mb-2">

													<div>

														<a
																href="{{ route('account-statements.documents.payments.show', $paymentRow->id) }}"
																class="text-decoration-none"
														>
															پرداخت #{{ $paymentRow->id }}
														</a>

														<span class="mx-1">—</span>

														<span>
                            {{ $methodLabel }}
                        </span>

														<span class="mx-1">—</span>

														<strong class="text-success">
															{{ \App\Support\Currency::formatRial((int) $paymentRow->amount) }}
														</strong>

													</div>


													@if(!empty($paymentRow->payment_identifier))

														<div class="small text-muted mt-1">
															شناسه پرداخت:
															{{ $paymentRow->payment_identifier }}
														</div>

													@endif


													@if(!empty($paymentRow->note))

														<div class="small text-muted">
															{{ $paymentRow->note }}
														</div>

													@endif

												</div>

											@endforeach

										</div>

									</div>

								</details>
							</td>
						</tr>
					@endforeach
				@endif
				</tbody>
			</table>
		</div>
	</div>

	<div class="card quick-actions-card mb-3">
		<div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
			<div>
				<div class="fw-bold">ثبت پرداخت جدید</div>
				<div class="text-muted small">برای این مشتری پرداخت نقدی یا چکی ثبت کن</div>
			</div>
			<button type="button" class="btn btn-success" id="openPaymentModalBtnSecondary">
				افزودن پرداخت
			</button>
		</div>
	</div>

	<div class="card">
		<div class="card-header bg-white d-flex justify-content-between align-items-center gap-2 flex-wrap">
			<div>
				<div class="fw-bold">دفتر گردش حساب</div>
				<div class="text-muted small">ثبت خام بدهکار و بستانکار برای کنترل حسابداری و تطبیق اسناد</div>
			</div>
			<div class="d-flex gap-2 flex-wrap">
				<span class="finance-badge finance-badge--debit">بدهکار = افزایش بدهی مشتری</span>
				<span class="finance-badge finance-badge--credit">بستانکار = پرداخت / کاهش بدهی</span>
			</div>
		</div>

		<div class="table-responsive">
			<table class="table align-middle mb-0">
				<thead>
				<tr>
					<th>تاریخ</th>
					<th>نوع عملیات</th>
					<th>شرح و مرجع</th>
					<th>بدهکار</th>
					<th>بستانکار</th>
					<th class="text-end">سند</th>
				</tr>
				</thead>
				<tbody>
				@if($ledgers->count() === 0)
					<tr>
						<td colspan="6" class="text-center py-4 text-muted">گردشی برای این شخص ثبت نشده است.</td>
					</tr>
				@else
					@foreach($ledgers as $ledger)
						@php
							$invoice = $ledger->reference_type === \App\Models\Invoice::class ? ($invoices[$ledger->reference_id] ?? null) : null;
							$payment = $ledger->reference_type === \App\Models\InvoicePayment::class ? ($payments[$ledger->reference_id] ?? null) : null;
							$transfer = $ledger->reference_type === \App\Models\WarehouseTransfer::class ? ($transfers[$ledger->reference_id] ?? null) : null;
							$salesReturn = $ledger->reference_type === \App\Models\SalesReturnDocument::class ? ($salesReturnDocuments[$ledger->reference_id] ?? null) : null;

							$description = $ledger->note ?: '—';
							$viewUrl = null;
							$operationLabel = $ledger->type === 'debit' ? 'بدهکار' : 'بستانکار';
							$operationClass = $ledger->type === 'debit' ? 'finance-badge--debit' : 'finance-badge--credit';

							if ($invoice) {
								$operationLabel = 'فاکتور فروش';
								$operationClass = 'finance-badge--debit';
								$description = "فاکتور {$invoice->uuid} | مبلغ نهایی ".\App\Support\Currency::formatRial((int) $invoice->total);

								if ((int) $invoice->discount_amount > 0) {
									$description .= " | تخفیف ".\App\Support\Currency::formatRial((int) $invoice->discount_amount);
								}

								if ($invoice->external_order_id) {
									$description .= " | سفارش سایت #{$invoice->external_order_id}";
								}

								$viewUrl = route('vouchers.sales.show', $invoice->uuid);
							}

							if ($payment) {
								$invoiceUuid = $payment->invoice?->uuid ?: ($invoices[$payment->invoice_id]->uuid ?? null);
								$isSitePayment = $payment->method === 'online'
									&& str_starts_with((string) $payment->payment_identifier, 'SITE-ORDER-');

								$methodLabel = match($payment->method) {
									'online' => $isSitePayment ? 'پرداخت آنلاین سایت' : 'پرداخت آنلاین',
									'cash' => 'پرداخت نقدی',
									'cheque' => 'پرداخت چکی',
									'card' => 'پرداخت کارت',
									'bank_transfer' => 'حواله بانکی',
									default => 'پرداخت',
								};

								$operationLabel = $methodLabel;
								$operationClass = $payment->method === 'online' ? 'finance-badge--online' : 'finance-badge--credit';

								if ($payment->method === 'cheque') {
									$cheque = $payment->cheque;
									$chNumber = $cheque?->cheque_number ?: '—';
									$description = "{$methodLabel} شماره {$chNumber} | مبلغ ".\App\Support\Currency::formatRial((int) $payment->amount);
								} else {
									$description = "{$methodLabel} | مبلغ ".\App\Support\Currency::formatRial((int) $payment->amount);

									if ($payment->bank_name) {
										$description .= " | بانک {$payment->bank_name}";
									}
								}

								if ($payment->payment_identifier) {
									$description .= " | شناسه {$payment->payment_identifier}";
								}

								if ($invoiceUuid) {
									$description .= " | فاکتور {$invoiceUuid}";
								}

								$creatorName = $payment->creator?->name
									?: ($isSitePayment ? 'سایت' : 'سیستم');
								$description .= " | ثبت‌کننده: {$creatorName}";

								$viewUrl = route('account-statements.documents.payments.show', $payment->id);
							}

							if ($salesReturn) {
								$source = \App\Models\SalesReturnDocument::sourceTypeLabels()[$salesReturn->source_type] ?? $salesReturn->source_type;
								$operationLabel = 'برگشت از فروش';
								$operationClass = 'finance-badge--credit';
								$description = "سند برگشت از فروش {$salesReturn->document_number} | نوع: {$source} | مبلغ " . \App\Support\Currency::formatRial((int) $salesReturn->total_refund_amount);
								$viewUrl = route('sales-returns.show', $salesReturn->id);
							}

							if ($transfer) {
								$transferRef = $transfer->reference ?: ('TR-' . $transfer->id);
								$transferTypeLabel = \App\Models\WarehouseTransfer::typeOptions()[$transfer->voucher_type] ?? $transfer->voucher_type;
								$operationLabel = $transferTypeLabel;
								$description = "سند {$transferRef} | نوع: {$transferTypeLabel} | مبلغ " . \App\Support\Currency::formatRial((int) $ledger->amount);

								if ($transfer->voucher_type === \App\Models\WarehouseTransfer::TYPE_CUSTOMER_RETURN) {
									$operationClass = 'finance-badge--credit';
									$viewUrl = route('account-statements.documents.returns.show', $transfer->id);
								}
							}
						@endphp

						<tr>
							<td class="text-nowrap">{{ $ledger->created_at ? Jalalian::fromDateTime($ledger->created_at)->format('Y/m/d H:i') : '—' }}</td>
							<td><span class="finance-badge {{ $operationClass }}">{{ $operationLabel }}</span></td>
							<td class="ledger-description">{{ $description }}</td>
							<td class="text-nowrap text-danger fw-bold">
								{{ $ledger->type === 'debit' ? \App\Support\Currency::formatRial((int) $ledger->amount) : '—' }}
							</td>
							<td class="text-nowrap text-success fw-bold">
								{{ $ledger->type === 'credit' ? \App\Support\Currency::formatRial((int) $ledger->amount) : '—' }}
							</td>
							<td class="text-end">
								@if($viewUrl)
									<a href="{{ $viewUrl }}" class="btn btn-sm btn-outline-primary">مشاهده</a>
								@else
									<span class="text-muted">—</span>
								@endif
							</td>
						</tr>
					@endforeach
				@endif
				</tbody>
			</table>
		</div>

		<div class="card-footer bg-white">{{ $ledgers->links() }}</div>
	</div>

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

						<div class="col-md-4">
							<label class="form-label">تاریخ پرداخت</label>
							<input
									type="date"
									class="form-control"
									name="paid_at"
									value="{{ old('paid_at') }}"
									required
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
								<div class="col-md-3">
									<label class="form-label">شماره چک</label>
									<input type="text" class="form-control" name="cheque_number" value="{{ old('cheque_number') }}">
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
									<input type="text" class="form-control" name="cheque_customer_name" value="{{ old('cheque_customer_name', $customer->display_name) }}">
								</div>

								<div class="col-md-3">
									<label class="form-label">کد/شناسه مشتری</label>
									<input type="text" class="form-control" name="cheque_customer_code" value="{{ old('cheque_customer_code') }}">
								</div>

								<div class="col-md-3">
									<label class="form-label">شماره حساب/شبا</label>
									<input type="text" class="form-control" name="cheque_account_number" value="{{ old('cheque_account_number') }}">
								</div>

								<div class="col-md-3">
									<label class="form-label">صاحب حساب</label>
									<input type="text" class="form-control" name="cheque_account_holder" value="{{ old('cheque_account_holder') }}">
								</div>

								<div class="col-md-3">
									<label class="form-label">وضعیت چک</label>
									<select name="cheque_status" class="form-select">
										<option value="pending" @selected(old('cheque_status', 'pending') === 'pending')>در انتظار وصول</option>
										<option value="cleared" @selected(old('cheque_status') === 'cleared')>وصول شده</option>
										<option value="bounced" @selected(old('cheque_status') === 'bounced')>برگشتی</option>
									</select>
								</div>

								<div class="col-md-6">
									<label class="form-label">تصویر چک</label>
									<input type="file" class="form-control" name="cheque_image" accept="image/*">
								</div>
							</div>
						</div>

						<div class="col-12">
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

			@if ($errors->any())
			openModal();
			@endif
		})();
	</script>
@endsection