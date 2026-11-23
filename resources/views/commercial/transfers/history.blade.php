@extends('layouts.app')
@section('title','تاریخچهٔ انتقال فروشنده')
@section('content')
<div class="card p-4"><div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h5">تاریخچهٔ انتقال فروشنده</h1><a class="btn btn-outline-primary" href="{{ route('commercial.transfers.index',['kind'=>'invoice']) }}">اسناد انتقال</a></div>
<p class="text-muted">سوابق موجود با انجام‌دهنده و دلیل اصلی حفظ شده‌اند. برای انتقال جدید، از بخش اسناد انتقال استفاده کنید.</p>
<div class="table-responsive"><table class="table"><thead><tr><th>فاکتور</th><th>از همکار</th><th>به همکار</th><th>تاریخ</th><th>انجام‌دهنده</th><th>دلیل</th></tr></thead><tbody>@forelse($history as $audit)<tr><td>{{ $audit->invoice?->uuid ?: '#'.$audit->invoice_id }}</td><td>{{ $audit->oldSeller?->name ?: 'بدون مسئول' }}</td><td>{{ $audit->newSeller?->name ?: '—' }}</td><td>{{ \App\Support\JalaliDate::dateTime($audit->changed_at) }}</td><td>{{ $audit->changedByUser?->name ?: '—' }}</td><td>{{ $audit->reason }}</td></tr>@empty<tr><td colspan="6">سابقه‌ای ثبت نشده است.</td></tr>@endforelse</tbody></table></div>{{ $history->links() }}</div>
@endsection
