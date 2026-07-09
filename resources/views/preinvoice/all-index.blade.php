@extends('layouts.app')

@php use Morilog\Jalali\Jalalian; @endphp

@section('content')

<style>
  .preinvoice-page {
    --primary: #4f46e5;
    --primary-dark: #3730a3;
    --soft-bg: #f6f7fb;
    --card-bg: #ffffff;
    --text-main: #1f2937;
    --text-muted: #6b7280;
    --border-soft: #eef0f6;
  }

  .preinvoice-hero {
    position: relative;
    overflow: hidden;
    border-radius: 28px;
    padding: 28px;
    background:
      radial-gradient(circle at top left, rgba(255,255,255,.32), transparent 34%),
      linear-gradient(135deg, #4f46e5 0%, #7c3aed 55%, #ec4899 100%);
    color: #fff;
    box-shadow: 0 22px 50px rgba(79, 70, 229, .24);
  }

  .preinvoice-hero::after {
    content: "";
    position: absolute;
    inset: auto -70px -90px auto;
    width: 240px;
    height: 240px;
    border-radius: 999px;
    background: rgba(255,255,255,.14);
  }

  .hero-title {
    font-weight: 900;
    letter-spacing: -.5px;
  }

  .hero-subtitle {
    color: rgba(255,255,255,.82);
    font-size: .95rem;
  }

  .hero-icon {
    width: 56px;
    height: 56px;
    display: grid;
    place-items: center;
    border-radius: 20px;
    background: rgba(255,255,255,.18);
    backdrop-filter: blur(10px);
    font-size: 1.7rem;
  }

  .create-btn {
    border: 0;
    border-radius: 16px;
    padding: 11px 18px;
    font-weight: 800;
    color: var(--primary-dark);
    box-shadow: 0 14px 30px rgba(17, 24, 39, .16);
  }

  .create-btn:hover {
    transform: translateY(-1px);
  }

  .mini-stat {
    min-width: 155px;
    border-radius: 20px;
    padding: 14px 16px;
    background: rgba(255,255,255,.16);
    border: 1px solid rgba(255,255,255,.22);
    backdrop-filter: blur(8px);
  }

  .mini-stat span {
    display: block;
    color: rgba(255,255,255,.75);
    font-size: .82rem;
    margin-bottom: 4px;
  }

  .mini-stat strong {
    font-size: 1.35rem;
    font-weight: 900;
  }

  .filter-card,
  .table-card {
    border: 1px solid var(--border-soft);
    border-radius: 24px;
    background: var(--card-bg);
    box-shadow: 0 14px 35px rgba(17, 24, 39, .06);
  }

  .filter-card {
    padding: 18px;
  }

  .form-label {
    color: var(--text-muted);
    font-size: .86rem;
    font-weight: 800;
  }

  .form-select {
    min-height: 48px;
    border-radius: 15px;
    border-color: #e5e7eb;
    font-weight: 700;
    color: var(--text-main);
  }

  .form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 .2rem rgba(79, 70, 229, .12);
  }

  .table-card {
    overflow: hidden;
  }

  .table-modern {
    margin-bottom: 0;
  }

  .table-modern thead th {
    background: #f8fafc;
    color: #64748b;
    font-size: .78rem;
    font-weight: 900;
    text-transform: none;
    padding: 16px 14px;
    border-bottom: 1px solid var(--border-soft);
    white-space: nowrap;
  }

  .table-modern tbody td {
    padding: 16px 14px;
    color: #334155;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    white-space: nowrap;
  }

  .table-modern tbody tr {
    transition: all .18s ease;
  }

  .table-modern tbody tr:hover {
    background: #fafaff;
    transform: scale(.998);
  }

  .id-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 42px;
    height: 32px;
    padding: 0 10px;
    border-radius: 12px;
    background: #eef2ff;
    color: #4338ca;
    font-weight: 900;
  }

  .uuid-pill {
    direction: ltr;
    display: inline-flex;
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    padding: 7px 10px;
    border-radius: 12px;
    background: #f8fafc;
    border: 1px solid #eef2f7;
    color: #475569;
    font-size: .82rem;
    font-weight: 800;
  }

  .customer-name {
    font-weight: 900;
    color: #111827;
  }

  .creator-name {
    color: #64748b;
    font-weight: 700;
  }

  .items-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 11px;
    border-radius: 999px;
    background: #f1f5f9;
    color: #475569;
    font-weight: 900;
    font-size: .85rem;
  }

  .price-text {
    font-weight: 950;
    color: #0f172a;
  }

  .date-text {
    color: #64748b;
    font-size: .86rem;
    font-weight: 700;
  }

  .status-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 12px;
    border-radius: 999px;
    font-size: .82rem;
    font-weight: 900;
  }

  .status-badge::before {
    content: "";
    width: 8px;
    height: 8px;
    border-radius: 999px;
    background: currentColor;
  }

  .status-draft {
    background: #f1f5f9;
    color: #475569;
  }

  .status-pending {
    background: #fff7ed;
    color: #ea580c;
  }

  .status-approved,
  .status-confirmed {
    background: #ecfdf5;
    color: #059669;
  }

  .status-rejected,
  .status-canceled,
  .status-cancelled {
    background: #fef2f2;
    color: #dc2626;
  }

  .status-expired {
    background: #f8fafc;
    color: #64748b;
  }

  .status-default {
    background: #eef2ff;
    color: #4f46e5;
  }

  .empty-state {
    padding: 56px 20px;
    text-align: center;
  }

  .empty-state .empty-icon {
    width: 72px;
    height: 72px;
    margin: 0 auto 14px;
    display: grid;
    place-items: center;
    border-radius: 26px;
    background: #eef2ff;
    font-size: 2rem;
  }

  .empty-state h6 {
    font-weight: 900;
    color: #1f2937;
  }

  .empty-state p {
    color: #6b7280;
    margin-bottom: 0;
  }

  .pagination {
    gap: 6px;
  }

  .page-link {
    border: 0;
    border-radius: 12px !important;
    color: #4f46e5;
    font-weight: 800;
    box-shadow: 0 8px 18px rgba(17, 24, 39, .06);
  }

  .page-item.active .page-link {
    background: #4f46e5;
  }

  @media (max-width: 768px) {
    .preinvoice-hero {
      padding: 22px;
      border-radius: 22px;
    }

    .hero-actions {
      width: 100%;
    }

    .create-btn {
      width: 100%;
      justify-content: center;
    }

    .mini-stat {
      width: 100%;
    }
  }
