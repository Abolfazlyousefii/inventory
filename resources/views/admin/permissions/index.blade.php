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

@section('title', 'مدیریت نقش‌ها و دسترسی‌ها')

@section('content')
<div class="access-page">
    <div class="access-hero">
        <h1>🔐 مرکز مدیریت نقش و دسترسی</h1>
        <p>نقش‌های کاربر و دسترسی‌های مستقیم را در یک صفحه مدیریت کنید.</p>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('warning'))<div class="alert alert-warning">{{ session('warning') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ collect($errors->all())->unique()->join(' ') }}</div>@endif
    @if($requestedUserMissing)<div class="alert alert-warning">کاربر درخواستی پیدا نشد؛ یک کاربر معتبر را انتخاب کنید.</div>@endif
    @if($missingActivePermissionKeys !== [])<div class="alert alert-warning">فهرست دسترسی‌های پایگاه داده با نسخه نرم‌افزار هماهنگ نیست.</div>@endif

    <div class="access-card">
        <h3>👤 انتخاب کاربر</h3>
        <select class="form-select" onchange="location='?user_id='+this.value" @disabled($users->isEmpty())>
            @forelse($users as $user)
                <option value="{{ $user->id }}" @selected($selectedUser?->id === $user->id)>
                    {{ $user->name }} — {{ $user->role_labels ?: 'بدون نقش' }} — {{ $user->is_active ? 'فعال' : 'غیرفعال' }}
                </option>
            @empty
                <option value="">هیچ کاربری وجود ندارد</option>
            @endforelse
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
            <input type="hidden" name="direct_permissions_submitted" value="1">

            <div class="access-card">
                <h3>🛡 نقش‌های {{ $selectedUser->name }}</h3>
                <div class="roles-grid">
                    @forelse($roles as $role)
                        <label class="role-card {{ $role['selected'] ? 'selected' : '' }}">
                            <input
                                class="role-check"
                                type="checkbox"
                                name="roles[]"
                                value="{{ $role['name'] }}"
                                @checked($role['selected'])
                                @disabled(! $canAssignRoles)
                            >
                            <b>{{ $role['label'] }}</b>
                            <span>{{ $role['permissions_count'] }} دسترسی</span>
                            @if($role['legacy'])
                                <small class="d-block text-muted mt-2">نقش قدیمی</small>
                            @endif
                        </label>
                    @empty
                        <p class="text-muted">نقشی تعریف نشده است.</p>
                    @endforelse
                </div>
            </div>

            <div class="access-card">
                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
                    <div>
                        <h3 class="mb-1">🔎 دسترسی‌های مستقیم</h3>
                        <small class="text-muted">
                            دسترسی‌های نقش به صورت خودکار مؤثر هستند؛ این بخش فقط دسترسی مستقیم افزایشی کاربر را مدیریت می‌کند.
                        </small>
                    </div>
                    @unless($canEditPermissions)
                        <span class="badge text-bg-secondary">فقط خواندنی</span>
                    @endunless
                </div>

                <input id="permissionSearch" class="form-control mb-3" placeholder="جستجو در دسترسی‌ها">

                <div class="permission-toolbar">
                    <button type="button" class="permission-module btn btn-primary" data-module="all">همه</button>
                    @foreach($modules as $module => $items)
                        <button type="button" class="permission-module btn btn-outline-secondary" data-module="{{ $module }}">
                            {{ $items->first()['module_label'] ?? $module }}
                        </button>
                    @endforeach
                </div>

                @forelse($modules as $module => $items)
                    <section class="permission-module-section" data-module="{{ $module }}">
                        <h5 class="mt-4 mb-3">{{ $items->first()['module_label'] ?? $module }}</h5>

                        @foreach($items as $item)
                            <div
                                class="permission-row"
                                data-module="{{ $module }}"
                                data-search="{{ $item['label'].' '.$item['key'].' '.$item['action'].' '.$item['module_label'] }}"
                            >
                                <input
                                    class="permission-check"
                                    type="checkbox"
                                    name="direct_permissions[]"
                                    value="{{ $item['key'] }}"
                                    data-dependencies='@json($item['depends_on'])'
                                    @checked(in_array($item['source'], ['direct', 'both'], true))
                                    @disabled(! $canEditPermissions)
                                >

                                <label>
                                    <span class="d-block">{{ $item['label'] }}</span>
                                    <small class="text-muted">{{ $item['key'] }}</small>
                                </label>

                                <span class="badge text-bg-{{ $item['source_variant'] }}">{{ $item['source_label'] }}</span>
                            </div>
                        @endforeach
                    </section>
                @empty
                    <p class="text-muted">دسترسی فعالی برای نمایش وجود ندارد.</p>
                @endforelse

                @if($legacyPermissions->isNotEmpty())
                    <div class="alert alert-warning small mt-3 mb-0">
                        {{ $legacyPermissions->count() }} دسترسی قدیمی فقط برای سازگاری نگهداری شده و از این فرم قابل ایجاد نیست.
                    </div>
                @endif
            </div>

            @if($canEditPermissions || $canAssignRoles)
                <div class="permission-savebar">
                    <div>
                        <span id="changeCount">بدون تغییر ذخیره نشده</span>
                        <small id="dependencyCount" class="d-block text-muted"></small>
                    </div>
                    <button id="saveButton" class="btn btn-primary" type="submit" disabled>ذخیره تغییرات</button>
                </div>
            @endif
        </form>
    @endif
</div>
@endsection
