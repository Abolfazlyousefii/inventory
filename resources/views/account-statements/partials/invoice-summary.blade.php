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
