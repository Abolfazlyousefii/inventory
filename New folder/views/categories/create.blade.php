@extends('layouts.app')

@section('content')
<div class="card shadow-sm">
  <div class="card-body">
    <h5 class="mb-3">افزودن دسته‌بندی</h5>

    <form method="POST" action="{{ route('categories.store') }}">
      @csrf

      <div class="mb-3">
        <label class="form-label">نام دسته</label>
        <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
        @error('name') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
      </div>

      <div class="mb-3">
        <label class="form-label">دسته والد (اختیاری)</label>
        <select name="parent_id" class="form-select">
          <option value="">— بدون والد (دسته اصلی) —</option>
          @foreach($parents as $p)
            <option value="{{ $p->id }}" @selected(old('parent_id') == $p->id)>{{ $p->name }}</option>
          @endforeach
        </select>
        @error('parent_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
      </div>

      <div class="alert alert-light border small mb-3">
        کد دسته‌بندی به صورت خودکار، <b>۲ رقمی یونیک</b> ساخته می‌شود.
      </div>

      <button class="btn btn-primary">ذخیره</button>
      <a class="btn btn-outline-secondary" href="{{ route('categories.index') }}">بازگشت</a>
    </form>
  </div>
</div>
@endsection
