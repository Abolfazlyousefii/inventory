@extends('layouts.app')
@section('title','Bug Investigator')
@section('content')
<div class="container-fluid py-4">
 <div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h4">بررسی‌گر باگ</h1><a class="btn btn-primary" href="{{ route('admin.bug-investigator.create') }}">ثبت بررسی جدید</a></div>
 <div class="card"><div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>#</th><th>عنوان</th><th>بخش</th><th>شدت</th><th>وضعیت</th><th>زمان</th><th></th></tr></thead><tbody>
 @forelse($bugCases as $case)<tr><td>{{ $case->id }}</td><td>{{ $case->title ?: Str::limit($case->description,50) }}</td><td>{{ $case->module ?: 'نامشخص' }}</td><td>{{ $case->severity ?: '-' }}</td><td><span class="badge bg-secondary">{{ $case->status }}</span></td><td>{{ $case->created_at }}</td><td><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.bug-investigator.show',$case) }}">مشاهده</a></td></tr>@empty<tr><td colspan="7" class="text-center text-muted">پرونده‌ای ثبت نشده است.</td></tr>@endforelse
 </tbody></table></div></div><div class="mt-3">{{ $bugCases->links() }}</div>
</div>
@endsection
