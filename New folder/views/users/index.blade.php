@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
    <div>
        <h4 class="mb-0">👤 کاربران</h4>
        <div class="text-muted small">لیست کاربران سینک‌شده از CRM و کاربران داخلی</div>
    </div>

    @canPermission('users.sync')
    <form method="POST" action="{{ route('users.sync') }}">
        @csrf
        <button type="submit" class="btn btn-primary">🔄 همگام‌سازی با CRM</button>
    </form>
    @endcanPermission
</div>

@if(session('sync_success'))
    <div class="alert alert-success">{{ session('sync_success') }}</div>
@endif

@if(session('sync_error'))
    <div class="alert alert-danger">{{ session('sync_error') }}</div>
@endif

<div class="card shadow-sm">
    <div class="card-header bg-white">
        <form method="GET" action="{{ route('users.index') }}" class="row g-2">
            <div class="col-md-4">
                <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="جستجو بر اساس نام یا موبایل">
            </div>
            <div class="col-md-3">
                <input type="text" name="role" value="{{ request('role') }}" class="form-control" placeholder="فیلتر بر اساس role">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="active" @selected(request('status') === 'active')>فعال</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>غیرفعال</option>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-outline-secondary">اعمال فیلتر</button>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>شناسه داخلی</th>
                        <th>شناسه CRM</th>
                        <th>نام</th>
                        <th>موبایل</th>
                        <th>ایمیل</th>
                        <th>نام کاربری</th>
                        <th>مدیر</th>
                        <th>نقش‌ها</th>
                        <th>منبع</th>
                        <th>وضعیت</th>
                        <th>آخرین sync</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                        <tr>
                            <td>{{ $user->id }}</td>
                            <td>{{ $user->crm_user_id ?? $user->external_crm_id ?? '-' }}</td>
                            <td>{{ $user->name }}</td>
                            <td>{{ $user->phone ?? '-' }}</td>
                            <td>{{ $user->email }}</td>
                            <td>{{ $user->username ?? '-' }}</td>
                            <td>{{ $user->manager?->name ?? '-' }}</td>
                            <td>{{ $user->roles->pluck('name')->implode('، ') ?: '-' }}</td>
                            <td>
                                @if($user->sync_source === 'crm')
                                    <span class="badge bg-info-subtle text-info">CRM</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary">داخلی</span>
                                @endif
                            </td>
                            <td>
                                @if($user->is_active)
                                    <span class="badge bg-success">فعال</span>
                                @else
                                    <span class="badge bg-danger">غیرفعال</span>
                                @endif
                            </td>
                            <td>{{ $user->synced_at?->format('Y-m-d H:i') ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="text-center text-muted py-4">هنوز کاربری سینک نشده است.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white">
        {{ $users->links() }}
    </div>
</div>
@endsection
