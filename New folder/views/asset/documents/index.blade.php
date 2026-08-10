@extends('layouts.app')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">📄 اسناد اموال</h4>
  <a href="{{ route('asset.documents.create') }}" class="btn btn-primary">+ ثبت سند</a>
</div>
<form class="card card-body mb-3" method="GET">
  <div class="row g-2">
    <div class="col-md-3"><input class="form-control" name="document_number" value="{{ $documentNo }}" placeholder="شماره سند"></div>
    <div class="col-md-3"><select class="form-select" name="personnel_id"><option value="">همه پرسنل</option>@foreach($personnel as $p)<option value="{{ $p->id }}" @selected($personnelId===$p->id)>{{ $p->full_name }}</option>@endforeach</select></div>
    <div class="col-md-3"><select class="form-select" name="status"><option value="">همه وضعیت‌ها</option>@foreach($statusLabels as $k=>$v)<option value="{{ $k }}" @selected($status===$k)>{{ $v }}</option>@endforeach</select></div>
    <div class="col-md-3"><button class="btn btn-outline-primary w-100">اعمال فیلتر</button></div>
  </div>
</form>
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>شماره سند</th><th>تاریخ</th><th>پرسنل</th><th>تعداد ردیف</th><th>وضعیت</th><th class="text-end">عملیات</th></tr></thead>
      <tbody>
      @forelse($documents as $d)
        <tr>
          <td>{{ $d->document_number }}</td>
          <td>{{ optional($d->document_date)->format('Y-m-d') }}</td>
          <td>{{ $d->trusteeDisplayName() }}</td>
          <td>{{ $d->items_count }}</td>
          <td><span class="badge bg-light text-dark border">{{ $statusLabels[$d->status] ?? $d->status }}</span></td>
          <td class="text-end d-flex gap-1 justify-content-end">
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('asset.documents.show', $d) }}">مشاهده</a>
            @if($d->status === \App\Models\AssetDocument::STATUS_DRAFT)
              <a class="btn btn-sm btn-outline-primary" href="{{ route('asset.documents.edit', $d) }}">ویرایش</a>
              <form method="POST" action="{{ route('asset.documents.finalize', $d) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-outline-success">نهایی‌سازی</button></form>
            @endif
            @if($d->status !== \App\Models\AssetDocument::STATUS_CANCELLED)
              <form method="POST" action="{{ route('asset.documents.cancel', $d) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-outline-danger">لغو</button></form>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="6" class="text-center text-muted py-4">سندی ثبت نشده است.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
<div class="mt-3">{{ $documents->links() }}</div>
@endsection
