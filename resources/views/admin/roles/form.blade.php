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
                <input name="name" value="{{ old('name', $role->name) }}" class="form-control @error('name') is-invalid @enderror" {{ in_array($role->name, ['super_admin','admin','staff','editor','union_expert','user','employee'], true) ? 'readonly' : '' }}>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="alert alert-info small">هر گزینه دسترسی کامل به همان صفحه و عملیات وابسته مانند ثبت، ویرایش، جست‌وجو، چاپ و درخواست‌های داخلی را فراهم می‌کند.</div>
            <div class="row g-3">
                @foreach($permissions as $group => $items)
                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="fw-bold mb-2">{{ $group ?: 'سایر' }}</div>
                            @foreach($items as $permission)
                                <label class="d-flex gap-2 mb-2 small">
                                    <input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked(in_array($permission->id, old('permissions', $selectedPermissionIds), true))>
                                    <span><strong>{{ $permission->name }}</strong><small class="d-block text-muted">{{ \App\Support\PageAccessCatalog::page(str($permission->key)->after('page.')->toString())['description'] ?? '' }}</small></span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="card-footer bg-white d-flex gap-2">
            <button class="btn btn-primary">ذخیره</button>
            <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary">بازگشت</a>
        </div>
    </form>
</div>
@endsection
