@extends('layouts.app')

@section('content')
<div dir="rtl" class="mb-3"><a class="btn btn-sm btn-outline-primary" href="{{ route('sales-return-corrections.index',['status'=>'pending_finance']) }}">درخواست‌های اصلاح برگشت از فروش</a></div>
<div class="container py-4" dir="rtl">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">چک‌های ثبت‌شده</h4>
    </div>
  </form>
  <div class="card">
    <div class="table-responsive">
      <table class="table mb-0">
        <thead><tr><th>تاریخ دریافت</th><th>مشتری</th><th>شماره چک</th><th>مبلغ</th><th>وضعیت صیادی</th><th>فاکتور</th></tr></thead>
        <tbody>
        @forelse($cheques as $cheque)
          <tr>
            <td>{{ $cheque->received_at ?: '—' }}</td>
            <td>{{ $cheque->customer_name ?: ($cheque->payment?->invoice?->customer_name ?: '—') }}</td>
            <td>{{ $cheque->cheque_number ?: '—' }}</td>
            <td>{{ \App\Support\Currency::formatRial($cheque->amount) }}</td>
            <td>{{ $cheque->status === 'registered' ? 'ثبت‌شده' : ($cheque->status === 'unregistered' ? 'ثبت‌نشده' : ($cheque->status ?: '—')) }}</td>
            <td>{{ $cheque->payment?->invoice?->uuid ?: '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted">چکی یافت نشد.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>

</div>
@endsection