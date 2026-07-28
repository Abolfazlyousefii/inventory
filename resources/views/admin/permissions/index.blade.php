@extends('layouts.app')

@section('title', 'مدیریت نقش ها و دسترسی ها')

@php
    $permissionsCssPath = public_path('css/permissions.css');
    $permissionsJsPath = public_path('js/permissions.js');
    $permissionsCssVersion = is_file($permissionsCssPath) ? filemtime($permissionsCssPath) : '1';
    $permissionsJsVersion = is_file($permissionsJsPath) ? filemtime($permissionsJsPath) : '1';
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/permissions.css') }}?v={{ $permissionsCssVersion }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/permissions.js') }}?v={{ $permissionsJsVersion }}" defer></script>
@endpush

@section('content')
<div class="permission-shell pb-4">
    <header class="permission-page-head">
        <div>
            <h1>مدیریت نقش ها و دسترسی کاربران</h1>
            <p>دسترسی مؤثر، مجموع دسترسی نقش ها و دسترسی های مستقیم افزایشی است.</p>
        </div>
        <span class="badge text-bg-light border">دسترسی های قدیمی قابل انتساب نیستند</span>
    </header>

    @if(session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif

    @if(session('warning'))
        <div class="alert alert-warning" role="status">{{ session('warning') }}</div>
    @endif

    @if($requestedUserMissing)
        <div class="alert alert-warning" role="alert">کاربر درخواستی پیدا نشد. یک کاربر معتبر را از فهرست انتخاب کنید.</div>
    @endif

    @if($missingActivePermissionKeys !== [])
        <div class="alert alert-warning" role="alert">
            <strong>فهرست دسترسی‌های پایگاه داده با نسخه نرم‌افزار هماهنگ نیست.</strong>
            @if($canSyncPermissions)
                <div class="mt-1">دستور لازم: <code dir="ltr">php artisan permissions:sync</code></div>
            @endif
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">
                @foreach(collect($errors->all())->unique() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="card shadow-sm mb-3" aria-labelledby="user-selection-title">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-lg-9">
                    <label id="user-selection-title" for="userPicker" class="form-label fw-bold">انتخاب کاربر</label>
                    <select id="userPicker" name="user_id" class="form-select" @disabled($users->isEmpty())>
                        @forelse($users as $userOption)
                            <option value="{{ $userOption->id }}" @selected($selectedUser?->is($userOption))>
                                {{ $userOption->name }} — {{ $userOption->phone ?: $userOption->email }}
                                @if($userOption->personnel_code) — {{ $userOption->personnel_code }} @endif
                                — {{ $userOption->role_labels ?: 'بدون نقش' }}
                                — {{ $userOption->is_active ? 'فعال' : 'غیرفعال' }}
                            </option>
                        @empty
                            <option value="">هیچ کاربری در سیستم وجود ندارد</option>
                        @endforelse
                    </select>
                </div>
                <div class="col-lg-3 d-grid">
                    <button class="btn btn-outline-primary" @disabled($users->isEmpty())>بارگذاری اطلاعات</button>
                </div>
            </form>
        </div>
    </section>

    @if($selectedUser)
        <section class="permission-user-summary" aria-label="خلاصه کاربر انتخاب شده">
            @foreach([
                ['نام کاربر', $selectedUser->name],
                ['شماره موبایل', $selectedUser->phone ?: '—'],
                ['تعداد نقش', $selectedRoleCount],
                ['دسترسی مؤثر', $effectiveCount],
                ['دسترسی مستقیم', $directCount],
                ['وضعیت', $selectedUser->is_active ? 'فعال' : 'غیرفعال'],
            ] as [$label, $value])
                <div class="card">
                    <div class="card-body p-3">
                        <small>{{ $label }}</small>
                        <strong>{{ $value }}</strong>
                    </div>
                </div>
            @endforeach
        </section>

        <form id="permissionForm" method="POST" action="{{ route('admin.permissions.update', $selectedUser) }}" novalidate>
            @csrf
            @method('PUT')
            <input type="hidden" name="user_id" value="{{ $selectedUser->id }}">
            <input type="hidden" name="permission_catalog_version" value="{{ \App\Support\PermissionCatalog::versionHash() }}">
            <input type="hidden" name="direct_permissions_submitted" value="1">
            <input type="hidden" id="directPermissionsChanged" name="direct_permissions_changed" value="0">
            <input type="hidden" id="rolesChanged" name="roles_changed" value="0">
            @if($canAssignRoles)
                <input type="hidden" name="roles_submitted" value="1">
            @endif

            <section class="card mb-3" aria-labelledby="roles-title">
                <div class="card-header d-flex justify-content-between align-items-center gap-2">
                    <strong id="roles-title">نقش های کاربر</strong>
                    @unless($canAssignRoles)
                        <small class="text-muted">شما اجازه تغییر نقش ها را ندارید.</small>
                    @endunless
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        @forelse($roles as $role)
                            <div class="col-md-4 col-xl-3">
                                <label class="role-card border rounded p-3 d-block">
                                    <span class="d-flex gap-2">
                                        <input
                                            class="form-check-input role-check"
                                            type="checkbox"
                                            name="roles[]"
                                            value="{{ $role['name'] }}"
                                            @checked($role['selected'])
                                            @disabled(! $canAssignRoles)
                                        >
                                        <span class="role-card__body">
                                            <span class="role-card__title">{{ $role['label'] }}</span>
                                            <span class="role-card__key">
                                                <code>{{ $role['name'] }}</code>
                                                @if($role['legacy'])
                                                    <span class="badge text-bg-secondary">قدیمی</span>
                                                @endif
                                            </span>
                                            <small>{{ number_format($role['permissions_count']) }} دسترسی</small>
                                        </span>
                                    </span>
                                </label>
                            </div>
                        @empty
                            <div class="col-12"><p class="permission-empty">هیچ نقش سازگار با Guard سیستم وجود ندارد.</p></div>
                        @endforelse
                    </div>
                </div>
            </section>

            <section class="card mb-3" aria-labelledby="source-guide-title">
                <div class="card-body permission-source-guide">
                    <strong id="source-guide-title">راهنمای منبع:</strong>
                    <span><span class="badge text-bg-primary">از نقش</span> با تغییر Role عوض می شود</span>
                    <span><span class="badge text-bg-success">مستقیم</span> قابل ویرایش مستقیم</span>
                    <span><span class="badge text-bg-info">نقش + مستقیم</span> حذف مستقیم، دسترسی Role را نگه می دارد</span>
                    <span><span class="badge text-bg-secondary">فاقد دسترسی</span></span>
                </div>
            </section>

            <div class="row g-3">
                <aside class="col-lg-3">
                    <div class="permission-modules gap-2" id="moduleList" aria-label="فیلتر ماژول ها">
                        <button type="button" class="permission-module btn btn-primary text-start" data-module="all">
                            همه ماژول ها <span class="badge text-bg-light">{{ collect($modules)->flatten(1)->count() }}</span>
                        </button>
                        @foreach($modules as $module => $items)
                            <button type="button" class="permission-module btn btn-outline-secondary text-start" data-module="{{ $module }}">
                                {{ $items->first()['module_label'] ?? $module }}
                                <span class="badge text-bg-light">{{ $items->where('granted', true)->count() }}/{{ $items->count() }}</span>
                            </button>
                        @endforeach
                    </div>
                </aside>

                <section class="col-lg-9" aria-labelledby="permissions-table-title">
                    <div class="card">
                        <div class="card-header">
                            <label class="visually-hidden" for="permissionSearch">جستجوی دسترسی</label>
                            <input id="permissionSearch" class="form-control" placeholder="جستجو در عنوان، کلید، عملیات یا ماژول…">
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table align-middle mb-0 permission-table">
                                    <caption id="permissions-table-title" class="visually-hidden">دسترسی های قابل مدیریت کاربر</caption>
                                    <thead><tr><th>دسترسی</th><th>مستقیم</th><th>منبع مؤثر</th><th>وابستگی ها</th><th>حساسیت</th></tr></thead>
                                    <tbody>
                                        @forelse($modules as $module => $items)
                                            @foreach($items as $item)
                                                <tr
                                                    class="permission-row {{ $item['risk'] === 'critical' ? 'risk-critical' : '' }}"
                                                    data-module="{{ $module }}"
                                                    data-search="{{ $item['label'].' '.$item['key'].' '.$item['action'].' '.$item['module_label'] }}"
                                                >
                                                    <td>
                                                        <strong>{{ $item['label'] }}</strong>
                                                        <small class="technical-key d-block text-muted">{{ $item['key'] }}</small>
                                                    </td>
                                                    <td>
                                                        @if($item['source'] === 'role')
                                                            <input class="form-check-input" type="checkbox" checked disabled aria-label="{{ $item['label'] }} از نقش">
                                                        @else
                                                            <input
                                                                class="form-check-input permission-check"
                                                                type="checkbox"
                                                                name="direct_permissions[]"
                                                                value="{{ $item['key'] }}"
                                                                data-dependencies='@json($item['depends_on'])'
                                                                @checked(in_array($item['source'], ['direct', 'both'], true))
                                                                @disabled(! $canEditPermissions)
                                                                aria-label="دسترسی مستقیم {{ $item['label'] }}"
                                                            >
                                                        @endif
                                                    </td>
                                                    <td>
                                                        <span class="badge text-bg-{{ $item['source_variant'] }}">{{ $item['source_label'] }}</span>
                                                        @if($item['source'] === 'both')
                                                            <small class="d-block text-muted mt-1">با برداشتن تیک، دسترسی نقش باقی می ماند.</small>
                                                        @endif
                                                    </td>
                                                    <td><small>{{ collect($item['depends_on'])->join('، ') ?: '—' }}</small></td>
                                                    <td><span class="badge text-bg-{{ $item['risk_variant'] }}">{{ $item['risk_label'] }}</span></td>
                                                </tr>
                                            @endforeach
                                        @empty
                                            <tr><td colspan="5" class="text-center text-muted py-4">دسترسی فعالی برای نمایش وجود ندارد.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            @if($legacyPermissions->isNotEmpty())
                <section class="card mt-3" aria-labelledby="legacy-permissions-title">
                    <div class="card-header"><strong id="legacy-permissions-title">دسترسی‌های قدیمی</strong></div>
                    <div class="card-body">
                        <p class="text-muted small">این دسترسی‌ها فقط خواندنی هستند و در نسخه فعلی قابل انتساب نیستند.</p>
                        <div class="vstack gap-2">
                            @foreach($legacyPermissions as $legacyPermission)
                                <div class="border rounded p-2 d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                    <div><code>{{ $legacyPermission['key'] }}</code><small class="d-block text-muted">این دسترسی در نسخه فعلی قابل انتساب نیست.</small></div>
                                    <div><span class="badge text-bg-secondary">قدیمی</span> <span class="badge text-bg-light border">{{ in_array($legacyPermission['source'], ['direct', 'both'], true) ? 'مستقیم' : 'از نقش' }}</span></div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endif

            @if($canEditPermissions || $canAssignRoles)
                <div class="permission-savebar rounded p-3 mt-3">
                    <div>
                        <strong id="changeCount">بدون تغییر ذخیره نشده</strong>
                        <small id="dependencyCount" class="d-block text-muted"></small>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a class="btn btn-outline-secondary" href="{{ route('admin.permissions.index', ['user_id' => $selectedUser->id]) }}">انصراف</a>
                        <button id="saveButton" class="btn btn-primary" type="submit" disabled>ذخیره دسترسی ها</button>
                    </div>
                </div>
            @endif
        </form>
    @else
        <div class="card"><div class="card-body permission-empty">کاربری برای نمایش انتخاب نشده است.</div></div>
    @endif
</div>
@endsection
