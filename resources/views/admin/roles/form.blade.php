@extends('layouts.app')

@section('content')
	<style>

		.role-form-title{
			font-size:25px;
			font-weight:900;
		}


		.role-form-card{
			border-radius:24px;
			overflow:hidden;
			border:0;
			box-shadow:0 15px 40px rgba(0,0,0,.07);
		}



		.permission-group{

			background:white;
			border:1px solid #edf0f5;
			border-radius:20px;
			padding:18px;
			height:100%;
			transition:.2s;

		}


		.permission-group:hover{

			transform:translateY(-3px);
			box-shadow:0 12px 25px rgba(0,0,0,.06);

		}



		.permission-header{

			background:#f8fafc;
			border-radius:14px;
			padding:12px;
			margin-bottom:15px;

		}



		.permission-item{

			padding:10px;
			border-radius:12px;
			transition:.15s;

		}



		.permission-item:hover{

			background:#f8f9fa;

		}


		.permission-item input{

			width:18px;
			height:18px;

		}


		.permission-title{

			font-weight:700;

		}


		.permission-description{

			font-size:12px;
			color:#6c757d;

		}



		.special-box{

			border-radius:20px;
			background:#fffaf0;
			border:1px solid #ffe8b5;

		}


	</style>

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
	                    <div class="permission-group">
		                    <div class="permission-header d-flex justify-content-between align-items-center">
			                    <span class="fw-bold">{{ $group ?: 'سایر' }}</span>
                                <span class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-secondary" data-page-group-select>انتخاب همه</button>
                                    <button type="button" class="btn btn-outline-secondary" data-page-group-clear>لغو همه</button>
                                </span>
                            </div>
                            @foreach($items as $permission)
			                    <label class="permission-item d-flex gap-2 mb-2 small">
				                    <input type="checkbox" name="permissions[]" value="{{ $permission->id }}" data-permission-key="{{ $permission->key }}" data-page-permission @checked(in_array($permission->id, old('permissions', $selectedPermissionIds), true))>
                                    <span><div class="permission-title">{{ $permission->name }}
</div>

<small class="permission-description d-block">{{ \App\Support\PageAccessCatalog::page(str($permission->key)->after('page.')->toString())['description'] ?? '' }}</small></span>
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
