@extends('layouts.app')

@section('content')

	<div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">

		<div>
			<h4 class="mb-0">👤 کاربران</h4>

			<div class="text-muted small">
				لیست کاربران سینک‌شده از CRM و کاربران داخلی
			</div>
		</div>


		@canPermission('users.sync')

		<form method="POST" action="{{ route('users.sync') }}">
			@csrf

			<button type="submit" class="btn btn-primary">
				🔄 همگام‌سازی با CRM
			</button>
		</form>

		@endcanPermission

	</div>


	@if(session('sync_success'))

		<div class="alert alert-success">
			{{ session('sync_success') }}
		</div>

	@endif


	@if(session('sync_error'))

		<div class="alert alert-danger">
			{{ session('sync_error') }}
		</div>

	@endif



	<div class="card shadow-sm">

		<div class="card-header bg-white">

			<form
					method="GET"
					action="{{ route('users.index') }}"
					class="row g-2"
			>

				<div class="col-md-4">

					<input
							type="text"
							name="filter_search"
							value="{{ request('filter_search') }}"
							class="form-control"
							placeholder="جستجو بر اساس نام یا موبایل"
					>

				</div>


				<div class="col-md-3">

					<input
							type="text"
							name="role"
							value="{{ request('role') }}"
							class="form-control"
							placeholder="فیلتر بر اساس role"
					>

				</div>


				<div class="col-md-3">

					<select
							name="status"
							class="form-select"
					>

						<option value="">
							همه وضعیت‌ها
						</option>

						<option
								value="active"
								@selected(request('status') === 'active')
						>
							فعال
						</option>

						<option
								value="inactive"
								@selected(request('status') === 'inactive')
						>
							غیرفعال
						</option>

					</select>

				</div>


				<div class="col-md-2 d-grid">

					<button
							type="submit"
							class="btn btn-outline-secondary"
					>
						اعمال فیلتر
					</button>

				</div>

			</form>

		</div>


		<div class="card-body p-0">
			<table id="users-table" class="table table-striped table-hover mb-0 align-middle"></table>
		</div>

	</div>

@endsection



@push('scripts')

	<script>

		$(function () {

			new DataTable('#users-table', {

				processing: true,
				serverSide: true,

				scrollX: true,
				scrollCollapse: true,
				searching: false,
				autoWidth: false,
				pageLength: 10,

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

					emptyTable: "کاربری برای نمایش وجود ندارد",

					paginate: {
						first: "اول",
						last: "آخر",
						next: "بعدی",
						previous: "قبلی"
					}

				},


				ajax: {

					url: "{{ route('users.index') }}",

					data: function (data) {

						data.filter_search =
						@json(request('filter_search'));

						data.role =
						@json(request('role'));

						data.status =
						@json(request('status'));

					}

				},


				columns: [

					{
						data: 'id',
						name: 'id',
						title: 'شناسه داخلی'
					},

					{
						data: 'crm_id',
						name: 'crm_user_id',
						title: 'شناسه CRM'
					},

					{
						data: 'name',
						name: 'name',
						title: 'نام'
					},

					{
						data: 'phone',
						name: 'phone',
						title: 'موبایل'
					},

					{
						data: 'email',
						name: 'email',
						title: 'ایمیل'
					},

					{
						data: 'username',
						name: 'username',
						title: 'نام کاربری'
					},

					{
						data: 'manager_name',
						name: 'manager_name',
						title: 'مدیر',
						orderable: false,
						searchable: false
					},

					{
						data: 'roles_list',
						name: 'roles_list',
						title: 'نقش‌ها',
						orderable: false,
						searchable: false
					},

					{
						data: 'source_badge',
						name: 'source_badge',
						title: 'منبع',
						orderable: false,
						searchable: false
					},

					{
						data: 'status_badge',
						name: 'status_badge',
						title: 'وضعیت',
						orderable: false,
						searchable: false
					},

					{
						data: 'synced_at',
						name: 'synced_at',
						title: 'آخرین sync'
					}

				]

			});
			$('#users-table').on('change', '.user-status', function () {

				let select = $(this);

				let userId = select.data('user-id');

				let status = select.val();


				if (status == 1) {
					select
						.removeClass('inactive')
						.addClass('active');
				} else {
					select
						.removeClass('active')
						.addClass('inactive');
				}


				$.ajax({

					url: `/users/${userId}/status`,

					method: 'PATCH',

					data: {
						_token: '{{ csrf_token() }}',
						is_active: status
					},


					success: function () {

						console.log('Status updated');

					},


					error: function (xhr) {

						console.log(xhr.responseText);

						alert('خطا در تغییر وضعیت');

					}

				});

			});
		});

	</script>

@endpush



@push('styles')

<style>
	.status-pill {
		border-radius: 20px !important;
		border: none !important;
		padding: 5px 25px 5px 12px !important;
		font-size: 13px;
		cursor: pointer;
		font-weight: 500;
	}

	.status-pill.active {
		background-color: #d9f7e3 !important;
		color: #218739 !important;
	}

	.status-pill.inactive {
		background-color: #fde0e0 !important;
		color: #c62828 !important;
	}

	.status-pill:focus {
		box-shadow: none !important;
	}

	/* Only the table body scrolls horizontally, NOT the pagination */
	.dt-scroll-head {
		overflow: hidden !important;
	}

		/* Hide the duplicate header inside the scroll body — the one in .dt-scroll-head is the real one */
	.dt-scroll-body > .dt-scroll-head,
	.dt-scroll-body thead {
		display: none !important;
	}

	/* OR more explicitly: */
	table.dataTable > thead {
		/* nothing */
	}
	.dt-scroll-body table.dataTable > thead {
		display: none !important;
	}

	/* Hide the original thead that lives inside the scrollable body —
   DataTables shows a cloned copy in .dt-scroll-head instead */
	.dt-scroll-body > table > thead {
		display: none !important;
	}

	/* Make sure the cloned header is visible and styled normally */
	.dt-scroll-head > table > thead {
		display: table-header-group !important;
		visibility: visible !important;
	}


	/* Your existing styles below... */
	.user-status {
		border-radius: 20px !important;
		border: none !important;
		padding: 4px 30px 4px 12px !important;
		font-size: 13px;
		cursor: pointer;
		background-color: #f1f3f5;
		box-shadow: none !important;
	}

	.user-status:focus {
		box-shadow: none !important;
		outline: none !important;
	}

	#users-table {
		width: 100% !important;
		table-layout: fixed !important;
	}

	#users-table th,
	#users-table td {
		white-space: normal !important;
		word-wrap: break-word;
		overflow-wrap: break-word;
		vertical-align: middle;
	}

	#users-table tbody td {
		vertical-align: middle;
	}

	#users-table .badge,
	#users-table .status-pill,
	#users-table .user-status {
		white-space: nowrap !important;
	}

	.dt-paging {
		display: flex;
		justify-content: flex-end;
		flex-wrap: wrap;
		gap: 5px;
	}

	.dt-paging-button {
		border-radius: 10px !important;
		min-width: 38px;
		height: 38px;
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

	/* Both the header table and body table must use the same layout */
	.dt-scroll-head table,


	/* Remove any borders/padding differences that shift widths */
	.dt-scroll-head table > thead > tr > th,
	.dt-scroll-body table > tbody > tr > td {
		box-sizing: border-box !important;
	}

	/* Kill the extra scrollbar space DataTables leaves in the header */
	.dt-scroll-head {
		overflow: hidden !important;
	}
</style>
@endpush