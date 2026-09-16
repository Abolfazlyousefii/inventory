@extends('layouts.app')

@section('content')
<style>
  :root {
    --brand: #0d6efd;
    --bg: #f6f8fb;
    --card: #ffffff;
    --border: #e5e7eb;
    --text: #111827;
    --muted: #6b7280;
    --soft: #f9fafb;
    --danger: #dc3545;
    --success: #198754;
    --warning: #f59e0b;
  }

  body {
    background: var(--bg);
  }

  .customers-page {
    max-width: 1280px;
  }

  .page-head {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 16px;
    margin-bottom: 14px;
  }

  .page-title {
    font-size: 1.15rem;
    font-weight: 900;
    color: var(--text);
    margin: 0;
  }

  .page-subtitle {
    color: var(--muted);
    font-size: .82rem;
    margin-top: 5px;
  }

  .toolbar {
    display: flex;
    align-items: stretch;
    gap: 8px;
    flex-wrap: wrap;
    width: 100%;
  }

  .search-form {
    display: grid;
    grid-template-columns: minmax(280px, 1.7fr) minmax(170px, .8fr) minmax(150px, .7fr) auto auto;
    gap: 8px;
    flex: 1 1 760px;
  }

  .search-form .form-control,
  .search-form .form-select {
    min-width: 0;
  }

  .filter-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #eef2ff;
    color: #3730a3;
    border: 1px solid #c7d2fe;
    border-radius: 999px;
    padding: 5px 10px;
    font-size: .78rem;
    font-weight: 800;
  }

  .customer-tier-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 4px 9px;
    font-size: .76rem;
    font-weight: 900;
    white-space: nowrap;
  }

  .operations-cell {
    width: 132px;
  }

  .customer-balance-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 72px;
    border-radius: 999px;
    padding: 4px 10px;
    font-size: .76rem;
    font-weight: 900;
    border: 1px solid transparent;
    white-space: nowrap;
  }

  .customer-balance-badge.is-debit {
    background: #fee2e2;
    color: #991b1b;
    border-color: #fecaca;
  }

  .customer-balance-badge.is-credit {
    background: #dcfce7;
    color: #166534;
    border-color: #bbf7d0;
  }

  .customer-balance-badge.is-settled {
    background: #f3f4f6;
    color: #4b5563;
    border-color: #e5e7eb;
  }

  .customer-balance-amount {
    font-weight: 900;
    direction: ltr;
    white-space: nowrap;
  }

  .customer-balance-amount.is-debit {
    color: #b91c1c;
  }

  .customer-balance-amount.is-credit {
    color: #15803d;
  }

  .customer-balance-amount.is-settled {
    color: #6b7280;
  }


  .soft-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 18px;
    overflow: hidden;
  }

  .desktop-table th {
    font-size: .78rem;
    color: #374151;
    white-space: nowrap;
  }

  .desktop-table td {
    font-size: .86rem;
    vertical-align: middle;
  }

  .customer-mobile-list {
    display: none;
  }

  .customer-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 13px;
    margin-bottom: 10px;
  }

  .customer-card-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 10px;
  }

  .customer-name {
    font-weight: 900;
    color: var(--text);
    font-size: .98rem;
    line-height: 1.8;
  }

  .customer-id-badge {
    background: var(--soft);
    border: 1px solid var(--border);
    border-radius: 999px;
    padding: 3px 8px;
    font-size: .75rem;
    color: var(--muted);
    white-space: nowrap;
  }

  .customer-info-line {
    display: flex;
    gap: 7px;
    align-items: flex-start;
    color: #374151;
    font-size: .84rem;
    margin-top: 6px;
    line-height: 1.8;
  }

  .customer-info-line .label {
    color: var(--muted);
    min-width: 58px;
    font-weight: 700;
  }

  .balance-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 7px;
    margin-top: 12px;
  }

  .balance-box {
    background: var(--soft);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 8px;
    text-align: center;
  }

  .balance-box .small-label {
    color: var(--muted);
    font-size: .72rem;
    font-weight: 700;
    margin-bottom: 4px;
  }

  .balance-box .value {
    font-size: .82rem;
    font-weight: 900;
    color: var(--text);
    direction: ltr;
  }

  .card-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-top: 12px;
  }

  .pagination-wrap {
    overflow-x: auto;
    padding-bottom: 4px;
  }

  .modal-content {
    border: 0;
    border-radius: 18px;
    overflow: hidden;
  }

  .modal-header {
    background: #f8fafc;
    border-bottom: 1px solid var(--border);
  }

  .modal-footer {
    background: #f8fafc;
    border-top: 1px solid var(--border);
  }

  .form-label {
    font-size: .82rem;
    font-weight: 800;
    color: #374151;
  }

  .select2-container {
    width: 100% !important;
  }

  .select2-container .select2-selection--single {
    min-height: 38px !important;
    border-color: #dee2e6 !important;
    border-radius: .5rem !important;
    padding-top: 4px;
  }

  .select2-container .select2-selection--single .select2-selection__rendered {
    line-height: 28px !important;
    padding-right: 12px !important;
  }

  .select2-container .select2-selection--single .select2-selection__arrow {
    height: 36px !important;
  }

  @media (max-width: 991.98px) {
    .page-head {
      padding: 13px;
      border-radius: 15px;
    }

    .page-head-main {
      width: 100%;
    }

    .toolbar {
      width: 100%;
      display: grid;
      grid-template-columns: 1fr;
      gap: 8px;
      margin-top: 12px;
    }

    .search-form {
      grid-column: 1 / -1;
      min-width: 0;
      width: 100%;
      display: grid;
      grid-template-columns: 1fr;
      gap: 8px;
    }

    .search-form .form-control {
      min-width: 0;
      width: 100%;
    }

    .toolbar > .btn {
      width: 100%;
    }
  }

  @media (max-width: 767.98px) {
    .container {
      padding-left: 10px;
      padding-right: 10px;
    }

    .desktop-customers {
      display: none;
    }

    .customer-mobile-list {
      display: block;
    }

    .page-title {
      font-size: 1rem;
    }

    .page-subtitle {
      font-size: .76rem;
    }

    .toolbar {
      grid-template-columns: 1fr;
    }

    .search-form {
      grid-template-columns: 1fr;
    }

    .search-form button {
      width: 100%;
    }

    .balance-grid {
      grid-template-columns: 1fr;
    }

    .card-actions {
      grid-template-columns: 1fr;
    }

    .modal-dialog {
      margin: 0;
    }

    .modal-content {
      min-height: 100vh;
      border-radius: 0;
    }

    .modal-body {
      padding: 12px;
    }

    .modal-footer {
      position: sticky;
      bottom: 0;
      z-index: 10;
    }

    .modal-footer .btn {
      width: 100%;
    }
  }
