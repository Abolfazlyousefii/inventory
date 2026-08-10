@extends('layouts.app')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">📌 نمایش سند اموال (Read Only)</h4>
  <div class="d-flex gap-2">
    <a href="{{ route('asset.documents.print', $document) }}" target="_blank" class="btn btn-dark">پرینت فرم تحویل اموال</a>
    @if($document->status === \App\Models\AssetDocument::STATUS_DRAFT)
      <a href="{{ route('asset.documents.edit', $document) }}" class="btn btn-outline-primary">ویرایش</a>
    @endif
    <a href="{{ route('asset.documents.index') }}" class="btn btn-outline-secondary">بازگشت</a>
  </div>
</div>

<div class="card border-0 shadow-sm mb-3"><div class="card-body row g-2">
  <div class="col-md-4"><b>شماره سند:</b> {{ $document->document_number }}</div>
  <div class="col-md-4"><b>تاریخ:</b> {{ optional($document->document_date)->format('Y-m-d') }}</div>
  <div class="col-md-4"><b>وضعیت:</b> {{ $statusLabels[$document->status] ?? $document->status }}</div>
  <div class="col-md-6"><b>پرسنل:</b> {{ $document->trusteeDisplayName() }}</div>
  <div class="col-md-6"><b>ثبت‌کننده:</b> {{ $document->creator?->name ?: '—' }}</div>
  <div class="col-12"><b>توضیحات:</b> {{ $document->description ?: '—' }}</div>
</div></div>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-white">نامه / فرم امضاشده</div>
  <div class="card-body">
    @if($document->signed_form_path)
      <div class="d-flex flex-wrap gap-2 mb-2">
        <a href="{{ route('asset.documents.signed-form.view', $document) }}" target="_blank" class="btn btn-sm btn-outline-primary">مشاهده فایل</a>
        <a href="{{ route('asset.documents.signed-form.download', $document) }}" class="btn btn-sm btn-outline-secondary">دانلود فایل</a>
      </div>

      @if($isSignedFormImage)
        <img src="{{ route('asset.documents.signed-form.view', $document) }}" class="img-fluid rounded border" alt="فرم امضاشده پرسنل">
      @else
        <div class="text-muted">فایل آپلود شده از نوع {{ strtoupper(pathinfo($document->signed_form_original_name ?? 'PDF', PATHINFO_EXTENSION)) }} است. برای مشاهده روی دکمه «مشاهده فایل» بزنید.</div>
      @endif
    @else
      <div class="text-muted">هنوز فایل امضاشده‌ای برای این سند ثبت نشده است.</div>
    @endif
  </div>
</div>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-white">اقلام سند</div>
  <div class="table-responsive"><table class="table mb-0 align-middle"><thead><tr><th>کالا</th><th>تعداد</th><th>کدهای اموال 4 رقمی</th><th>توضیحات</th></tr></thead><tbody>
    @foreach($document->items as $item)
      <tr>
        <td>{{ $item->item_name }}</td>
        <td>{{ $item->quantity }}</td>
        <td>{{ $item->codes->pluck('asset_code')->join(' , ') }}</td>
        <td>{{ $item->description ?: '—' }}</td>
      </tr>
    @endforeach
  </tbody></table></div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white">تاریخچه</div>
  <div class="table-responsive"><table class="table mb-0"><thead><tr><th>عملیات</th><th>توضیح</th><th>کاربر</th><th>زمان</th></tr></thead><tbody>
    @forelse($document->histories as $h)
      <tr>
        <td>{{ $h->action_type }}</td>
        <td>{{ $h->description ?: '—' }}</td>
        <td>{{ $h->actor?->name ?: '—' }}</td>
        <td>{{ optional($h->done_at)->format('Y-m-d H:i') }}</td>
      </tr>
    @empty
      <tr><td colspan="4" class="text-center text-muted">تاریخچه‌ای ثبت نشده است.</td></tr>
    @endforelse
  </tbody></table></div>
</div>
@endsection
