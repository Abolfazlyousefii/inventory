@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">👥 امین اموال | پرسنل</h4>
  <a href="{{ route('asset.personnel.create') }}" class="btn btn-primary">+ تعریف پرسنل</a>
</div>

<form class="card card-body mb-3" method="GET">
  <div class="row g-2">
    <div class="col-md-10"><input class="form-control" name="q" value="{{ $q }}" placeholder="جستجو نام یا کد پرسنلی"></div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100">جستجو</button></div>
  </div>
</form>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>نام</th><th>کد پرسنلی</th><th>واحد</th><th>سمت</th><th>وضعیت</th><th class="text-end">عملیات</th></tr></thead>
      <tbody>
      @forelse($personnel as $p)
        <tr>
          <td>{{ $p->full_name }}</td>
          <td>{{ $p->personnel_code }}</td>
          <td>{{ $p->department ?: '—' }}</td>
          <td>{{ $p->position ?: '—' }}</td>
          <td><span class="badge {{ $p->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $p->is_active ? 'فعال' : 'غیرفعال' }}</span></td>
          <td class="text-end d-flex gap-1 justify-content-end">
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('asset.personnel.show', $p) }}">مشاهده</a>
            <a class="btn btn-sm btn-outline-primary" href="{{ route('asset.personnel.edit', $p) }}">ویرایش</a>
            <form method="POST" action="{{ route('asset.personnel.toggle-status', $p) }}">
              @csrf @method('PATCH')
              <button class="btn btn-sm btn-outline-dark">{{ $p->is_active ? 'غیرفعال' : 'فعال' }}</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="6" class="text-center text-muted py-4">پرسنلی ثبت نشده است.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
<div class="mt-3">{{ $personnel->links() }}</div>
@endsection
