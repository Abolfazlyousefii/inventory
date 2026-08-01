@extends('layouts.app')

@section('title', 'مدیریت نقش‌های کاربران')

@php
    $permissionsCssPath = public_path('css/permissions.css');
    $permissionsJsPath = public_path('js/permissions.js');
@endphp
@push('styles')<link rel="stylesheet" href="{{ asset('css/permissions.css') }}?v={{ is_file($permissionsCssPath) ? filemtime($permissionsCssPath) : 1 }}">@endpush
@push('scripts')<script src="{{ asset('js/permissions.js') }}?v={{ is_file($permissionsJsPath) ? filemtime($permissionsJsPath) : 1 }}" defer></script>@endpush

@section('content')
<div class="container py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h1 class="h4 mb-1">مدیریت نقش‌های کاربران</h1><p class="text-muted mb-0">دسترسی صفحات فقط از نقش‌های کاربر محاسبه می‌شود.</p></div>
        <a class="btn btn-outline-primary" href="{{ route('admin.roles.index') }}">مدیریت نقش‌ها و صفحات</a>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ collect($errors->all())->unique()->join(' ') }}</div>@endif
    @if($requestedUserMissing)<div class="alert alert-warning">کاربر درخواستی پیدا نشد؛ یک کاربر معتبر را انتخاب کنید.</div>@endif
    @if($missingActivePermissionKeys !== [])<div class="alert alert-warning">فهرست دسترسی‌های پایگاه داده با نسخه نرم‌افزار هماهنگ نیست.</div>@endif

    <div class="card mb-3"><div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-9"><label class="form-label" for="userPicker">کاربر</label><select id="userPicker" name="user_id" class="form-select">
                @foreach($users as $userOption)<option value="{{ $userOption->id }}" @selected($selectedUser?->is($userOption))>{{ $userOption->name }} — {{ $userOption->role_labels ?: 'بدون نقش' }}</option>@endforeach
            </select></div><div class="col-md-3 d-grid"><button class="btn btn-outline-primary">نمایش نقش‌ها</button></div>
        </form>
    </div></div>

    @if($selectedUser)
        <form method="POST" action="{{ route('admin.permissions.update', $selectedUser) }}" class="card">
            @csrf @method('PUT')
            <input type="hidden" name="user_id" value="{{ $selectedUser->id }}">
            <input type="hidden" name="permission_catalog_version" value="{{ \App\Support\PermissionCatalog::versionHash() }}">
            <input type="hidden" name="roles_changed" value="1">
            <input type="hidden" name="roles_submitted" value="1">
            <input type="hidden" name="direct_permissions_changed" value="0">
            <div class="card-header"><strong>نقش‌های {{ $selectedUser->name }}</strong></div>
            <div class="card-body"><div class="row g-3">
                @forelse($roles as $role)
                    <div class="col-md-4"><label class="border rounded p-3 d-flex gap-2 h-100">
                        <input class="form-check-input" type="checkbox" name="roles[]" value="{{ $role['name'] }}" @checked($role['selected']) @disabled(! $canAssignRoles)>
                        <span><strong>{{ $role['label'] }}</strong><small class="d-block text-muted">{{ $role['permissions_count'] }} صفحه مجاز</small></span>
                    </label></div>
                @empty <p class="text-muted">نقشی تعریف نشده است.</p> @endforelse
            </div></div>
            @if($legacyPermissions->isNotEmpty())<div class="card-footer bg-warning-subtle small">{{ $legacyPermissions->count() }} دسترسی مستقیم/قدیمی فعلاً فقط برای مهاجرت نگهداری شده و از این صفحه قابل تخصیص نیست.</div>@endif
            @if($canAssignRoles)<div class="card-footer bg-white"><button class="btn btn-primary">ذخیره نقش‌های کاربر</button></div>@endif
        </form>
    @endif
</div>
@endsection
