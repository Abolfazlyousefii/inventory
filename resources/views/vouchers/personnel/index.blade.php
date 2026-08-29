@extends('layouts.app')

@section('content')
@php
    $filters = $personnelFilters ?? [
        'q' => request('q', ''),
        'reference' => request('reference', ''),
        'receiver_user_id' => request('receiver_user_id', ''),
        'user_q' => request('user_q', ''),
    ];
@endphp
<div class="container py-3" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">حواله پرسنل</h4>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('vouchers.index') }}">بازگشت</a>
            <a class="btn btn-primary" href="{{ route('vouchers.section.create', 'personnel') }}">+ ثبت جدید</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('vouchers.section.index', 'personnel') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">جستجوی عمومی</label>
                    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="شماره، پرسنل، کالا، کد اموال...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">شماره حواله</label>
                    <input type="text" name="reference" value="{{ $filters['reference'] ?? '' }}" class="form-control" placeholder="TR-137 یا 137">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">تحویل‌گیرنده</label>
                    <select name="receiver_user_id" class="form-select">
                        <option value="">همه</option>
                        @foreach($personnelReceiverUsers ?? collect() as $user)
                            <option value="{{ $user->id }}" @selected((string)($filters['receiver_user_id'] ?? '') === (string)$user->id)>
                                {{ $user->name }}{{ $user->phone ? ' - '.$user->phone : '' }}{{ $user->personnel_code ? ' | کد: '.$user->personnel_code : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">ثبت‌کننده</label>
                    <input type="text" name="user_q" value="{{ $filters['user_q'] ?? '' }}" class="form-control" placeholder="نام کاربر">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">از تاریخ شمسی</label>
                    <input type="text" name="date_from" value="{{ $dateFrom ?? request('date_from') }}" class="form-control js-jalali-date @error('date_from') is-invalid @enderror" data-jdp data-jdp-only-date inputmode="numeric" autocomplete="off" placeholder="۱۴۰۵/۰۶/۰۱">
                    @error('date_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label class="form-label small">تا تاریخ شمسی</label>
                    <input type="text" name="date_to" value="{{ $dateTo ?? request('date_to') }}" class="form-control js-jalali-date @error('date_to') is-invalid @enderror" data-jdp data-jdp-only-date inputmode="numeric" autocomplete="off" placeholder="۱۴۰۵/۰۶/۳۱">
                    @error('date_to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button class="btn btn-primary">اعمال فیلتر</button>
                    <a href="{{ route('vouchers.section.index', 'personnel') }}" class="btn btn-outline-secondary">حذف فیلتر</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>شماره</th>
                        <th>تاریخ</th>
                        <th>مبدا</th>
                        <th>مقصد (پرسنل)</th>
                        <th>کاربر</th>
                        <th class="text-nowrap">تعداد اقلام</th>
                        <th class="text-end">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($vouchers as $voucher)
                    <tr>
                        <td>{{ $voucher->id }}</td>
                        <td>{{ $voucher->reference ?: ('TR-'.$voucher->id) }}</td>
                        <td class="text-nowrap">{{ \App\Support\JalaliDate::dateTime($voucher->transferred_at) }}</td>
                        <td>{{ $voucher->fromWarehouse?->name ?: 'انبار مرکزی' }}</td>
                        <td>{{ $voucher->receiverDisplayName() }}</td>
                        <td>{{ $voucher->user?->name ?: '—' }}</td>
                        <td>{{ number_format((int) $voucher->items->sum('quantity')) }}</td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('vouchers.show', $voucher) }}">مشاهده</a>
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('vouchers.edit', $voucher) }}">ویرایش</a>
                                <form method="POST" action="{{ route('vouchers.destroy', $voucher) }}" onsubmit="return confirm('این حواله حذف شود؟ موجودی به حالت قبل برمی‌گردد.');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger">حذف</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center py-4 text-muted">موردی ثبت نشده است.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $vouchers->links() }}</div>
</div>
<script>
if (window.jalaliDatepicker) {
    window.jalaliDatepicker.startWatch({
        selector: '.js-jalali-date',
        persianDigits: true,
        zIndex: 3000,
        time: false,
        hideAfterChange: true
    });
}
</script>
@endsection
