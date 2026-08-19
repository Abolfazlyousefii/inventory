@extends('layouts.app')

@section('title', 'صف ورودی موجودی')
@section('page-title', 'صف ورودی موجودی')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/warehouse-inbound-queue.css') }}">
@endpush

@section('content')
@php
    use App\Models\WarehouseInboundReceipt;
    use Morilog\Jalali\Jalalian;
    $currentStatus = $filters['status'] ?? WarehouseInboundReceipt::STATUS_PENDING;
@endphp
<div class="wiq" dir="rtl" data-detail-base="{{ url('/warehouse/inbound-queue') }}">
    <div class="wiq-head">
        <div>
            <h4 class="mb-1">صف ورودی موجودی</h4>
            <div class="text-muted">ورودی‌های برگشت از فروش، لغو فاکتور و اصلاح مالی فقط پس از تأیید انبار وارد موجودی می‌شوند.</div>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="{{ route('warehouse.inbound.index', request()->query()) }}">بروزرسانی</a>
    </div>

    <div class="wiq-stats">
        <div class="wiq-stat">
            <span>در انتظار دریافت</span>
            <strong>{{ number_format($stats['pending_count']) }}</strong>
            <small class="text-warning">{{ number_format($stats['overdue_count']) }} مورد بیشتر از ۲۴ ساعت</small>
        </div>
        <div class="wiq-stat">
            <span>تعداد مورد انتظار</span>
            <strong>{{ number_format($stats['pending_quantity']) }}</strong>
            <small class="text-muted">عدد کالا در صف</small>
        </div>
        <div class="wiq-stat">
            <span>دارای مغایرت</span>
            <strong>{{ number_format($stats['discrepancy_count']) }}</strong>
            <small class="text-danger">برای کنترل و گزارش</small>
        </div>
        <div class="wiq-stat">
            <span>دریافت‌شده امروز</span>
            <strong>{{ number_format($stats['received_today_quantity']) }}</strong>
            <small class="text-success">در {{ number_format($stats['received_today_count']) }} رسید</small>
        </div>
    </div>

    <form method="GET" action="{{ route('warehouse.inbound.index') }}" class="wiq-filter">
        <input class="form-control" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="جستجو: رسید، فاکتور، سند برگشت، مشتری یا کالا">
        <select class="form-select" name="source_type">
            <option value="">همه منابع</option>
            @foreach($sourceLabels as $key => $label)
                <option value="{{ $key }}" @selected(($filters['source_type'] ?? '') === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <select class="form-select" name="requested_by">
            <option value="">همه ثبت‌کنندگان</option>
            @foreach($requesters as $user)
                <option value="{{ $user->id }}" @selected((int)($filters['requested_by'] ?? 0) === (int)$user->id)>{{ $user->name }}</option>
            @endforeach
        </select>
        <select class="form-select" name="warehouse_id">
            <option value="">همه انبارهای مقصد</option>
            @foreach($warehouses as $warehouse)
                <option value="{{ $warehouse->id }}" @selected((int)($filters['warehouse_id'] ?? 0) === (int)$warehouse->id)>{{ $warehouse->name }}</option>
            @endforeach
        </select>
        <input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" title="از تاریخ">
        <input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" title="تا تاریخ">
        <select class="form-select" name="sort">
            <option value="newest" @selected(($filters['sort'] ?? 'newest') === 'newest')>جدیدترین ابتدا</option>
            <option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>قدیمی‌ترین ابتدا</option>
        </select>
        <input type="hidden" name="status" value="{{ $currentStatus }}">
        <button class="btn btn-primary" type="submit">اعمال</button>
    </form>

    <div class="wiq-tabs">
        @php
            $tabs = [
                WarehouseInboundReceipt::STATUS_PENDING => 'در انتظار دریافت',
                WarehouseInboundReceipt::STATUS_DISCREPANCY => 'دارای مغایرت',
                WarehouseInboundReceipt::STATUS_RECEIVED => 'دریافت‌شده',
                WarehouseInboundReceipt::STATUS_CANCELLED => 'لغوشده',
                'all' => 'همه',
            ];
        @endphp
        @foreach($tabs as $key => $label)
            @php
                $query = request()->except('page', 'status');
                $query['status'] = $key;
                $count = $key === 'all' ? array_sum($tabCounts->map(fn($v)=>(int)$v)->all()) : (int)($tabCounts[$key] ?? 0);
            @endphp
            <a href="{{ route('warehouse.inbound.index', $query) }}" class="wiq-tab {{ $currentStatus === $key ? 'active' : '' }}">
                {{ $label }} @if(in_array($key,[WarehouseInboundReceipt::STATUS_PENDING, WarehouseInboundReceipt::STATUS_DISCREPANCY], true))<b>{{ number_format($count) }}</b>@endif
            </a>
        @endforeach
    </div>

    <div class="wiq-table-card">
        <div class="table-responsive">
            <table class="table align-middle mb-0 wiq-table">
                <thead>
                <tr>
                    <th>رسید</th><th>منبع</th><th>سند / مشتری</th><th>ثبت‌کننده</th><th>مقصد</th><th>اقلام</th><th>مورد انتظار / تأیید</th><th>مغایرت</th><th>ایجاد</th><th>وضعیت</th><th></th>
                </tr>
                </thead>
                <tbody>
                @forelse($receipts as $receipt)
                    @php
                        $sourceClass = match($receipt->source_type){
                            WarehouseInboundReceipt::SOURCE_SALES_RETURN => 'return',
                            WarehouseInboundReceipt::SOURCE_INVOICE_CANCEL => 'cancel',
                            default => 'finance'
                        };
                        $statusClass = match($receipt->status){
                            WarehouseInboundReceipt::STATUS_PENDING => 'pending',
                            WarehouseInboundReceipt::STATUS_RECEIVED => 'received',
                            WarehouseInboundReceipt::STATUS_DISCREPANCY => 'discrepancy',
                            default => 'cancelled'
                        };
                    @endphp
                    <tr>
                        <td><b dir="ltr">{{ $receipt->receipt_number }}</b><div class="wiq-muted">#{{ $receipt->id }}</div></td>
                        <td><span class="wiq-source wiq-source-{{ $sourceClass }}"><i></i>{{ $sourceLabels[$receipt->source_type] ?? $receipt->source_type }}</span></td>
                        <td><b>{{ $receipt->source_number_snapshot ?: '—' }}</b><div class="wiq-muted">{{ $receipt->customer_name_snapshot ?: '—' }}</div></td>
                        <td>{{ $receipt->requester?->name ?: '—' }}</td>
                        <td>{{ $receipt->items->map(fn($item) => $item->receivedWarehouse?->name ?: $item->suggestedWarehouse?->name)->filter()->unique()->join('، ') ?: '—' }}</td>
                        <td>{{ number_format($receipt->items_count) }} قلم</td>
                        <td><b>{{ number_format($receipt->expected_quantity) }}</b> / {{ $receipt->isPending() ? '—' : number_format($receipt->accepted_quantity) }}</td>
                        <td><span dir="ltr">{{ $receipt->isPending() ? '—' : sprintf('%+d', $receipt->difference) }}</span></td>
                        <td>{{ $receipt->created_at ? Jalalian::fromDateTime($receipt->created_at)->format('Y/m/d H:i') : '—' }}<div class="wiq-muted">{{ $receipt->created_at?->diffForHumans() }}</div></td>
                        <td><span class="wiq-badge wiq-badge-{{ $statusClass }}">{{ $statusLabels[$receipt->status] ?? $receipt->status }}</span></td>
                        <td><button type="button" class="btn btn-sm {{ $receipt->isPending() ? 'btn-primary' : 'btn-outline-secondary' }}" data-wiq-open="{{ route('warehouse.inbound.show', $receipt) }}">{{ $receipt->isPending() ? 'بررسی و دریافت' : 'مشاهده' }}</button></td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="text-center text-muted py-5">موردی مطابق فیلترهای انتخاب‌شده وجود ندارد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($receipts->hasPages())<div class="p-3 border-top">{{ $receipts->links() }}</div>@endif
    </div>
</div>

<div class="wiq-drawer" id="wiqDrawer" aria-hidden="true">
    <button class="wiq-backdrop" type="button" data-wiq-close aria-label="بستن"></button>
    <aside class="wiq-panel" role="dialog" aria-modal="true" aria-label="بررسی رسید ورودی">
        <div class="wiq-drawer-loading">در حال دریافت اطلاعات رسید...</div>
        <div id="wiqDrawerContent"></div>
    </aside>
</div>
@endsection

@push('scripts')
    <script src="{{ asset('js/warehouse-inbound-queue.js') }}" defer></script>
@endpush
