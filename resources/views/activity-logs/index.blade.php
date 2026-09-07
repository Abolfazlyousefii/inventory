	@extends('layouts.app')

	@php
		use Morilog\Jalali\Jalalian;
	@endphp

	@section('content')

		<style>

			/* Main cards */
			.card.border-0 {
				border-radius: 18px !important;
				overflow: hidden;
			}


			/* Filter area */
			.card-body {
				padding: 24px;
			}


			.form-control,
			.form-select {

				border-radius: 12px;
				min-height: 44px;

			}


			.btn {

				border-radius: 12px;
				min-height: 44px;

			}



			/* Table */

			.table-responsive {

				border-radius: 18px;
				overflow-x:auto;

			}


			#activity-logs-table {

				margin-bottom:0 !important;

			}


			#activity-logs-table thead th {

				padding:16px 18px;
				white-space:nowrap;
				text-align:center;
				vertical-align:middle;

			}


			#activity-logs-table tbody td {

				padding:15px 18px;
				vertical-align:middle;
				text-align:center;

			}


			#activity-logs-table tbody tr:last-child td {

				border-bottom:none;

			}



			/* DataTables wrapper */

			.dt-container {

				padding:0;

			}


			.dt-layout-row {

				padding:14px 18px;
				align-items:center;

			}



			/* Pagination shape only */

			.dt-paging {

				display:flex;
				justify-content:flex-end;
				flex-wrap:wrap;
				gap:5px;

			}


			.dt-paging-button {

				border-radius:10px !important;
				min-width:38px;
				height:38px;

			}



			/* Loading */

			.dt-processing {

				border-radius:16px !important;

			}



			/* Mobile */

			@media(max-width:768px){


				.card-body {

					padding:16px;

				}


				.d-flex.gap-2 {

					flex-direction:column;

				}


				.d-flex.gap-2 .btn {

					width:100%;

				}


				.dt-layout-row {

					flex-direction:column;
					gap:12px;
					align-items:stretch;

				}


				.dt-info {

					text-align:center;

				}


				.dt-paging {

					justify-content:center;

				}


				#activity-logs-table {

					min-width:900px;

				}


			}

			.badge {
				border-radius: 999px;
				padding: 7px 14px;
				font-size: 12px;
				font-weight: 500;
				white-space: nowrap;
			}


		</style>

		<div class="activity-header">

			<div class="activity-title">

	        <span>
	            <i class="bi bi-clock-history"></i>
	        </span>

				لاگ فعالیت‌ها

			</div>

		</div>



		<div class="card border-0 filter-card mb-4">

			<div class="card-body">


				<form id="activity-filter-form" class="row g-3 align-items-end">

					<div class="col-md-5">

						<label class="form-label">
							جستجو
						</label>


						<input
								type="text"
								name="q"
								value="{{ request('q') }}"
								class="form-control"
								placeholder="توضیح، نوع رکورد یا شناسه رکورد..."
						>


					</div>



					<div class="col-md-3">


						<label class="form-label">
							نوع عملیات
						</label>


						<select name="action" class="form-select">


							<option value="">
								همه
							</option>


							@foreach($actions as $a)

								<option value="{{ $a }}"
										@selected(request('action') === $a)>
									{{ $a }}
								</option>

							@endforeach


						</select>


					</div>




					<div class="col-md-4 d-flex gap-2">


						<button class="btn btn-primary">
							اعمال فیلتر
						</button>


						<a href="{{ route('activity-logs.index') }}">
							حذف فیلتر
						</a>


					</div>


				</form>


			</div>

		</div>





		<div class="card border-0 table-card">


			<div class="table-responsive">


				<table
						id="activity-logs-table"
						class="table table-hover align-middle text-center mb-0">


					<thead>

					<tr>

						<th>#</th>
						<th>زمان (شمسی)</th>
						<th>کاربر</th>
						<th>عملیات</th>
						<th>رکورد</th>
						<th>شرح</th>

					</tr>


					</thead>


				</table>


			</div>


		</div>





		@push('scripts')


			<script>


				$(function () {


					new DataTable('#activity-logs-table', {


						processing:true,
						serverSide:true,
						searching: false,

						language: {
							search: "جستجو:",
							lengthMenu: "نمایش _MENU_ مورد",
							info: "نمایش _START_ تا _END_ از _TOTAL_ مورد",
							infoEmpty: "موردی برای نمایش وجود ندارد",
							infoFiltered: "(فیلتر شده از _MAX_ مورد)",
							loadingRecords: "در حال بارگذاری...",
							processing: "در حال پردازش...",
							zeroRecords: "موردی پیدا نشد",
							emptyTable: "داده‌ای برای نمایش وجود ندارد",

							paginate: {
								first: "اول",
								last: "آخر",
								next: "بعدی",
								previous: "قبلی"
							}
						},


						ajax:{
							url:"{{ route('activity-logs.index') }}",

							data:function(d){

								d.action = $('select[name="action"]').val();

								d.q = $('input[name="q"]').val();

							}
						},

						columns:[


							{
								data:'id',
								name:'id'
							},


							{
								data:'occurred_at',
								name:'occurred_at'
							},


							{
								data:'user',
								name:'user.name'
							},


							{
								data:'action_badge',
								name:'action'
							},


							{
								data:'record',
								name:'subject_type'
							},


							{
								data:'description',
								name:'description'
							}


						]


					});


				});


			</script>


		@endpush


	@endsection