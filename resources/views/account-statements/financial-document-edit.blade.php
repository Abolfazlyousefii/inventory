@extends('layouts.app')
@section('title', 'ویرایش سند مالی FIN-'.$document->id)
@section('content')
@php
    $editDate = old('document_date', \App\Support\JalaliDate::date($document->document_date));
    if (is_string($editDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $editDate)) $editDate = \App\Support\JalaliDate::date($editDate);
@endphp
<div class="card mx-auto" style="max-width:720px">
    <div class="card-header d-flex justify-content-between align-items-center"><h1 class="h5 mb-0">ویرایش سند مالی FIN-{{ $document->id }}</h1><a href="{{ $backUrl }}" class="btn btn-outline-secondary">بازگشت</a></div>
    <div class="card-body">
        @if($errors->any())<div class="alert alert-danger" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
        <form action="{{ route('financial-documents.update', $document) }}" method="POST" class="row g-3">
            @csrf @method('PUT')
            <input type="hidden" name="version" value="{{ old('version', $document->version) }}">
            <div class="col-12"><label for="editTitle" class="form-label">عنوان سند</label><input id="editTitle" class="form-control" name="title" maxlength="190" required value="{{ old('title', $document->title) }}"></div>
            <div class="col-md-6"><label for="editType" class="form-label">نوع سند</label><select id="editType" class="form-select" name="type" required><option value="debit" @selected(old('type',$document->type)==='debit')>بدهکار — شخص به شرکت بدهکار است</option><option value="credit" @selected(old('type',$document->type)==='credit')>بستانکار — شرکت به شخص بدهکار است</option></select></div>
            <div class="col-md-6"><label for="editAmount" class="form-label">مبلغ (ریال)</label><input id="editAmount" class="form-control" name="document_amount" data-rial inputmode="numeric" required value="{{ old('document_amount',$document->amount) }}"></div>
            <div class="col-12"><label for="editDate" class="form-label">تاریخ سند (شمسی)</label><input id="editDate" class="form-control" name="document_date" data-jdp data-jdp-only-date required value="{{ $editDate }}"></div>
            <div class="col-12"><label for="editDescription" class="form-label">توضیح سند</label><textarea id="editDescription" class="form-control" name="description" rows="3" minlength="3" maxlength="2000" required>{{ old('description',$document->description) }}</textarea></div>
            <div class="col-12"><button class="btn btn-success" type="submit">ذخیره تغییرات</button></div>
        </form>
        <hr>
        <details><summary class="text-danger">حذف سند مالی</summary>
            <p class="small mt-2">اثر این سند از مانده حساب برداشته می‌شود؛ سابقه برای پیگیری محفوظ می‌ماند.</p>
            <form action="{{ route('financial-documents.destroy',$document) }}" method="POST" class="d-grid gap-2">
                @csrf @method('DELETE')<input type="hidden" name="version" value="{{ old('version', $document->version) }}">
                <label for="deleteReason">علت حذف (اجباری)</label><textarea id="deleteReason" class="form-control" name="reason" minlength="3" maxlength="2000" rows="2" required>{{ old('reason') }}</textarea>
                <div><button class="btn btn-outline-danger" type="submit">حذف اثر سند از حساب</button></div>
            </form>
        </details>
    </div>
</div>
@endsection
