@extends('layouts.app')

@php
  use Morilog\Jalali\Jalalian;
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">لاگ فعالیت‌ها</h4>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label">جستجو</label>
                <input type="text" name="q" value="{{ $q }}" class="form-control"
                       placeholder="توضیح، نوع رکورد یا شناسه رکورد...">
            </div>

            <div class="col-md-3">
                <label class="form-label">نوع عملیات</label>
                <select name="action" class="form-select">
                    <option value="">همه</option>
                    @foreach($actions as $a)
                        <option value="{{ $a }}" @selected($action === $a)>{{ $a }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-primary">اعمال فیلتر</button>
                <a href="{{ route('activity-logs.index') }}" class="btn btn-outline-secondary">حذف فیلتر</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>زمان (شمسی)</th>
                    <th>کاربر</th>
                    <th>عملیات</th>
                    <th>رکورد</th>
                    <th>شرح</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td>{{ $log->id }}</td>
                        <td dir="ltr" class="text-nowrap">{{ $log->occurred_at ? Jalalian::fromDateTime($log->occurred_at)->format('Y/m/d H:i:s') : '—' }}</td>
                        <td>{{ $log->user?->name ?? 'سیستم' }}</td>
                        <td><span class="badge bg-secondary">{{ $log->action }}</span></td>
                        <td dir="ltr">
                            {{ class_basename($log->subject_type) }}
                            @if($log->subject_id)
                                #{{ $log->subject_id }}
                            @endif
                        </td>
                        <td style="min-width: 360px">{{ $log->description }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">لاگی برای نمایش وجود ندارد.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($logs->hasPages())
        <div class="card-footer bg-white">{{ $logs->links() }}</div>
    @endif
</div>
@endsection
