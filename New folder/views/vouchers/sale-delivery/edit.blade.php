@extends('layouts.app')

@section('content')
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">✏️ ویرایش حواله فروش کالا</h4>
    <a class="btn btn-outline-secondary" href="{{ route('vouchers.sale-delivery.index') }}">بازگشت</a>
  </div>

  <form method="POST" action="{{ route('vouchers.sale-delivery.update', $invoice->uuid) }}" class="card">
    @csrf
    @method('PUT')

    <div class="card-body">
      <div class="mb-3">
        <label class="form-label">وضعیت</label>
        <select name="status" class="form-select">
          @foreach(['pending_warehouse_approval','collecting','checking_discrepancy','packing','shipped','not_shipped'] as $status)
            <option value="{{ $status }}" @selected($invoice->status===$status)>{{ $status }}</option>
          @endforeach
        </select>
      </div>

      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>محصول</th>
              <th>تعداد</th>
              <th>قیمت</th>
            </tr>
          </thead>
          <tbody>
            @foreach($invoice->items as $item)
              <tr>
                <td>
                  {{ $item->product?->name ?? ('#'.$item->product_id) }}
                  <input type="hidden" name="items[{{ $loop->index }}][id]" value="{{ $item->id }}">
                </td>
                <td><input type="number" min="1" class="form-control" name="items[{{ $loop->index }}][quantity]" value="{{ (int) $item->quantity }}"></td>
                <td><input type="number" min="0" class="form-control" name="items[{{ $loop->index }}][price]" value="{{ (int) $item->price }}"></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>

    <div class="card-footer text-end">
      <button class="btn btn-success">ثبت تغییرات حواله فروش</button>
    </div>
  </form>
</div>
@endsection
