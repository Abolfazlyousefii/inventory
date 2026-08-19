@php
    use App\Models\WarehouseInboundReceipt;
    use Morilog\Jalali\Jalalian;
    $sourceLabel = WarehouseInboundReceipt::sourceLabels()[$receipt->source_type] ?? $receipt->source_type;
    $statusLabel = WarehouseInboundReceipt::statusLabels()[$receipt->status] ?? $receipt->status;
    $isPending = $receipt->isPending();
    $reasonLabels = \App\Models\WarehouseInboundReceiptItem::reasonLabels();
@endphp
<div class="wiq-drawer-head">
    <div>
        <h5 class="mb-1">{{ $isPending ? 'بررسی و دریافت کالا' : 'جزئیات رسید ورودی' }}</h5>
        <div class="wiq-muted"><span dir="ltr">{{ $receipt->receipt_number }}</span> • {{ $sourceLabel }} {{ $receipt->source_number_snapshot }}</div>
    </div>
    <button type="button" class="btn btn-light btn-sm" data-wiq-close>✕</button>
</div>

<form method="POST" action="{{ route('warehouse.inbound.receive', $receipt) }}" class="wiq-receive-form" data-wiq-form>
    @csrf
    <div class="wiq-drawer-body">
        <div class="wiq-doc-card">
            <div><span>منبع</span><b>{{ $sourceLabel }}</b></div>
            <div><span>سند مبنا</span><b>{{ $receipt->source_number_snapshot ?: '—' }}</b></div>
            <div><span>مشتری</span><b>{{ $receipt->customer_name_snapshot ?: '—' }}</b></div>
            <div><span>ثبت‌کننده</span><b>{{ $receipt->requester?->name ?: '—' }}</b></div>
            <div><span>مورد انتظار</span><b>{{ number_format($receipt->expected_quantity) }} عدد</b></div>
            <div><span>وضعیت</span><b>{{ $statusLabel }}</b></div>
            <div><span>مغایرت کل</span><b dir="ltr">{{ sprintf('%+d', $receipt->difference) }}</b></div>
            <div><span>تاریخ ایجاد</span><b>{{ $receipt->created_at ? Jalalian::fromDateTime($receipt->created_at)->format('Y/m/d H:i') : '—' }}</b></div>
            @if($receipt->reviewed_at)<div><span>بررسی‌کننده</span><b>{{ $receipt->reviewer?->name ?: '—' }}</b></div>@endif
        </div>

        @if($sourceUrl)
            <a class="btn btn-sm btn-outline-primary mb-3" href="{{ $sourceUrl }}">مشاهده سند مبدا</a>
        @else
            <div class="alert alert-secondary small mb-3">سند مبدا در دسترس نیست؛ اطلاعات تاریخی زیر از Snapshot رسید نمایش داده می‌شود.</div>
        @endif

        @if($receipt->request_note)
            <div class="alert alert-light border small"><b>یادداشت مبدا:</b> {{ $receipt->request_note }}</div>
        @endif

        <div class="wiq-items-title">
            <b>اقلام رسید</b>
            @if($isPending)<button class="btn btn-sm btn-outline-secondary" type="button" data-wiq-fill-all>دریافت کامل همه</button>@endif
        </div>

        <div class="wiq-items">
            @foreach($receipt->items as $index => $item)
                @php
                    $displayAccepted = $isPending ? $item->expected_quantity : $item->accepted_quantity;
                    $displayDifference = $displayAccepted - $item->expected_quantity;
                @endphp
                <div class="wiq-item-row" data-wiq-item data-suggested-warehouse-id="{{ $item->suggested_warehouse_id }}">
                    <div class="wiq-item-name">
                        <b>{{ $item->product_name_snapshot ?: $item->product?->name ?: 'کالای نامشخص' }}</b>
                        <div class="wiq-muted">{{ $item->variant_name_snapshot ?: $item->variant?->variant_name ?: '—' }} @if($item->sku_snapshot) • {{ $item->sku_snapshot }} @endif</div>
                    </div>
                    <div><span class="wiq-field-label">انتظار</span><b>{{ number_format($item->expected_quantity) }}</b></div>
                    <div>
                        <span class="wiq-field-label">دریافت</span>
                        @if($isPending)
                            <input type="hidden" name="items[{{ $index }}][id]" value="{{ $item->id }}">
                            <input class="form-control wiq-qty" type="number" min="0" step="1" name="items[{{ $index }}][accepted_quantity]" value="{{ $item->expected_quantity }}" data-expected="{{ $item->expected_quantity }}" required>
                            <div class="wiq-qty-diff"></div>
                        @else
                            <b>{{ number_format($item->accepted_quantity) }}</b>
                        @endif
                    </div>
                    <div>
                        <span class="wiq-field-label">مغایرت</span>
                        <span class="wiq-badge {{ $displayDifference < 0 ? 'wiq-badge-discrepancy' : ($displayDifference > 0 ? 'wiq-badge-pending' : 'wiq-badge-received') }}" data-wiq-difference>
                            {{ $displayDifference === 0 ? 'بدون مغایرت' : ($displayDifference < 0 ? 'کسری '.number_format(abs($displayDifference)) : 'اضافه '.number_format($displayDifference)) }}
                        </span>
                    </div>
                    <div>
                        <span class="wiq-field-label">مقصد</span>
                        @if($isPending)
                            <select class="form-select" name="items[{{ $index }}][received_warehouse_id]" required>
                                @foreach($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" @selected((int)$warehouse->id === (int)$item->suggested_warehouse_id)>{{ $warehouse->name }}</option>
                                @endforeach
                            </select>
                        @else
                            <b>{{ $item->receivedWarehouse?->name ?: $item->suggestedWarehouse?->name ?: '—' }}</b>
                        @endif
                    </div>
                    <div>
                        <span class="wiq-field-label">دلیل</span>
                        <b>{{ $reasonLabels[$item->reason] ?? $item->reason ?? '—' }}</b>
                    </div>
                    @if($isPending)
                        <div class="wiq-item-note">
                            <label class="wiq-field-label" for="wiq-note-{{ $item->id }}">توضیح قلم</label>
                            <input id="wiq-note-{{ $item->id }}" class="form-control form-control-sm" name="items[{{ $index }}][note]" value="{{ old("items.$index.note", $item->note) }}" maxlength="1000" placeholder="علت مغایرت این قلم">
                        </div>
                    @elseif($item->note)
                        <div class="wiq-item-note">{{ $item->note }}</div>
                    @endif
                </div>
            @endforeach
        </div>

        @if($isPending)
            <div class="wiq-info">موجودی فقط بعد از تأیید این رسید افزایش پیدا می‌کند. مقدار تأییدشده می‌تواند کمتر، صفر یا بیشتر از مقدار مورد انتظار باشد؛ برای مغایرت توضیح ثبت کنید.</div>
            <div class="alert alert-warning d-none mt-3" data-wiq-warning>مقدار تأییدشده با مقدار مورد انتظار سیستم مغایرت دارد.</div>
            <label class="form-label fw-semibold mt-3">یادداشت مدیر انبار <span class="text-muted fw-normal small">در صورت مغایرت تعداد یا تغییر مقصد الزامی است</span></label>
            <textarea class="form-control" name="review_note" rows="3" placeholder="مثلاً: یک عدد تحویل نشد یا کالا به انبار مرجوعی منتقل شد..." data-wiq-review-note></textarea>
        @elseif($receipt->review_note)
            <div class="alert alert-light border mt-3 mb-0"><b>یادداشت بررسی:</b> {{ $receipt->review_note }}</div>
        @endif
    </div>

    <div class="wiq-drawer-foot">
        @if($isPending)
            <button class="btn btn-success" type="submit" data-wiq-submit>تأیید دریافت و ورود به موجودی</button>
        @endif
        <button class="btn btn-outline-secondary" type="button" data-wiq-close>بستن</button>
    </div>
</form>