</style>

<div class="container py-4 preinvoice-page">

  <div class="preinvoice-hero mb-4">
    <div class="position-relative">
      <div class="d-flex flex-column flex-lg-row justify-content-between gap-4">
        <div class="d-flex gap-3 align-items-start">
          <div class="hero-icon">📋</div>

          <div>
            <h4 class="hero-title mb-2">همه پیش‌فاکتورها</h4>
            <div class="hero-subtitle">
              مدیریت، بررسی وضعیت و مشاهده جزئیات کلی پیش‌فاکتورهای ثبت‌شده
            </div>
          </div>
        </div>

        <div class="hero-actions d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center gap-3">
          <div class="mini-stat">
            <span>تعداد کل</span>
            <strong>
              {{ method_exists($orders, 'total') ? number_format($orders->total()) : number_format($orders->count()) }}
            </strong>
          </div>

          <a href="{{ route('preinvoice.create') }}" class="btn btn-light create-btn d-inline-flex align-items-center gap-2">
            <span>➕</span>
            <span>ایجاد پیش‌فاکتور</span>
          </a>
        </div>
      </div>
    </div>
  </div>

  <form method="GET" class="filter-card mb-3">
    <div class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label">فیلتر وضعیت</label>
        <select name="status" class="form-select" onchange="this.form.submit()">
          <option value="">همه وضعیت‌ها</option>
          @foreach($statusLabels as $key => $label)
            <option value="{{ $key }}" @selected($status === $key)>
              {{ $label }}
            </option>
          @endforeach
        </select>
      </div>

      <div class="col-md-8">
        <div class="d-flex justify-content-md-end">
          @if($status)
            <a href="{{ route(request()->route()->getName()) }}" class="btn btn-outline-secondary rounded-4 px-4">
              حذف فیلتر
            </a>
          @endif
        </div>
      </div>
    </div>
  </form>

  <div class="table-card">
    <div class="table-responsive">
      <table class="table table-hover align-middle table-modern">
        <thead>
          <tr>
            <th>#</th>
            <th>کد</th>
            <th>وضعیت</th>
            <th>مشتری</th>
            <th>ثبت‌کننده</th>
            <th>اقلام</th>
            <th>جمع</th>
            <th>انقضای فریز</th>
            <th>تاریخ ثبت</th>
            <th class="text-end">عملیات</th>
          </tr>
        </thead>

        <tbody>
          @forelse($orders as $o)
            @php
              $statusKey = $o->status ?? '';
              $badgeClass = match($statusKey) {
                'draft' => 'status-draft',
                'pending' => 'status-pending',
                'approved' => 'status-approved',
                'confirmed' => 'status-confirmed',
                'rejected' => 'status-rejected',
                'canceled' => 'status-canceled',
                'cancelled' => 'status-cancelled',
                'expired' => 'status-expired',
                default => 'status-default',
              };
            @endphp

            <tr>
              <td>
                <span class="id-badge">{{ $o->id }}</span>
              </td>

              <td>
                <span class="uuid-pill" title="{{ $o->uuid }}">
                  {{ $o->uuid }}
                </span>
              </td>

              <td>
                <span class="status-badge {{ $badgeClass }}">
                  {{ $o->status_label }}
                </span>
              </td>

              <td>
                <span class="customer-name">
                  {{ $o->customer_name }}
                </span>
              </td>

              <td>
                <span class="creator-name">
                  {{ $o->creator?->name ?? '—' }}
                </span>
              </td>

              <td>
                <span class="items-chip">
                  🧾 {{ number_format((int) $o->items_count) }}
                </span>
              </td>

              <td>
                <span class="price-text">
                  {{ number_format((int) $o->total_price) }}
                </span>
              </td>

              <td>
                <span class="date-text">
                  {{ $o->stock_frozen_until ? Jalalian::fromDateTime($o->stock_frozen_until)->format('Y/m/d H:i') : '—' }}
                </span>
              </td>

              <td>
                <span class="date-text">
                  {{ $o->created_at ? Jalalian::fromDateTime($o->created_at)->format('Y/m/d H:i') : '—' }}
                </span>
              </td>
              <td class="text-end">
                <a href="{{ route('preinvoice.print', $o->uuid) }}" target="_blank" class="btn btn-sm btn-outline-dark">پرینت</a>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="10">
                <div class="empty-state">
                  <div class="empty-icon">🗂️</div>
                  <h6>موردی یافت نشد</h6>
                  <p>هنوز پیش‌فاکتوری با این شرایط ثبت نشده است.</p>
                </div>
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-4 d-flex justify-content-center justify-content-md-end">
    {{ $orders->links() }}
  </div>

</div>
@endsection
