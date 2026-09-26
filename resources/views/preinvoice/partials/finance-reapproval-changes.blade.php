@once
    @push('styles')
        <style>
            .finance-diff .diff-summary, .finance-diff .diff-item { border: 1px solid #dbe3eb; border-radius: .75rem; }
            .finance-diff .diff-summary { padding: .8rem 1rem; height: 100%; background: #f8fafc; }
            .finance-diff .diff-label { display: block; color: #64748b; font-size: .8rem; margin-bottom: .3rem; }
            .finance-diff .diff-value { font-weight: 700; font-variant-numeric: tabular-nums; }
            .finance-diff .diff-positive { color: #147d45; }
            .finance-diff .diff-negative { color: #bc3030; }
            .finance-diff .diff-neutral { color: #475569; }
            .finance-diff .diff-item { padding: .8rem; margin-top: .65rem; border-right-width: 4px; }
            .finance-diff .diff-item.is-added { border-right-color: #198754; background: #f5fbf7; }
            .finance-diff .diff-item.is-removed { border-right-color: #dc3545; background: #fff7f7; }
            .finance-diff .diff-item.is-changed { border-right-color: #64748b; background: #fafbfc; }
            .finance-diff .diff-pair { display: flex; flex-wrap: wrap; gap: .35rem; align-items: center; font-variant-numeric: tabular-nums; }
            .finance-diff .diff-before, .finance-diff .diff-after { border-radius: .35rem; padding: .15rem .4rem; white-space: nowrap; }
            .finance-diff .diff-before { background: #fff0f0; color: #ac2929; }
            .finance-diff .diff-after { background: #eaf7ee; color: #137340; }
            .finance-diff .diff-missing { color: #64748b; background: #eef2f6; }
            .finance-diff .diff-arrow { color: #64748b; }
        </style>
    @endpush
@endonce
@php
    $revisions = $changes['revisions'];
    $history = $changes['history'];
    $money = fn ($value) => $value === null ? '—' : number_format((int) $value);
    $date = fn ($value) => $value ? \Morilog\Jalali\Jalalian::fromDateTime($value)->format('Y/m/d H:i') : '—';
    $totalDelta = $changes['original_total'] === null ? null : (int) $invoice->total - $changes['original_total'];
    $deltaClass = fn ($delta) => $delta > 0 ? 'diff-positive' : ($delta < 0 ? 'diff-negative' : 'diff-neutral');
    $signed = fn ($delta) => ($delta > 0 ? '+' : '').number_format($delta);
    $changeLabels = ['added' => 'اضافه‌شده', 'removed' => 'حذف‌شده', 'multiple_changes' => 'ویرایش‌شده'];
@endphp
<div class="modal fade finance-diff" id="invoiceChanges{{ $invoice->id }}" tabindex="-1" aria-hidden="true" dir="rtl">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">تغییرات فاکتور {{ $invoice->uuid }} از تأیید اولیه مالی</h6>
                <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="بستن"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-4"><div class="diff-summary"><span class="diff-label">مبلغ قبل از اولین تغییر ثبت‌شده</span><span class="diff-value">{{ $money($changes['original_total']) }} ریال</span></div></div>
                    <div class="col-md-4"><div class="diff-summary"><span class="diff-label">مبلغ فعلی</span><span class="diff-value">{{ $money($invoice->total) }} ریال</span></div></div>
                    <div class="col-md-4"><div class="diff-summary"><span class="diff-label">تغییر خالص مبلغ فاکتور</span><span class="diff-value {{ $totalDelta === null ? 'diff-neutral' : $deltaClass($totalDelta) }}">{{ $totalDelta === null ? '—' : $signed($totalDelta) }} ریال</span></div></div>
                </div>

                @forelse($revisions as $revision)
                    @php $revisionDelta = (int) $revision->new_total - (int) $revision->old_total; @endphp
                    <section class="border rounded-3 p-3 mb-3" aria-label="ویرایش {{ $revision->revision_number }}">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <div class="fw-bold">ویرایش {{ number_format((int) $revision->revision_number) }} <span class="small fw-normal text-muted">· {{ $date($revision->created_at) }} · {{ $revision->actor_name ?: 'کاربر نامشخص' }}</span></div>
                            <span class="fw-bold {{ $deltaClass($revisionDelta) }}">تغییر مبلغ فاکتور: {{ $signed($revisionDelta) }} ریال</span>
                        </div>
                        @if($revision->reason_type || $revision->reason_note)
                            <div class="small text-muted mb-2">دلیل: {{ $revision->reason_type ?: '—' }} {{ $revision->reason_note ? '· '.$revision->reason_note : '' }}</div>
                        @endif
                        <div class="small text-muted">مبلغ فاکتور: {{ $money($revision->old_total) }} ← {{ $money($revision->new_total) }} ریال</div>
                        @forelse($revision->items as $item)
                            @php
                                $itemDelta = (int) ($item->new_line_total ?? 0) - (int) ($item->old_line_total ?? 0);
                                $itemType = in_array($item->change_type, ['added', 'removed'], true) ? $item->change_type : 'changed';
                            @endphp
                            <div class="diff-item is-{{ $itemType }}">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                    <div>
                                        <span class="badge {{ $itemType === 'added' ? 'bg-success' : ($itemType === 'removed' ? 'bg-danger' : 'bg-secondary') }}">{{ $changeLabels[$item->change_type] ?? $item->change_type }}</span>
                                        <strong class="ms-1">{{ $item->product_name_snapshot ?: 'کالای نامشخص' }}</strong>
                                        <span class="text-muted">/ {{ $item->variant_name_snapshot ?: '—' }}</span>
                                        @if($item->sku_snapshot)<small class="text-muted">({{ $item->sku_snapshot }})</small>@endif
                                    </div>
                                    <div class="fw-bold {{ $deltaClass($itemDelta) }}">تغییر مبلغ ردیف: {{ $signed($itemDelta) }} ریال</div>
                                </div>
                                <div class="row g-2 small">
                                    @foreach(['تعداد' => [$item->old_quantity, $item->new_quantity], 'قیمت واحد' => [$item->old_price, $item->new_price], 'تخفیف' => [$item->old_discount, $item->new_discount], 'مبلغ ردیف' => [$item->old_line_total, $item->new_line_total]] as $label => [$before, $after])
                                        <div class="col-sm-6 col-lg-3">
                                            <span class="diff-label">{{ $label }}</span>
                                            <div class="diff-pair">
                                                <span class="diff-before {{ $before === null ? 'diff-missing' : '' }}">قبل: {{ $money($before) }}</span>
                                                <span class="diff-arrow" aria-hidden="true">←</span>
                                                <span class="diff-after {{ $after === null ? 'diff-missing' : '' }}">بعد: {{ $money($after) }}</span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @empty
                            <div class="small text-muted mt-2">در این نوبت، جزئیات ردیف‌ها ثبت نشده است.</div>
                        @endforelse
                    </section>
                @empty
                    @if($history->isEmpty())
                        <div class="alert alert-warning mb-0">برای این فاکتور سابقهٔ قابل‌مقایسهٔ قبل و بعد ثبت نشده است. برای تصمیم مالی، سند و سابقهٔ آن را بررسی کنید.</div>
                    @else
                        <div class="alert alert-warning">برای این ویرایش‌ها فقط شرح تغییر ثبت شده است؛ مقادیر قبل و بعدِ همهٔ ردیف‌ها قابل بازسازی دقیق نیست.</div>
                    @endif
                @endforelse

                @if($history->isNotEmpty())
                    <div class="fw-bold mb-2">شرح‌های ثبت‌شده در سابقه</div>
                    <ul class="list-group">
                        @foreach($history as $event)
                            <li class="list-group-item">
                                <span class="text-muted small">{{ $date($event->done_at) }}</span> · {{ $event->description ?: $event->action_type }}
                                @if($event->action_type === 'seller_invoice_header_changed')
                                    <div class="diff-pair mt-1">
                                        <span class="diff-before">قبل: {{ $event->old_value === '' ? '—' : $event->old_value }}</span>
                                        <span class="diff-arrow" aria-hidden="true">←</span>
                                        <span class="diff-after">بعد: {{ $event->new_value === '' ? '—' : $event->new_value }}</span>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
