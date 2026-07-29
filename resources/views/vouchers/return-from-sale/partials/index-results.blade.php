<div class="sr-results-card card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
        <span class="small text-muted">تعداد نتایج: <span class="sr-ltr">{{ method_exists($returnRows,'total') ? number_format($returnRows->total()) : number_format($returnRows->count()) }}</span></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 sr-results-table">
            <thead><tr><th>ردیف</th><th>شماره سند</th><th>تاریخ اولیه</th><th>مشتری</th><th>وضعیت</th><th>خلاصه اقلام</th><th>تعداد کل</th><th>سالم / معیوب</th><th>مقصد</th><th>مبلغ</th><th>عملیات</th></tr></thead>
            <tbody>
            @forelse($returnRows as $row)
                @php
                    $status = $row['status'] ?? 'legacy';
                    $badge = match($status){
                        \App\Models\SalesReturnDocument::STATUS_APPLIED => 'bg-success-subtle text-success-emphasis border border-success-subtle',
                        \App\Models\SalesReturnDocument::STATUS_DRAFT => 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
                        \App\Models\SalesReturnDocument::STATUS_CANCELLED => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
                        default => 'bg-light text-muted border'
                    };
                @endphp
                <tr data-return-source="{{ $row['source'] }}" data-return-id="{{ $row['source_id'] }}">
                    <td class="sr-ltr">{{ method_exists($returnRows,'firstItem') ? $returnRows->firstItem()+$loop->index : $loop->iteration }}</td>
                    <td class="sr-ltr">{{ $row['document_number'] }}</td>
                    <td class="sr-ltr">{{ $row['returned_at_display'] }}</td>
                    <td>{{ $row['customer_name'] }}</td>
                    <td><span class="badge {{ $badge }}">{{ $row['status_label'] ?? 'قدیمی' }}</span></td>
                    <td><div class="sr-items-summary" title="{{ $row['items_summary'] }}">{{ $row['items_summary'] }}</div></td>
                    <td class="sr-ltr">{{ number_format($row['quantity']) }}</td>
                    <td>{{ $row['condition_label'] ?? $row['return_type'] }}</td>
                    <td><span>{{ $row['destination_warehouse_name'] }}</span><div class="small text-muted sr-destination-detail" title="{{ $row['destination_warehouse_details'] ?? '' }}">{{ $row['destination_warehouse_details'] ?? '' }}</div></td>
                    <td class="sr-ltr sr-amount">{{ number_format($row['total_amount']) }}</td>
                    <td>
                        <div class="sr-row-actions">
                            <a class="btn btn-sm btn-outline-primary" href="{{ $row['show_url'] }}">مشاهده</a>
                            @if($row['can_edit'] ?? false)<a class="btn btn-sm btn-outline-dark" href="{{ $row['edit_url'] }}">ویرایش</a>@endif
                            @if($row['print_url'])<a class="btn btn-sm btn-outline-secondary" href="{{ $row['print_url'] }}">چاپ</a>@endif
                            @if($row['can_cancel'] ?? false)
                                @if(($row['status'] ?? null) === \App\Models\SalesReturnDocument::STATUS_APPLIED)
                                    <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#voidSalesReturnModal" data-void-url="{{ $row['cancel_url'] }}" data-doc="{{ $row['document_number'] }}" data-customer="{{ $row['customer_name'] }}" data-amount="{{ number_format($row['total_amount']) }}" data-qty="{{ number_format($row['quantity']) }}">حذف</button>
                                @else
                                    <form class="d-inline" method="POST" action="{{ $row['cancel_url'] }}" onsubmit="return confirm('این پیش‌نویس هنوز روی موجودی و حساب مشتری اثری ندارد. حذف شود؟')">@csrf<button class="btn btn-sm btn-outline-danger">حذف پیش‌نویس</button></form>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" class="text-center text-muted py-4">سندی مطابق فیلترهای انتخاب‌شده پیدا نشد.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if(method_exists($returnRows,'links'))<div class="mt-3">{{ $returnRows->links() }}</div>@endif
@include('vouchers.return-from-sale.partials.void-modal')
