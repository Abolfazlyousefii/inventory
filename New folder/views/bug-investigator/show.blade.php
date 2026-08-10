@extends('layouts.app')
@section('title','گزارش بررسی باگ')
@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between mb-3">
        <h1 class="h4">گزارش بررسی #{{ $bugCase->id }}</h1>
        <div class="d-flex gap-2">
            @if(in_array($bugCase->status, ['pending', 'failed'], true))
                <form method="POST" action="{{ route('admin.bug-investigator.rerun', $bugCase) }}">
                    @csrf
                    <button type="submit" class="btn btn-warning">Run investigation again</button>
                </form>
            @endif
            <a href="{{ route('admin.bug-investigator.index') }}" class="btn btn-outline-secondary">بازگشت</a>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <p><b>عنوان:</b> {{ $bugCase->title ?: '-' }}</p>
            <p><b>وضعیت:</b> <span class="badge bg-secondary">{{ $bugCase->status }}</span></p>
            <p><b>توضیح:</b> {{ $bugCase->description }}</p>
            @if($bugCase->error_message)
                <div class="alert alert-danger">{{ $bugCase->error_message }}</div>
            @endif
        </div>
    </div>

    @if($bugCase->report)
        <div class="card mb-3"><div class="card-header">خلاصه</div><div class="card-body"><p>{{ $bugCase->report->summary }}</p></div></div>
        <div class="row"><div class="col-md-6"><div class="card mb-3"><div class="card-header">قوانین شکسته‌شده</div><ul class="list-group list-group-flush">@forelse($bugCase->report->broken_rules ?? [] as $r)<li class="list-group-item">{{ $r }}</li>@empty<li class="list-group-item text-muted">موردی ثبت نشده</li>@endforelse</ul></div></div><div class="col-md-6"><div class="card mb-3"><div class="card-header">شواهد</div><ul class="list-group list-group-flush">@forelse($bugCase->report->findings ?? [] as $f)<li class="list-group-item">{{ $f }}</li>@empty<li class="list-group-item text-muted">موردی ثبت نشده</li>@endforelse</ul></div></div></div>
        <div class="card mb-3"><div class="card-header">فایل‌های مشکوک</div><div class="card-body">@foreach($bugCase->report->suspected_files ?? [] as $file)<code class="d-block">{{ $file }}</code>@endforeach</div></div>
        <div class="card mb-3"><div class="card-header">گزارش کامل</div><div class="card-body"><pre style="white-space:pre-wrap">{{ $bugCase->report->raw_report }}</pre></div></div>
        <div class="card"><div class="card-header">پرامپت آماده برای Codex</div><div class="card-body"><textarea class="form-control" rows="14" readonly>{{ $bugCase->report->codex_prompt }}</textarea></div></div>
    @else
        <div class="alert alert-info">گزارش هنوز آماده نیست. صفحه را چند لحظه دیگر تازه‌سازی کنید. اگر وضعیت پرونده pending باقی مانده، ممکن است لازم باشد queue worker اجرا شود.</div>
    @endif
</div>
@endsection
