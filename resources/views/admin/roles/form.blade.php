@extends('layouts.app')

@section('content')
<div class="container py-4" dir="rtl">
    <h1 class="h4 mb-3">{{ $role->exists ? 'ویرایش نقش' : 'ایجاد نقش' }}</h1>
    <form method="POST" action="{{ $role->exists ? route('admin.roles.update', $role) : route('admin.roles.store') }}" class="card border-0 shadow-sm">
        @csrf
        @if($role->exists) @method('PUT') @endif
        <div class="card-body">
            <div class="mb-4">
                <label class="form-label">نام سیستمی نقش</label>
                <input name="name" value="{{ old('name', $role->name) }}" class="form-control @error('name') is-invalid @enderror" {{ in_array($role->name, $protectedRoleNames, true) ? 'readonly' : '' }}>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="alert alert-info small">هر گزینه دسترسی کامل به همان صفحه و عملیات وابسته مانند ثبت، ویرایش، جست‌وجو، چاپ و درخواست‌های داخلی را فراهم می‌کند.</div>
            <div class="row g-3">
                @foreach($permissions as $group => $items)
                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-bold">{{ $group ?: 'سایر' }}</span>
                                <span class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-secondary" data-page-group-select>انتخاب همه</button>
                                    <button type="button" class="btn btn-outline-secondary" data-page-group-clear>لغو همه</button>
                                </span>
                            </div>
                            @foreach($items as $permission)
                                <label class="d-flex gap-2 mb-2 small">
                                    <input type="checkbox" name="permissions[]" value="{{ $permission->id }}" data-permission-key="{{ $permission->key }}" data-page-permission @checked(in_array($permission->id, old('permissions', $selectedPermissionIds), true))>
                                    <span><strong>{{ $permission->name }}</strong><small class="d-block text-muted">{{ \App\Support\PageAccessCatalog::page(str($permission->key)->after('page.')->toString())['description'] ?? '' }}</small></span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="border rounded-3 p-3 mt-4" data-commission-action-permissions>
                <h2 class="h6">عملیات حساس پورسانت</h2>
                <p class="small text-muted">این مجوزها فقط عملیات مدیریتی را فعال می‌کنند و به‌تنهایی اجازه ورود به صفحه پورسانت نمی‌دهند.</p>
                <div class="row g-2">
                    @foreach($commissionActionPermissions as $permission)
                        <label class="col-md-4 d-flex gap-2 small">
                            <input type="checkbox" name="permissions[]" value="{{ $permission->id }}" data-permission-key="{{ $permission->key }}" data-page-permission @checked(in_array($permission->id, old('permissions', $selectedPermissionIds), true))>
                            <span>{{ $permission->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="border rounded-3 p-3 mt-4" data-asset-action-permissions>
                <h2 class="h6">عملیات امین اموال</h2>
                <p class="small text-muted">این مجوزها عملیات ثبت، ویرایش، نهایی‌سازی، لغو، چاپ/دانلود و جستجوی کد اموال را کنترل می‌کنند. برای ورود به بخش، دسترسی کلی «امین اموال» هم باید فعال باشد.</p>
                <div class="row g-2">
                    @foreach($assetActionPermissions as $permission)
                        <label class="col-md-4 d-flex gap-2 small">
                            <input type="checkbox" name="permissions[]" value="{{ $permission->id }}" data-permission-key="{{ $permission->key }}" data-page-permission @checked(in_array($permission->id, old('permissions', $selectedPermissionIds), true))>
                            <span>{{ $permission->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="card-footer bg-white d-flex gap-2 align-items-center">
            <button class="btn btn-primary">ذخیره</button>
            <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary">بازگشت</a>
            <span class="text-muted small me-auto">دسترسی‌های فعال: <strong data-page-selected-count>0</strong></span>
        </div>
    </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const boxes = [...document.querySelectorAll('[data-page-permission]')];
    const count = document.querySelector('[data-page-selected-count]');
    const refresh = () => { count.textContent = boxes.filter(box => box.checked).length; };
    document.querySelectorAll('[data-page-group-select], [data-page-group-clear]').forEach(button => {
        button.addEventListener('click', () => {
            const checked = button.hasAttribute('data-page-group-select');
            button.closest('.border').querySelectorAll('[data-page-permission]').forEach(box => { box.checked = checked; });
            refresh();
        });
    });
    boxes.forEach(box => box.addEventListener('change', refresh));
    refresh();
});
</script>
@endsection
