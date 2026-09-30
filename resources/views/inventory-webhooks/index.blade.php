@extends('layouts.app')

@section('content')

	<div class="container py-4">

		<h1 class="mb-3">
			مدیریت API تغییرات موجودی و قیمت
		</h1>


		@if(session('success'))

			<div class="alert alert-success">
				{{ session('success') }}
			</div>

		@endif


		@if(session('error'))

			<div class="alert alert-danger">
				{{ session('error') }}
			</div>

		@endif


		@if(!($dbReady ?? false))

			<div class="alert alert-warning">

				جداول این ماژول هنوز ساخته نشده‌اند.
				لطفاً دستور

				<code>
					php artisan migrate
				</code>

				را اجرا کنید.

			</div>

		@endif



		<div class="card mb-4">

			<div class="card-body">

				<form
						method="POST"
						action="{{ route('inventory-webhooks.update') }}"
				>

					@csrf
					@method('PUT')


					<div class="form-check mb-3">

						<input
								class="form-check-input"
								type="checkbox"
								name="is_enabled"
								value="1"
								id="is_enabled"
								{{ old('is_enabled', $setting?->is_enabled) ? 'checked' : '' }}
						>

						<label
								class="form-check-label"
								for="is_enabled"
						>
							ارسال خودکار API فعال باشد
						</label>

					</div>


					<div class="mb-3">

						<label class="form-label">
							Webhook URL
						</label>

						<input
								type="url"
								name="endpoint_url"
								class="form-control"
								value="{{ old('endpoint_url', $setting?->endpoint_url) }}"
								placeholder="https://example.com/webhook/inventory"
						>

					</div>


					<div class="mb-3">

						<label class="form-label">
							Secret (اختیاری)
						</label>

						<input
								type="text"
								name="secret"
								class="form-control"
								value="{{ old('secret', $setting?->secret) }}"
						>

					</div>


					<div class="mb-3">

						<label class="form-label">
							Timeout (ثانیه)
						</label>

						<input
								type="number"
								name="timeout_seconds"
								min="1"
								max="30"
								class="form-control"
								value="{{ old('timeout_seconds', $setting?->timeout_seconds ?? 5) }}"
						>

					</div>


					<button
							class="btn btn-primary"
							{{ !($dbReady ?? false) ? 'disabled' : '' }}
					>
						ذخیره تنظیمات
					</button>

				</form>

			</div>

		</div>



		<div class="card">

			<div class="card-header">
				گزارش ارسال‌ها (آخرین 100 رکورد)
			</div>


			<div class="card-body table-responsive">

				<table
						id="inventory-webhook-logs-table"
						class="table table-striped align-middle w-100"
				></table>

			</div>

		</div>

	</div>



	@push('styles')

		<style>

			#inventory-webhook-logs-table {
				table-layout: auto !important;
				width: 100% !important;
				min-width: max-content !important;
				margin-bottom: 0 !important;
			}

			#inventory-webhook-logs-table thead th,
			#inventory-webhook-logs-table tbody td {
				text-align: center;
				vertical-align: middle;
				white-space: normal !important;
				word-break: break-word;
			}

			/* Keep badges/status pills on one line */
			#inventory-webhook-logs-table .badge {
				white-space: nowrap !important;
			}

			/* Long-text columns: wrap inside the cell instead of forcing table wider */
			#inventory-webhook-logs-table td:nth-child(3),
			#inventory-webhook-logs-table th:nth-child(3) {
				max-width: 380px;
				white-space: normal !important;
			}

			#inventory-webhook-logs-table td:nth-child(9),
			#inventory-webhook-logs-table th:nth-child(9) {
				max-width: 350px;
				white-space: normal !important;
			}

			/* Keep the cloned header and body tables in sync */
			.dt-scroll-head table,
			.dt-scroll-body table {
				table-layout: auto !important;
				margin: 0 !important;
			}

			.dt-scroll {
				overflow-x: auto !important;
				overflow-y: hidden !important;
				-webkit-overflow-scrolling: touch;
			}

			.dt-scroll-head {
				overflow: hidden !important;
			}

			.dt-scroll-body > table > thead {
				display: none !important;
			}

			.dt-scroll-head > table > thead {
				display: table-header-group !important;
				visibility: visible !important;
			}

			.dt-paging-button {
				border-radius: 10px !important;
			}

			.dt-processing {
				border-radius: 16px !important;
			}

			@media (max-width: 768px) {
				.dt-layout-row {
					flex-direction: column;
					gap: 12px;
					align-items: stretch;
				}
				.dt-info {
					text-align: center;
				}
				.dt-paging {
					justify-content: center;
				}
			}
		</style>

	@endpush



	@push('scripts')

		<script>

			$(function () {

				new DataTable('#inventory-webhook-logs-table', {

					processing: true,
					serverSide: true,
					scrollX: true,
					scrollCollapse: true,
					autoWidth: true,

					/*
					 * Original page had no search box,
					 * so keep the same functionality.
					 */
					searching: false,

					pageLength: 25,

					order: [
						[0, 'desc']
					],


					language: {

						lengthMenu: "نمایش _MENU_ مورد",

						info: "نمایش _START_ تا _END_ از _TOTAL_ مورد",

						infoEmpty: "موردی برای نمایش وجود ندارد",

						infoFiltered: "(فیلتر شده از _MAX_ مورد)",

						loadingRecords: "در حال بارگذاری...",

						processing: "در حال پردازش...",

						zeroRecords: "موردی پیدا نشد",

						emptyTable: "هنوز ارسالی ثبت نشده است.",

						paginate: {
							first: "اول",
							last: "آخر",
							next: "بعدی",
							previous: "قبلی"
						}

					},


					ajax: "{{ route('inventory-webhooks.index') }}",


					columns: [

						{
							data: 'id',
							name: 'inventory_webhook_logs.id',
							title: '#'
						},

						{
							data: 'event',
							name: 'inventory_webhook_logs.event',
							title: 'رویداد'
						},

						{
							data: 'payload_summary',
							name: 'payload_summary',
							title: 'محصول/تنوع ارسال‌شده',
							orderable: false,
							searchable: false
						},

						{
							data: 'status',
							name: 'inventory_webhook_logs.status',
							title: 'وضعیت'
						},

						{
							data: 'attempts',
							name: 'inventory_webhook_logs.attempts',
							title: 'دفعات تلاش'
						},

						{
							data: 'next_retry_at',
							name: 'inventory_webhook_logs.next_retry_at',
							title: 'تلاش بعدی'
						},

						{
							data: 'response_code',
							name: 'inventory_webhook_logs.response_code',
							title: 'کد پاسخ'
						},

						{
							data: 'sent_at',
							name: 'inventory_webhook_logs.sent_at',
							title: 'زمان ارسال'
						},

						{
							data: 'error_message',
							name: 'inventory_webhook_logs.error_message',
							title: 'خطا'
						}

					]

				});

			});

		</script>

	@endpush

@endsection