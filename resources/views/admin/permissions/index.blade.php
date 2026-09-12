@extends('layouts.app')

@push('styles')
	<link rel="stylesheet" href="{{ asset('css/permissions.css') }}">
@endpush

@section('title','مدیریت دسترسی کاربران')

@section('content')

	<style>
		/* BARON modern dashboard redesign layer */
		.access-page, .roles-page {
			padding:30px;
			background:#f6f7fb;
			min-height:100%;
		}
		.access-hero, .roles-hero {
			background:linear-gradient(135deg,#111827,#374151);
			color:#fff;
			border-radius:28px;
			padding:35px;
			box-shadow:0 20px 50px rgba(0,0,0,.12);
		}
		.access-card, .role-card, .permission-group {
			background:#fff;
			border-radius:22px;
			border:1px solid #e8ebf2;
			box-shadow:0 10px 35px rgba(0,0,0,.06);
			transition:.25s;
		}
		.access-card:hover, .role-card:hover, .permission-group:hover {
			transform:translateY(-4px);
			box-shadow:0 20px 45px rgba(0,0,0,.1);
		}
		.roles-grid, .roles-grid, .permission-module-section {
			gap:20px;
		}
		.role-card {
			padding:24px;
		}
		.btn {
			border-radius:14px !important;
		}
		.form-control, .form-select {
			border-radius:14px;
			padding:12px 15px;
		}
	</style>
	<div class="access-page">

		<div class="access-hero">
			<h1>🔐 مرکز مدیریت دسترسی</h1>
			<p>مدیریت نقش‌ها و مجوزهای کاربران سیستم</p>
		</div>

		<form method="POST" id="permissionForm"
		      action="{{ $selectedUser ? route('admin.permissions.update',$selectedUser) : '#' }}">

			@csrf
			@method('PUT')

			<input type="hidden" name="user_id" value="{{ $selectedUser?->id }}">
			<input type="hidden" id="rolesChanged" name="roles_changed" value="0">
			<input type="hidden" id="directPermissionsChanged" name="direct_permissions_changed" value="0">
			<input type="hidden" name="roles_submitted" value="1">
			<input type="hidden" name="direct_permissions_submitted" value="1">

			<div class="access-card">
				<h3>👤 انتخاب کاربر</h3>
				<select class="form-select" onchange="location='?user_id='+this.value">
					@foreach($users as $user)
						<option value="{{ $user->id }}" @selected($selectedUser?->id === $user->id)>
							{{ $user->name }}
						</option>
					@endforeach
				</select>
			</div>

			<div class="access-card">
				<h3>🛡 نقش‌ها</h3>
				<div class="roles-grid">
					@foreach($roles as $role)
						<label class="role-card {{ $role['selected']?'selected':'' }}">
							<input class="role-check" type="checkbox" name="roles[]" value="{{ $role['name'] }}" @checked($role['selected'])>
							<b>{{ $role['label'] }}</b>
							<span>{{ $role['permissions_count'] }} دسترسی</span>
						</label>
					@endforeach
				</div>
			</div>

			<div class="access-card">
				<h3>🔎 دسترسی‌ها</h3>

				<input id="permissionSearch" class="form-control mb-3" placeholder="جستجو">



				@foreach($modules as $module=>$items)

					<div class="permission-module-section" data-module="{{ $module }}">

						<h4>{{ $module }}</h4>

						@foreach($items as $key=>$permission)
							<div class="permission-row" data-module="{{ $module }}">
								<label>
									<input class="permission-check"
									       type="checkbox"
									       name="direct_permissions[]"
									       value="{{ $key }}"
											@checked($permission['granted'] ?? false)>

									{{ $permission['label'] ?? $key }}
								</label>
							</div>
						@endforeach

					</div>

				@endforeach

			</div>

			<div class="permission-savebar">
				<span id="changeCount">بدون تغییر ذخیره نشده</span>
				<span id="dependencyCount"></span>
				<button id="saveButton" class="btn btn-primary" disabled>ذخیره تغییرات</button>
			</div>

		</form>
	</div>
@endsection