</style>

@php
  $tierFilterLabels = [
    'vip' => 'VIP',
    'normal' => 'معمولی',
    'new_or_low_purchase' => 'جدید / کم‌خرید',
    'unset' => 'بدون سطح / معمولی پیش‌فرض',
  ];
  $balanceFilterLabels = [
    'debt' => 'بدهکار',
    'credit' => 'بستانکار',
    'settled' => 'تسویه / صفر',
  ];
  $hasActiveFilters = filled($q ?? '') || filled($reservationTier ?? '') || filled($balanceStatus ?? '');
@endphp

<div class="container customers-page py-3">

  <div class="page-head">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
      <div class="page-head-main">
        <h1 class="page-title">👥 مشتریان</h1>
        <div class="page-subtitle">سطح رزرو مشتری برای تعیین مدت فیریز پیش‌فاکتور استفاده می‌شود. مانده حساب از بخش گردش حساب اشخاص مدیریت می‌شود.</div>
      </div>

      <div class="toolbar">
        <form class="search-form" method="GET" action="{{ route('customers.index') }}">
          <input
            class="form-control"
            name="q"
            value="{{ $q ?? '' }}"
            placeholder="جستجوی نام، موبایل، کد مشتری، آدرس یا توضیحات">
          <select class="form-select" name="reservation_tier" aria-label="فیلتر سطح مشتری">
            <option value="">همه سطح‌ها</option>
            <option value="vip" @selected(($reservationTier ?? '') === 'vip')>VIP</option>
            <option value="normal" @selected(($reservationTier ?? '') === 'normal')>معمولی</option>
            <option value="new_or_low_purchase" @selected(($reservationTier ?? '') === 'new_or_low_purchase')>جدید / کم‌خرید</option>
            <option value="unset" @selected(($reservationTier ?? '') === 'unset')>بدون سطح / معمولی پیش‌فرض</option>
          </select>
          <select class="form-select" name="balance_status" aria-label="فیلتر وضعیت مانده">
            <option value="">همه مانده‌ها</option>
            <option value="debt" @selected(($balanceStatus ?? '') === 'debt')>بدهکار</option>
            <option value="credit" @selected(($balanceStatus ?? '') === 'credit')>بستانکار</option>
            <option value="settled" @selected(($balanceStatus ?? '') === 'settled')>تسویه / صفر</option>
          </select>
          <button class="btn btn-primary">جستجو</button>
          <a class="btn btn-outline-secondary" href="{{ route('customers.index') }}">پاک کردن</a>
        </form>

        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#createCustomerModal">
          ➕ ساخت مشتری
        </button>
      </div>
    </div>
  </div>

  @if(session('success'))
    <div class="alert alert-success rounded-4">{{ session('success') }}</div>
  @endif

  @if(session('error'))
    <div class="alert alert-danger rounded-4">{{ session('error') }}</div>
  @endif

  @if ($errors->any())
    <div class="alert alert-danger rounded-4">
      <ul class="mb-0">
        @foreach ($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif


  @if($hasActiveFilters)
    <div class="d-flex flex-wrap gap-2 mb-3">
      @if(filled($q ?? ''))
        <span class="filter-chip">جستجو: {{ $q }}</span>
      @endif
      @if(filled($reservationTier ?? ''))
        <span class="filter-chip">سطح: {{ $tierFilterLabels[$reservationTier] ?? $reservationTier }}</span>
      @endif
      @if(filled($balanceStatus ?? ''))
        <span class="filter-chip">مانده: {{ $balanceFilterLabels[$balanceStatus] ?? $balanceStatus }}</span>
      @endif
      <a class="filter-chip text-decoration-none" href="{{ route('customers.index') }}">پاک کردن همه</a>
    </div>
  @endif

  <div class="soft-card desktop-customers">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 desktop-table">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>نام مشتری</th>
            <th>موبایل</th>
            <th>سطح رزرو</th>
            <th>اطلاعات تکمیلی</th>
            <th class="text-nowrap">ماهیت</th>
            <th class="text-nowrap">مانده حساب</th>
            <th class="text-nowrap">عملیات</th>
          </tr>
        </thead>

        <tbody>
          @forelse($customers as $c)
            @php
              $customerPayload = [
                'id' => $c->id,
                'name' => $c->display_name,
                'mobile' => $c->mobile,
                'address' => $c->address,
                'postal_code' => $c->postal_code,
                'extra_description' => $c->extra_description,
                'province_id' => $c->province_id,
                'city_id' => $c->city_id,
                'reservation_tier' => $c->reservation_tier,
                'update_url' => route('customers.update', $c),
              ];
              $customerBalance = (int) ($c->balance ?? 0);
              $balanceState = $customerBalance > 0 ? 'debit' : ($customerBalance < 0 ? 'credit' : 'settled');
              $balanceLabel = ['debit' => 'بدهکار', 'credit' => 'بستانکار', 'settled' => 'تسویه'][$balanceState];
              $balanceAmount = abs($customerBalance);
            @endphp

            <tr>
              <td>{{ $c->id }}</td>

              <td>
                <div class="fw-semibold">{{ $c->display_name ?: '—' }}</div>

                @if((int)$c->opening_balance !== 0)
                  <div class="small text-muted">
                    مانده اولیه: {{ number_format((int)$c->opening_balance) }}
                  </div>
                @endif
              </td>

              <td class="text-nowrap">{{ $c->mobile ?: '—' }}</td>

              <td><span class="customer-tier-badge {{ $c->reservationTierBadgeClass() }}">{{ $c->reservationTierLabel() }}</span></td>

              <td style="max-width: 360px">
                <div>{{ $c->address ?: '—' }}</div>

                @if($c->postal_code)
                  <div class="small text-muted mt-1">
                    کدپستی: {{ $c->postal_code }}
                  </div>
                @endif

                @if($c->extra_description)
                  <div class="small text-muted mt-1" style="white-space: pre-line;">
                    {{ $c->extra_description }}
                  </div>
                @endif
              </td>

              <td class="text-nowrap">
                <span class="customer-balance-badge is-{{ $balanceState }}">{{ $balanceLabel }}</span>
              </td>
              <td class="text-nowrap customer-balance-amount is-{{ $balanceState }}">{{ number_format($balanceAmount) }}</td>

              <td class="text-nowrap operations-cell">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-warning"
                  data-bs-toggle="modal"
                  data-bs-target="#editCustomerModal"
                  data-customer='@json($customerPayload, JSON_HEX_APOS | JSON_HEX_QUOT)'
                >
                  ویرایش
                </button>

                <form method="POST" action="{{ route('customers.destroy', $c) }}" class="d-inline"
                      onsubmit="return confirm('مشتری حذف شود؟')">
                  @csrf
                  @method('DELETE')
                  <button class="btn btn-sm btn-outline-danger">حذف</button>
                </form>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="9" class="text-center text-muted py-4">مشتری یافت نشد</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="customer-mobile-list">
    @forelse($customers as $c)
      @php
        $customerPayload = [
          'id' => $c->id,
          'name' => $c->display_name,
          'mobile' => $c->mobile,
          'address' => $c->address,
          'postal_code' => $c->postal_code,
          'extra_description' => $c->extra_description,
          'province_id' => $c->province_id,
          'city_id' => $c->city_id,
          'reservation_tier' => $c->reservation_tier,
          'update_url' => route('customers.update', $c),
        ];
        $customerBalance = (int) ($c->balance ?? 0);
        $balanceState = $customerBalance > 0 ? 'debit' : ($customerBalance < 0 ? 'credit' : 'settled');
        $balanceLabel = ['debit' => 'بدهکار', 'credit' => 'بستانکار', 'settled' => 'تسویه'][$balanceState];
        $balanceAmount = abs($customerBalance);
      @endphp

      <div class="customer-card">
        <div class="customer-card-head">
          <div>
            <div class="customer-name">{{ $c->display_name ?: '—' }}</div>

            <div class="mt-1"><span class="customer-tier-badge {{ $c->reservationTierBadgeClass() }}">{{ $c->reservationTierLabel() }}</span></div>
          </div>

          <div class="customer-id-badge">#{{ $c->id }}</div>
        </div>

        <div class="customer-info-line">
          <div class="label">موبایل</div>
          <div>{{ $c->mobile ?: '—' }}</div>
        </div>

        <div class="customer-info-line">
          <div class="label">آدرس</div>
          <div>{{ $c->address ?: '—' }}</div>
        </div>

        @if($c->postal_code)
          <div class="customer-info-line">
            <div class="label">کدپستی</div>
            <div>{{ $c->postal_code }}</div>
          </div>
        @endif

        @if($c->extra_description)
          <div class="customer-info-line">
            <div class="label">توضیحات</div>
            <div style="white-space: pre-line;">{{ $c->extra_description }}</div>
          </div>
        @endif

        <div class="balance-grid">
          <div class="balance-box">
            <div class="small-label">ماهیت حساب</div>
            <div><span class="customer-balance-badge is-{{ $balanceState }}">{{ $balanceLabel }}</span></div>
          </div>

          <div class="balance-box">
            <div class="small-label">مانده حساب</div>
            <div class="value customer-balance-amount is-{{ $balanceState }}">{{ number_format($balanceAmount) }}</div>
          </div>
        </div>

        <div class="card-actions">
          <button
            type="button"
            class="btn btn-outline-warning"
            data-bs-toggle="modal"
            data-bs-target="#editCustomerModal"
            data-customer='@json($customerPayload, JSON_HEX_APOS | JSON_HEX_QUOT)'
          >
            ویرایش
          </button>

          <form method="POST" action="{{ route('customers.destroy', $c) }}"
                onsubmit="return confirm('مشتری حذف شود؟')">
            @csrf
            @method('DELETE')
            <button class="btn btn-outline-danger w-100">حذف</button>
          </form>
        </div>
      </div>
    @empty
      <div class="customer-card text-center text-muted py-4">
        مشتری یافت نشد
      </div>
    @endforelse
  </div>

  <div class="mt-3 pagination-wrap">
    {{ $customers->links() }}
  </div>

</div>

<div class="modal fade" id="editCustomerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
    <form class="modal-content" method="POST" id="editCustomerForm" action="#">
      @csrf
      @method('PUT')

      <div class="modal-header">
        <div class="fw-bold" id="editCustomerTitle">✏️ ویرایش مشتری</div>
        <button type="button" class="btn-close ms-0" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label">نام مشتری *</label>
            <input class="form-control" name="customer_name" id="edit_customer_name" required>
          </div>

          <div class="col-12">
            <label class="form-label">موبایل *</label>
            <input class="form-control" name="mobile" id="edit_mobile" required>
          </div>

          <div class="col-12">
            <label class="form-label">سطح رزرو مشتری</label>
            <select class="form-select" name="reservation_tier" id="edit_reservation_tier">
              <option value="">معمولی پیش‌فرض</option>
              <option value="vip">VIP - رزرو بدون محدودیت</option>
              <option value="normal">معمولی - رزرو ۳ ساعته</option>
              <option value="new_or_low_purchase">جدید / کم‌خرید - رزرو ۱ ساعته</option>
            </select>
            <div class="form-text">در صورت عدم انتخاب، مشتری معمولی در نظر گرفته می‌شود. مانده حساب از بخش گردش حساب اشخاص مدیریت می‌شود.</div>
          </div>

          <div class="col-md-6">
            <label class="form-label">استان</label>
            <select class="form-select" name="province_id" id="edit_province_id">
              <option value=""></option>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">شهر</label>
            <select class="form-select" name="city_id" id="edit_city_id">
              <option value=""></option>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label">آدرس</label>
            <textarea class="form-control" name="address" id="edit_address" rows="2"></textarea>
          </div>

          <div class="col-12">
            <label class="form-label">کد پستی</label>
            <input class="form-control" name="postal_code" id="edit_postal_code">
          </div>

          <div class="col-12">
            <label class="form-label">توضیحات اضافی</label>
            <textarea class="form-control" name="extra_description" id="edit_extra_description" rows="3"></textarea>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-primary">ذخیره تغییرات</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="createCustomerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
    <form class="modal-content" method="POST" action="{{ route('customers.store') }}">
      @csrf

      <div class="modal-header">
        <div class="fw-bold">➕ ساخت مشتری</div>
        <button type="button" class="btn-close ms-0" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label">نام مشتری *</label>
            <input class="form-control" name="customer_name" value="{{ old('customer_name') }}" required>
          </div>

          <div class="col-12">
            <label class="form-label">موبایل *</label>
            <input class="form-control" name="mobile" value="{{ old('mobile') }}" required>
          </div>

          <div class="col-12">
            <label class="form-label">سطح رزرو مشتری</label>
            <select class="form-select" name="reservation_tier">
              <option value="" @selected(old('reservation_tier') === null || old('reservation_tier') === '')>معمولی پیش‌فرض</option>
              <option value="vip" @selected(old('reservation_tier') === 'vip')>VIP - رزرو بدون محدودیت</option>
              <option value="normal" @selected(old('reservation_tier') === 'normal')>معمولی - رزرو ۳ ساعته</option>
              <option value="new_or_low_purchase" @selected(old('reservation_tier') === 'new_or_low_purchase')>جدید / کم‌خرید - رزرو ۱ ساعته</option>
            </select>
            <div class="form-text">در صورت عدم انتخاب، مشتری معمولی در نظر گرفته می‌شود. مانده حساب از بخش گردش حساب اشخاص مدیریت می‌شود.</div>
          </div>

          <div class="col-md-6">
            <label class="form-label">استان</label>
            <select class="form-select" name="province_id" id="create_province_id">
              <option value=""></option>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">شهر</label>
            <select class="form-select" name="city_id" id="create_city_id">
              <option value=""></option>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label">آدرس</label>
            <textarea class="form-control" name="address" rows="2">{{ old('address') }}</textarea>
          </div>

          <div class="col-12">
            <label class="form-label">کد پستی</label>
            <input class="form-control" name="postal_code" value="{{ old('postal_code') }}">
          </div>

          <div class="col-12">
            <label class="form-label">توضیحات اضافی</label>
            <textarea class="form-control" name="extra_description" rows="3">{{ old('extra_description') }}</textarea>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-primary">ثبت مشتری</button>
      </div>
    </form>
  </div>
</div>
@endsection

@push('scripts')
<script>
const PROVINCES_API = "{{ route('locations.provinces.index') }}";
let provinces = [];

function initSelect2(selectEl, placeholder) {
  if (!window.jQuery || !window.jQuery.fn?.select2 || !selectEl) return;

  const $el = $(selectEl);

  if ($el.hasClass('select2-hidden-accessible')) {
    $el.off('select2:select select2:clear');
    $el.select2('destroy');
  }

  const modalParent = $el.closest('.modal');

  $el.select2({
    width: '100%',
    dir: 'rtl',
    placeholder: placeholder,
    allowClear: true,
    dropdownParent: modalParent.length ? modalParent : $(document.body)
  });

  $el.on('select2:select select2:clear', function () {
    this.dispatchEvent(new Event('change', { bubbles: true }));
  });
}

function setSelectDisabled(selectEl, disabled) {
  if (!selectEl) return;

  selectEl.disabled = disabled;

  if (window.jQuery && $(selectEl).hasClass('select2-hidden-accessible')) {
    $(selectEl).prop('disabled', disabled).trigger('change.select2');
  }
}

function setProvinceOptions(selectEl) {
  if (!selectEl) return;

  selectEl.innerHTML = '<option value=""></option>';

  if (!provinces.length) {
    const opt = document.createElement('option');
    opt.value = '';
    opt.textContent = 'استانی ثبت نشده است؛ seeder استان و شهر را اجرا کنید.';
    opt.disabled = true;
    selectEl.appendChild(opt);
    setSelectDisabled(selectEl, true);
    return;
  }

  provinces.forEach((p) => {
    const opt = document.createElement('option');
    opt.value = p.id;
    opt.textContent = p.name;
    selectEl.appendChild(opt);
  });

  setSelectDisabled(selectEl, false);
}

function setCityOptions(selectEl, provinceId, selectedCityId = null) {
  if (!selectEl) return;

  const province = provinces.find((p) => Number(p.id) === Number(provinceId));
  const cities = province?.cities ?? province?.city ?? [];

  selectEl.innerHTML = '<option value=""></option>';

  if (!provinceId) {
    setSelectDisabled(selectEl, true);
    if (window.jQuery) {
      $(selectEl).trigger('change.select2');
    }
    return;
  }

  if (!cities.length) {
    const opt = document.createElement('option');
    opt.value = '';
    opt.textContent = 'شهری برای این استان ثبت نشده است.';
    opt.disabled = true;
    selectEl.appendChild(opt);
    setSelectDisabled(selectEl, true);
    if (window.jQuery) {
      $(selectEl).trigger('change.select2');
    }
    return;
  }

  cities.forEach((c) => {
    const opt = document.createElement('option');
    opt.value = c.id;
    opt.textContent = c.name;

    if (selectedCityId && Number(selectedCityId) === Number(c.id)) {
      opt.selected = true;
    }

    selectEl.appendChild(opt);
  });

  setSelectDisabled(selectEl, cities.length === 0);

  if (window.jQuery) {
    $(selectEl).trigger('change.select2');
  }
}

function safeJsonParse(value) {
  try {
    return JSON.parse(value || '{}');
  } catch (e) {
    return {};
  }
}

document.addEventListener('DOMContentLoaded', async function () {
  const editModal = document.getElementById('editCustomerModal');

  const createProvince = document.getElementById('create_province_id');
  const createCity = document.getElementById('create_city_id');

  const editProvince = document.getElementById('edit_province_id');
  const editCity = document.getElementById('edit_city_id');

  try {
    const res = await fetch(PROVINCES_API, {
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    });

    if (!res.ok) {
      throw new Error('Failed to load provinces');
    }

    const json = await res.json();
    provinces = Array.isArray(json) ? json : (json?.data?.provinces ?? []);
  } catch (e) {
    provinces = [];
  }

  setProvinceOptions(createProvince);
  setProvinceOptions(editProvince);

  setCityOptions(createCity, null, null);
  setCityOptions(editCity, null, null);

  initSelect2(createProvince, 'انتخاب استان...');
  initSelect2(createCity, 'انتخاب شهر...');
  initSelect2(editProvince, 'انتخاب استان...');
  initSelect2(editCity, 'انتخاب شهر...');

  createProvince?.addEventListener('change', function () {
    setCityOptions(createCity, this.value, null);
  });

  editProvince?.addEventListener('change', function () {
    setCityOptions(editCity, this.value, null);
  });

  editModal?.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    if (!button) return;

    const customer = safeJsonParse(button.getAttribute('data-customer'));

    const form = document.getElementById('editCustomerForm');
    form.action = customer.update_url || '#';

    document.getElementById('editCustomerTitle').textContent = `✏️ ویرایش مشتری #${customer.id ?? ''}`;
    document.getElementById('edit_customer_name').value = customer.name ?? '';
    document.getElementById('edit_mobile').value = customer.mobile ?? '';
    const tierSelect = document.getElementById('edit_reservation_tier');
    if (tierSelect) {
      tierSelect.value = customer.reservation_tier ?? '';
      if (window.jQuery) {
        $(tierSelect).trigger('change.select2');
      }
    }
    document.getElementById('edit_address').value = customer.address ?? '';
    document.getElementById('edit_postal_code').value = customer.postal_code ?? '';
    document.getElementById('edit_extra_description').value = customer.extra_description ?? '';

    editProvince.value = customer.province_id ?? '';

    if (window.jQuery) {
      $(editProvince).trigger('change.select2');
    }

    setCityOptions(editCity, customer.province_id, customer.city_id);
  });
});
</script>
@endpush