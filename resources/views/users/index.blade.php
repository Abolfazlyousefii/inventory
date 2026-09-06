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

			<form method="GET" action="{{ route('users.index') }}" class="row g-2">

				<div class="col-md-4">

					<input type="text" name="filter_search" value="{{ request('filter_search') }}" class="form-control" placeholder="جستجو بر اساس نام یا موبایل">

				</div>

				<div class="col-md-3">

					<input type="text" name="role" value="{{ request('role') }}" class="form-control" placeholder="فیلتر بر اساس role">

				</div>

				<div class="col-md-3">

					<select name="status" class="form-select">

						<option value="">
							همه وضعیت‌ها
						</option>

						<option value="active"
								@selected(request('status') === 'active')
						>
							فعال
						</option>

						<option value="inactive"
								@selected(request('status') === 'inactive')
						>
							غیرفعال
						</option>

					</select>

				</div>

				<div class="col-md-2 d-grid">

					<button type="submit" class="btn btn-outline-secondary">
						اعمال فیلتر
					</button>

				</div>

			</form>

		</div>

		<div class="card-body p-0">

			<div class="table-responsive">

				{!! $dataTable->table([
					'class' => 'table table-striped table-hover mb-0 align-middle'
				]) !!}

			</div>

		</div>

	</div>

@endsection


@push('scripts')
	{!! $dataTable->scripts() !!}
@endpush


@push('styles')

	<style>

		.dt-paging {
			display         : flex;
			justify-content : flex-end;
			flex-wrap       : wrap;
			gap             : 5px;
		}

		.dt-paging-button {
			border-radius : 10px !important;
			min-width     : 38px;
			height        : 38px;
		}

	</style>

@endpush