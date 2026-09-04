@extends('layouts.app')

@php
	use Morilog\Jalali\Jalalian;
@endphp

@section('content')

	<div class="d-flex justify-content-between align-items-center mb-3">
		<h4 class="mb-0">لاگ فعالیت‌ها</h4>
	</div>


	<div class="card border-0 shadow-sm mb-3">
		<div class="card-body">

			<form method="GET" class="row g-2 align-items-end">

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

		<script>

			$(function () {

				$('#activity-logs-table').DataTable({

					processing: true,
					serverSide: true,

					ajax: "{{ route('activity-logs.index') }}",

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
							name: 'user.name'
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

			});

		</script>

	@endpush


@endsection