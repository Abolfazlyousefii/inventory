@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">گزارش موجودی کم</h4>
</div>

<div class="card shadow-sm">
  <div class="card-body table-responsive">
    <table class="table align-middle mb-0">
      <thead>
        <tr>
          <th>محصول</th>
          <th>SKU</th>
          <th>دسته‌بندی</th>
          <th>موجودی</th>
          <th>آستانه</th>
        </tr>
      </thead>
      <tbody>
      @forelse($products as $p)
        <tr>
          <td class="fw-semibold">{{ $p->name }}</td>
          <td><span class="badge text-bg-secondary">{{ $p->sku }}</span></td>
          <td>{{ $p->category?->name }}</td>
          <td>
            @if($p->stock==0)
              <span class="badge text-bg-danger">ناموجود</span>
            @else
              <span class="badge text-bg-warning">{{ $p->stock }}</span>
            @endif
          </td>
          <td>{{ $p->low_stock_threshold }}</td>
        </tr>
      @empty
        <tr><td colspan="5" class="text-center text-muted py-4">موردی یافت نشد.</td></tr>
      @endforelse
      </tbody>
    </table>
    <div class="mt-3">{{ $products->links() }}</div>
  </div>
</div>
@endsection
