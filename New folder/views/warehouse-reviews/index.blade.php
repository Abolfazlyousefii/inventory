@extends('layouts.app')

@php use Morilog\Jalali\Jalalian; @endphp

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0">سوابق تأیید انبار</h4>
        <a href="{{ route('preinvoice.warehouse.index') }}" class="btn btn-outline-secondary">صف تأیید انبار</a>
    </div>

    <form class="card border-0 shadow-sm mb-3" method="GET" action="{{ route('warehouse.reviews.index') }}">
        <div class="card-body row g-2 align-items-end">
            <div class="col-md-2"><label class="form-label small">شماره</label><input class="form-control" name="uuid" value="{{ $filters['uuid'] ?? '' }}"></div>
            <div class="col-md-2"><label class="form-label small">مشتری</label><input class="form-control" name="customer" value="{{ $filters['customer'] ?? '' }}"></div>
            <div class="col-md-2"><label class="form-label small">ثبت‌کننده</label><select class="form-select" name="creator_id"><option value="">همه</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected(($filters['creator_id'] ?? '') == $user->id)>{{ $user->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label small">انباردار</label><select class="form-select" name="reviewer_id"><option value="">همه</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected(($filters['reviewer_id'] ?? '') == $user->id)>{{ $user->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label small">وضعیت</label><select class="form-select" name="status"><option value="">همه</option>@foreach($statusLabels as $status => $label)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-md-1"><label class="form-label small">از تاریخ</label><input type="date" class="form-control" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></div>
            <div class="col-md-1"><label class="form-label small">تا تاریخ</label><input type="date" class="form-control" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></div>
            <div class="col-md-3 form-check mt-2"><input class="form-check-input" type="checkbox" name="changed_only" value="1" id="changedOnly" @checked((bool)($filters['changed_only'] ?? false))><label class="form-check-label" for="changedOnly">فقط موارد تغییرکرده</label></div>
            <div class="col-md-3 form-check mt-2"><input class="form-check-input" type="checkbox" name="rejected_only" value="1" id="rejectedOnly" @checked((bool)($filters['rejected_only'] ?? false))><label class="form-check-label" for="rejectedOnly">فقط ردشده‌ها</label></div>
            <div class="col-md-6 text-end"><button class="btn btn-primary">اعمال فیلتر</button><a href="{{ route('warehouse.reviews.index') }}" class="btn btn-outline-secondary">پاک کردن</a></div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>شماره</th><th>مشتری</th><th>ثبت‌کننده</th><th>آخرین انباردار</th><th>وضعیت نهایی</th><th>آیتم‌ها</th><th>تغییرات</th><th>ورود به صف</th><th>آخرین اقدام</th><th class="text-end">عملیات</th></tr></thead>
                <tbody>
                @forelse($orders as $order)
                    <tr>
                        <td>{{ $order->uuid }}</td>
                        <td>{{ $order->customer_name ?: '—' }}</td>
                        <td>{{ $order->creator?->name ?? '—' }}</td>
                        <td>{{ $order->warehouseReviewer?->name ?? '—' }}</td>
                        <td><span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">{{ $order->status_label }}</span></td>
                        <td>{{ number_format((int) $order->items_count) }}</td>
                        <td>{{ number_format((int) $order->changes_count) }}</td>
                        <td>{{ $order->created_at ? Jalalian::fromDateTime($order->created_at)->format('Y/m/d H:i') : '—' }}</td>
                        <td>{{ $order->last_review_action_at ? Jalalian::fromDateTime($order->last_review_action_at)->format('Y/m/d H:i') : '—' }}</td>
                        <td class="text-end"><div class="d-flex gap-1 justify-content-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('warehouse.reviews.show', $order->uuid) }}">مشاهده پرونده</a><a class="btn btn-sm btn-outline-dark" target="_blank" href="{{ route('warehouse.reviews.print', $order->uuid) }}">چاپ</a><a class="btn btn-sm btn-outline-secondary" href="{{ route('preinvoice.my.show', $order->uuid) }}">پیش‌فاکتور</a></div></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center py-4 text-muted">پرونده‌ای برای نمایش وجود ندارد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $orders->links() }}</div>
</div>
@endsection
