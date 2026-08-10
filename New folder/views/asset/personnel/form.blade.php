@extends('layouts.app')

@section('content')
@php($isEdit = $personnel->exists)
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">{{ $isEdit ? 'ویرایش پرسنل' : 'تعریف پرسنل جدید' }}</h4>
  <a href="{{ route('asset.personnel.index') }}" class="btn btn-outline-secondary">بازگشت</a>
</div>

<form method="POST" action="{{ $isEdit ? route('asset.personnel.update', $personnel) : route('asset.personnel.store') }}" class="card card-body">
  @csrf
  @if($isEdit) @method('PUT') @endif

  <div class="row g-3">
    <div class="col-md-6"><label class="form-label">نام و نام خانوادگی</label><input name="full_name" class="form-control" required value="{{ old('full_name', $personnel->full_name) }}"></div>
    <div class="col-md-6"><label class="form-label">کد پرسنلی</label><input name="personnel_code" class="form-control" required value="{{ old('personnel_code', $personnel->personnel_code) }}"></div>
    <div class="col-md-4"><label class="form-label">کد ملی</label><input name="national_code" class="form-control" value="{{ old('national_code', $personnel->national_code) }}"></div>
    <div class="col-md-4"><label class="form-label">واحد</label><input name="department" class="form-control" value="{{ old('department', $personnel->department) }}"></div>
    <div class="col-md-4"><label class="form-label">سمت</label><input name="position" class="form-control" value="{{ old('position', $personnel->position) }}"></div>
    <div class="col-md-6"><label class="form-label">موبایل</label><input name="mobile" class="form-control" value="{{ old('mobile', $personnel->mobile) }}"></div>
    <div class="col-md-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" @checked(old('is_active', $personnel->exists ? $personnel->is_active : true))><label class="form-check-label">فعال</label></div></div>
    <div class="col-12"><label class="form-label">توضیحات</label><textarea name="description" class="form-control" rows="3">{{ old('description', $personnel->description) }}</textarea></div>
    <div class="col-12"><button class="btn btn-success">ذخیره</button></div>
  </div>
</form>
@endsection
