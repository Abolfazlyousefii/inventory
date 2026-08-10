@extends('layouts.app')

@section('content')
<div class="container py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">مدیریت نقش‌ها</h1>
        @canPermission('roles.create')
            <a href="{{ route('admin.roles.create') }}" class="btn btn-primary">ایجاد نقش</a>
        @endcanPermission
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>نام نقش</th><th>تعداد دسترسی</th><th class="text-end">عملیات</th></tr></thead>
                <tbody>
                @foreach($roles as $role)
                    <tr>
                        <td><code>{{ $role->name }}</code></td>
                        <td>{{ $role->permissions_count ?? $role->permissions->count() }}</td>
                        <td class="text-end">
                            @canPermission('roles.edit')
                                <a href="{{ route('admin.roles.edit', $role) }}" class="btn btn-sm btn-outline-primary">ویرایش</a>
                            @endcanPermission
                            @canPermission('roles.delete')
                                <form action="{{ route('admin.roles.destroy', $role) }}" method="POST" class="d-inline" onsubmit="return confirm('آیا از حذف این نقش مطمئن هستید؟')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger">حذف</button>
                                </form>
                            @endcanPermission
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
