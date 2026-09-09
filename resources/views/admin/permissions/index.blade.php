@extends('layouts.app')

@push('styles')
	<link rel="stylesheet" href="{{ asset('css/permissions.css') }}">
@endpush

@section('title','مدیریت دسترسی کاربران')

@section('content')
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
