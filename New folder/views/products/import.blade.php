@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="page-title mb-0">ایمپورت محصولات از اکسل</h4>
  <a class="btn btn-outline-secondary" href="{{ route('products.index') }}">بازگشت</a>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="alert alert-info">
      ستون‌ها باید دقیقاً این‌ها باشند:
      <div class="mt-2"><code>name, sku, category, stock, low_stock_threshold, price</code></div>
    </div>

    <div class="d-flex gap-2 mb-3">
      <a class="btn btn-outline-primary" href="{{ route('products.import.template') }}">دانلود فایل نمونه</a>
    </div>

    <form method="POST" action="{{ route('products.import') }}" enctype="multipart/form-data">
      @csrf
      <div class="mb-3">
        <label class="form-label">فایل اکسل/CSV</label>
        <input type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required>
      </div>

      <button class="btn btn-primary">شروع ایمپورت</button>
    </form>
  </div>
</div>
@endsection
