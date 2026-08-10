@extends('layouts.app')
@section('title','ثبت بررسی باگ')
@section('content')
<div class="container py-4"><h1 class="h4 mb-3">ثبت بررسی باگ</h1><form method="POST" action="{{ route('admin.bug-investigator.store') }}" class="card card-body">@csrf
 <div class="mb-3"><label class="form-label">عنوان (اختیاری)</label><input name="title" value="{{ old('title') }}" class="form-control"></div>
 <div class="mb-3"><label class="form-label">توضیح باگ *</label><textarea name="description" required rows="5" class="form-control">{{ old('description') }}</textarea>@error('description')<div class="text-danger small">{{ $message }}</div>@enderror</div>
 <div class="row"><div class="col-md-4 mb-3"><label class="form-label">بخش</label><select name="module" class="form-select"><option value="">نامشخص</option>@foreach(['proforma'=>'پیش‌فاکتور','invoice'=>'فاکتور','warehouse_issue'=>'حواله فروش','stock'=>'موجودی','purchase'=>'خرید','finance'=>'مالی','warehouse'=>'انبار'] as $v=>$l)<option value="{{ $v }}" @selected(old('module')===$v)>{{ $l }}</option>@endforeach</select></div>
 <div class="col-md-4 mb-3"><label class="form-label">نوع رکورد</label><input name="entity_type" value="{{ old('entity_type') }}" placeholder="proforma / invoice / product" class="form-control"></div>
 <div class="col-md-4 mb-3"><label class="form-label">شناسه رکورد</label><input name="entity_id" value="{{ old('entity_id') }}" type="number" class="form-control"></div></div>
 <div class="mb-3"><label class="form-label">شدت</label><select name="severity" class="form-select"><option value="">-</option><option>معمولی</option><option>مهم</option><option>بحرانی</option></select></div>
 <button class="btn btn-primary">ثبت و شروع بررسی</button></form></div>
@endsection
