@extends('layouts.app')

@php
	use Morilog\Jalali\Jalalian;
@endphp

@push('styles')
	<link rel="stylesheet" href="{{ asset('lib/datatables.bootstrap5.min.css') }}">
@endpush

@section('content')

	<div class="d-flex justify-content-between align-items-center mb-3">
		<h4 class="mb-0">لاگ فعالیت‌ها</h4>
	</div>


	<div class="card border-0 shadow-sm mb-3">
		<div class="card-body">

			<form method="GET" id="activity-logs-filter" class="row g-2 align-items-end">

				<div class="col-md-5">
					<label class="form-label">جستجو</label>

					<input
							type="text"
							name="q"
							value="{{ request('q') }}"
							class="form-control"
							placeholder="توضیح، نوع رکورد یا شناسه رکورد..."
					>
				</div>


				<div class="col-md-3">

					<label class="form-label">نوع عملیات</label>

					<select name="action" class="form-select">

						<option value="">همه</option>

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

					<a href="{{ route('activity-logs.index') }}"
					   class="btn btn-outline-secondary">
						حذف فیلتر
					</a>

				</div>

			</form>

		</div>
	</div>



	<div class="card border-0 shadow-sm">

		<div class="table-responsive">

			<table
					id="activity-logs-table"
					class="table table-striped table-hover align-middle mb-0">

				<thead class="table-light">

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

		<script src="{{ asset('lib/datatables.min.js') }}"></script>
		<script src="{{ asset('lib/datatables.bootstrap5.min.js') }}"></script>

		<script>

			$(function () {

				var table = $('#activity-logs-table').DataTable({

					processing: true,
					serverSide: true,
					order: [[1, 'desc']],

					ajax: {
						url: "{{ route('activity-logs.index') }}",
						data: function (d) {
							d.q = $('#activity-logs-filter [name="q"]').val();
							d.action = $('#activity-logs-filter [name="action"]').val();
						}
					},

					columns: [

						{
							data: 'id',
							name: 'id'
						},

						{
							data: 'occurred_at',
							name: 'occurred_at'
						},

						{
							data: 'user',
							name: 'user',
							orderable: false
						},

						{
							data: 'action_badge',
							name: 'action'
						},

						{
							data: 'record',
							name: 'subject_type'
						},

						{
							data: 'description',
							name: 'description'
						}

					]

				});

				// Apply the q/action filters through the server-side AJAX request
				// instead of reloading the whole page.
				$('#activity-logs-filter').on('submit', function (e) {
					e.preventDefault();
					table.ajax.reload();
				});

				$('#activity-logs-filter').on('reset', function () {
					window.setTimeout(function () {
						table.ajax.reload();
					}, 0);
				});

			});

		</script>

	@endpush


@endsection