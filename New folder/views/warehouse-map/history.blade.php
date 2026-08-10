@extends('layouts.app')
@section('content')
<div class="container py-4">
  <div class="d-flex justify-content-between mb-3"><h4>تاریخچه جابه‌جایی: {{ $variant->variant_name }}</h4><a class="btn btn-outline-secondary" href="{{ route('warehouse-map.index') }}">بازگشت</a></div>
  <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0"><thead class="table-light"><tr><th>تاریخ</th><th>نوع</th><th>از</th><th>به</th><th>تعداد</th><th>کاربر</th><th>توضیح</th></tr></thead><tbody>
  @forelse($movements as $m)<tr><td>{{ $m->created_at?->format('Y/m/d H:i') }}</td><td>{{ $m->type }}</td><td dir="ltr">{{ $m->fromLocation?->code ?? '—' }}</td><td dir="ltr">{{ $m->toLocation?->code ?? '—' }}</td><td>{{ number_format($m->quantity) }}</td><td>{{ $m->user?->name ?? '—' }}</td><td>{{ $m->note ?? '—' }}</td></tr>@empty<tr><td colspan="7" class="text-center text-muted py-4">تاریخچه‌ای وجود ندارد.</td></tr>@endforelse
  </tbody></table></div><div class="card-footer bg-white">{{ $movements->links() }}</div></div>
</div>
@endsection
