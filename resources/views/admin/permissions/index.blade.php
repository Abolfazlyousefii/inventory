@extends('layouts.app')

@php
	$permissionsCssPath = public_path('css/permissions.css');
	$permissionsJsPath = public_path('js/permissions.js');
@endphp

@push('styles')
	<link rel="stylesheet" href="{{ asset('css/permissions.css') }}?v={{ is_file($permissionsCssPath) ? filemtime($permissionsCssPath) : 1 }}">
@endpush
@push('scripts')
	<script src="{{ asset('js/permissions.js') }}?v={{ is_file($permissionsJsPath) ? filemtime($permissionsJsPath) : 1 }}" defer></script>
@endpush

@section('title','مدیریت دسترسی کاربران')

@section('content')
	<div class="access-page">

		<div class="access-hero">
			<h1>🔐 مرکز مدیریت دسترسی</h1>
			<p>دسترسی صفحات فقط از نقش‌های کاربر محاسبه می‌شود.</p>
		</div>

		@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
		@if(session('warning'))<div class="alert alert-warning">{{ session('warning') }}</div>@endif
		@if($errors->any())<div class="alert alert-danger">{{ collect($errors->all())->unique()->join(' ') }}</div>@endif
		@if($requestedUserMissing)<div class="alert alert-warning">کاربر درخواستی پیدا نشد؛ یک کاربر معتبر را انتخاب کنید.</div>@endif
		@if($missingActivePermissionKeys !== [])<div class="alert alert-warning">فهرست دسترسی‌های پایگاه داده با نسخه نرم‌افزار هماهنگ نیست.</div>@endif

		<div class="access-card">
			<h3>👤 انتخاب کاربر</h3>
			<select class="form-select" onchange="location='?user_id='+this.value">
				@foreach($users as $user)
					<option value="{{ $user->id }}" @selected($selectedUser?->id === $user->id)>
						{{ $user->name }} — {{ $user->role_labels ?: 'بدون نقش' }}
					</option>
				@endforeach
			</select>
		</div>

		@if($selectedUser)
			<form method="POST" id="permissionForm" action="{{ route('admin.permissions.update', $selectedUser) }}">
				@csrf
				@method('PUT')

				<input type="hidden" name="user_id" value="{{ $selectedUser->id }}">
				<input type="hidden" name="permission_catalog_version" value="{{ \App\Support\PermissionCatalog::versionHash() }}">
				<input type="hidden" id="rolesChanged" name="roles_changed" value="0">
				<input type="hidden" name="roles_submitted" value="1">
				<input type="hidden" id="directPermissionsChanged" name="direct_permissions_changed" value="0">

				<div class="access-card">
					<h3>🛡 نقش‌های {{ $selectedUser->name }}</h3>
					<div class="roles-grid">
						@forelse($roles as $role)
							<label class="role-card {{ $role['selected'] ? 'selected' : '' }}">
								<input class="role-check" type="checkbox" name="roles[]" value="{{ $role['name'] }}" @checked($role['selected']) @disabled(! $canAssignRoles)>
								<b>{{ $role['label'] }}</b>
								<span>{{ $role['permissions_count'] }} صفحه مجاز</span>
							</label>
						@empty
							<p class="text-muted">نقشی تعریف نشده است.</p>
						@endforelse
					</div>
					@if($legacyPermissions->isNotEmpty())
						<div class="alert alert-warning small mt-3 mb-0">{{ $legacyPermissions->count() }} دسترسی مستقیم/قدیمی فعلاً فقط برای مهاجرت نگهداری شده و از این صفحه قابل تخصیص نیست.</div>
					@endif
				</div>

				@if($canAssignRoles)
					<div class="permission-savebar">
						<span id="changeCount">بدون تغییر ذخیره نشده</span>
						<button id="saveButton" class="btn btn-primary" disabled>ذخیره نقش‌های کاربر</button>
					</div>
				@endif
			</form>
		@endif
	</div>
@endsection
