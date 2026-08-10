@extends('layouts.app')

@section('content')
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">📦 حواله فروش کالا</h4>
    <a class="btn btn-outline-secondary" href="{{ route('vouchers.index') }}">بازگشت</a>
  </div>

  @if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
  @endif

  <form method="GET" class="mb-3 d-flex gap-2">
    <input name="q" value="{{ $q }}" class="form-control" placeholder="جستجو کد/نام/موبایل">
    <button class="btn btn-primary">جستجو</button>
  </form>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>کد فاکتور</th>
            <th>مشتری</th>
            <th>مبلغ</th>
            <th>وضعیت</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody>
          @forelse($invoices as $invoice)
            <tr>
              <td>{{ $invoice->uuid }}</td>
              <td>{{ $invoice->customer_name }}</td>
              <td>{{ number_format((int) $invoice->total) }}</td>
              <td>{{ $invoice->status }}</td>
              <td>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('vouchers.sale-delivery.edit', $invoice->uuid) }}">ویرایش حواله فروش</a>
              </td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-center py-4">موردی یافت نشد.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-3">{{ $invoices->links() }}</div>
</div>
@endsection
