@extends('layouts.app')

@section('content')
<div class="card shadow-sm">
  <div class="card-body">
    <h5 class="mb-1">ثبت ورود/خروج کالا</h5>
    <div class="text-muted mb-3">{{ $product->name }} ({{ $product->sku }}) — موجودی فعلی: {{ $product->stock }}</div>

    <form method="POST" action="{{ route('movements.store', $product) }}">
      @csrf

      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">نوع</label>
          <select name="type" class="form-select">
            <option value="in" @selected(old('type')==='in')>ورود</option>
            <option value="out" @selected(old('type')==='out')>خروج</option>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">تعداد</label>
          <input type="number" name="quantity" class="form-control" min="1" value="{{ old('quantity', 1) }}">
        </div>

        <div class="col-md-4">
          <label class="form-label">یادداشت (اختیاری)</label>
          <input name="note" class="form-control" value="{{ old('note') }}" placeholder="مثلاً فروش فاکتور ۱۲۳">
        </div>
      </div>

      <div class="d-flex gap-2 mt-4">
        <button class="btn btn-primary">ثبت</button>
        <a class="btn btn-outline-secondary" href="{{ route('products.index') }}">بازگشت</a>
      </div>
        <div class="col-md-4">
        <label class="form-label">علت گردش</label>
        <select name="reason" class="form-select">
            <option value="purchase" @selected(old('reason')==='purchase')>خرید/ورود از تامین‌کننده</option>
            <option value="sale" @selected(old('reason')==='sale')>فروش/خروج</option>
            <option value="return" @selected(old('reason')==='return')>مرجوعی</option>
            <option value="transfer" @selected(old('reason')==='transfer')>انتقال</option>
            <option value="adjustment" @selected(old('reason','adjustment')==='adjustment')>اصلاح موجودی</option>
        </select>
        </div>
        <div class="col-md-4">
        <label class="form-label">ارجاع (اختیاری)</label>
        <input name="reference" class="form-control" value="{{ old('reference') }}" placeholder="مثلاً حواله ۱۲۳ / فاکتور ۵۴">
        </div>
    </form>
  </div>
</div>
@endsection
