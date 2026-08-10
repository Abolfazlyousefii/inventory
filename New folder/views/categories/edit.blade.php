@extends('layouts.app')

@section('content')
<div class="card shadow-sm">
  <div class="card-body">
    <h5 class="mb-3">ویرایش دسته‌بندی</h5>

    <form method="POST" action="{{ route('categories.update', $category) }}">
      @csrf
      @method('PUT')

      <div class="mb-3">
        <label class="form-label">نام دسته</label>
        <input type="text" name="name" class="form-control" value="{{ old('name', $category->name) }}" required>
        @error('name') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
      </div>

      <div class="mb-3">
        <label class="form-label">کد دسته‌بندی</label>
        <input type="text" class="form-control" value="{{ $category->code ?: '—' }}" readonly>
        <div class="form-text">کد به صورت خودکار ۲ رقمی و یکتا نگهداری می‌شود.</div>
      </div>

      <div class="mb-3">
        <label class="form-label">دسته والد (اختیاری)</label>
        <select name="parent_id" class="form-select">
          <option value="">— بدون والد (دسته اصلی) —</option>
          @foreach($parents as $p)
            <option value="{{ $p->id }}" @selected(old('parent_id', $category->parent_id) == $p->id)>{{ $p->name }}</option>
          @endforeach
        </select>
        @error('parent_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
      </div>

      <button class="btn btn-primary">بروزرسانی</button>
      <a class="btn btn-outline-secondary" href="{{ route('categories.index') }}">بازگشت</a>
    </form>
  </div>
</div>
@endsection
