@extends('layouts.app')

@section('content')
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">حواله فروش کالا</h4>
    <a class="btn btn-outline-secondary" href="{{ route('vouchers.index') }}">بازگشت</a>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead><tr><th>کد فاکتور</th><th>مشتری</th><th>محصولات</th><th>تاریخ</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
          @forelse($salesInvoices as $inv)
          <tr>
            <td>{{ $inv->uuid }}</td>
            <td>{{ $inv->customer_name }}<div class="small text-muted">{{ $inv->customer_mobile }}</div></td>
            <td>
              <ul class="mb-0 ps-3 small">
                @foreach($inv->items as $it)
                  <li>{{ $it->product?->name ?? ('#'.$it->product_id) }} × {{ number_format((int)$it->quantity) }}</li>
                @endforeach
              </ul>
            </td>
            <td>{{ \App\Support\JalaliDate::dateTime($inv->display_document_date) }}</td>
            <td><span class="badge bg-secondary">{{ $inv->status }}</span></td>
            <td class="text-end">
              <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-primary" href="{{ route('invoices.show', $inv->uuid) }}">مشاهده/وضعیت</a>
                <a class="btn btn-outline-secondary" href="{{ route('invoices.edit', $inv->uuid) }}">ویرایش</a>
                <a class="btn btn-outline-dark" target="_blank" href="{{ route('invoices.print', $inv->uuid) }}">چاپ</a>
              </div>
            </td>
          </tr>
          @empty
          <tr><td colspan="6" class="text-center py-4">حواله فروشی یافت نشد.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-3">{{ $salesInvoices->links() }}</div>
</div>
@endsection
