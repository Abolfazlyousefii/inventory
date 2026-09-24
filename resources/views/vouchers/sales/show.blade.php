@extends('layouts.app')

@php
	use App\Models\Invoice;
	use App\Support\Currency;
	use Morilog\Jalali\Jalalian;

	$toJalali = fn($date) => $date ? Jalalian::fromDateTime($date)->format('Y/m/d H:i') : '—';
	$money = fn($amount) => Currency::formatRial((int) $amount);
	$itemsCount = (int) $invoice->items->sum('quantity');
	$shippingMethod = $invoice->dispatchShippingMethod?->name
		?? $invoice->shippingMethod?->name
		?? ($invoice->shipping_method_id || $invoice->shipping_id ? 'روش ارسال #' . ($invoice->shipping_method_id ?: $invoice->shipping_id) : null);
	$shippingCost = (int) ($invoice->shipping_cost ?? $invoice->shipping_price ?? 0);
	$hasShippingInfo = filled($shippingMethod) || $shippingCost > 0 || filled($invoice->shipping_note) || filled($invoice->shipped_at) || filled($invoice->shipped_by);
	$statusClass = match ((string) $invoice->status) {
		Invoice::STATUS_SHIPPED => 'is-success',
		Invoice::STATUS_READY_TO_SHIP => 'is-ready',
		Invoice::STATUS_PENDING_FINANCE_REAPPROVAL => 'is-warning',
		default => 'is-default',
	};
	$noteRows = collect([
		['title' => 'یادداشت جمع‌آوری', 'body' => $invoice->collection_note],
		['title' => 'توضیحات ارسال', 'body' => $invoice->shipping_note],
	])->filter(fn($row) => filled($row['body']));

	$removedReasonLabels = [
		'physical_shortage' => 'کسری فیزیکی',
		'customer_cancelled' => 'انصراف مشتری',
		'wrong_item' => 'کالای اشتباه',
		'warehouse_correction' => 'اصلاح انبار',
		'replacement' => 'جایگزینی کالا',
		'invoice_correction' => 'اصلاح فاکتور',
		'other' => 'سایر',
	];
	$removedItems = collect();
	$currentItemRevisionHistory = collect();
	if (
		\Illuminate\Support\Facades\Schema::hasTable('invoice_collection_revisions')
		&& \Illuminate\Support\Facades\Schema::hasTable('invoice_collection_revision_items')
	) {
		$baseRevisionQuery = \Illuminate\Support\Facades\DB::table('invoice_collection_revision_items as revision_item')
			->join('invoice_collection_revisions as revision', 'revision.id', '=', 'revision_item.invoice_collection_revision_id')
			->leftJoin('users as changer', 'changer.id', '=', 'revision.changed_by')
			->where('revision.invoice_id', $invoice->id);

		$removedItems = (clone $baseRevisionQuery)
			->where('revision_item.change_type', 'removed')
			->select([
				'revision_item.id','revision_item.product_id','revision_item.product_variant_id',
				'revision_item.product_name_snapshot','revision_item.variant_name_snapshot','revision_item.sku_snapshot',
				'revision_item.old_quantity','revision_item.old_price','revision_item.old_discount','revision_item.old_line_total',
				'revision.revision_number','revision.reason_type','revision.reason_note','revision.changed_by',
				'revision.created_at as changed_at','changer.name as changed_by_name',
			])
			->orderBy('revision.revision_number')->orderBy('revision_item.id')->get();

		$currentItemRevisionHistory = (clone $baseRevisionQuery)
			->whereNotNull('revision_item.invoice_item_id')
			->whereIn('revision_item.change_type', ['added', 'multiple_changes'])
			->select([
				'revision_item.id','revision_item.invoice_item_id','revision_item.change_type',
				'revision_item.old_quantity','revision_item.new_quantity','revision_item.old_price','revision_item.new_price',
				'revision_item.old_discount','revision_item.new_discount','revision_item.old_line_total','revision_item.new_line_total',
				'revision.revision_number','revision.reason_type','revision.reason_note','revision.changed_by',
				'revision.created_at as changed_at','changer.name as changed_by_name',
			])
			->orderBy('revision.revision_number')->orderBy('revision_item.id')->get()->groupBy('invoice_item_id');
	}
@endphp

@section('content')
	<style>
		.invoice-view-page{--blue:#2563eb;--blue-soft:#eff6ff;--border:#dbeafe;--text:#0f172a;--muted:#64748b;max-width:100%;overflow-x:hidden;color:var(--text)}
		.invoice-view-header{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:16px;background:linear-gradient(135deg,#eff6ff,#fff);border:1px solid var(--border);border-radius:20px;padding:18px;box-shadow:0 10px 28px rgba(15,23,42,.05)}
		.invoice-view-title{font-weight:900;font-size:1.25rem;margin:0;color:var(--text)}.invoice-view-subtitle{color:var(--muted);font-size:.84rem;margin-top:5px;overflow-wrap:anywhere}.invoice-view-actions{display:flex;gap:8px;flex-wrap:wrap}.invoice-view-actions .btn{border-radius:12px;font-weight:800}
		.invoice-view-card{background:#fff;border:1px solid rgba(37,99,235,.12);border-radius:18px;box-shadow:0 10px 28px rgba(15,23,42,.05);padding:16px;margin-bottom:14px}.invoice-view-card-title{font-weight:900;margin-bottom:12px;color:var(--text)}
		.invoice-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.invoice-summary-item{background:#f8fbff;border:1px solid #e2e8f0;border-radius:14px;padding:10px 12px;min-width:0}.invoice-summary-label{color:var(--muted);font-size:.75rem;margin-bottom:4px}.invoice-summary-value{color:var(--text);font-weight:800;font-size:.88rem;overflow-wrap:anywhere}.invoice-status-badge{display:inline-flex;border-radius:999px;padding:5px 11px;font-weight:900;font-size:.78rem;border:1px solid #e2e8f0;background:#f8fafc;color:#475569}.invoice-status-badge.is-success{background:#ecfdf5;color:#047857;border-color:#bbf7d0}.invoice-status-badge.is-ready{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}.invoice-status-badge.is-warning{background:#fffbeb;color:#b45309;border-color:#fde68a}
		.invoice-items-table{width:100%;table-layout:fixed;margin:0}.invoice-items-table th{background:#f8fbff;font-size:.76rem;color:#475569;font-weight:800}.invoice-items-table td{font-size:.82rem;vertical-align:middle;overflow-wrap:anywhere}.invoice-item-card{background:#f8fbff;border:1px solid #e2e8f0;border-radius:14px;padding:12px}.removed-history-row td{background:#f8fafc!important;color:#94a3b8!important}.removed-history-row .removed-history-text{text-decoration:line-through}.revision-added-row td{background:#f0fdf4!important}.revision-changed-row td{background:#fffbeb!important}.removed-history-badge,.revision-added-badge,.revision-changed-badge{display:inline-flex;border-radius:999px;padding:3px 8px;font-size:.7rem;font-weight:900;white-space:nowrap}.removed-history-badge{border:1px solid #fecaca;background:#fef2f2;color:#991b1b}.revision-added-badge{border:1px solid #bbf7d0;background:#dcfce7;color:#166534}.revision-changed-badge{border:1px solid #fde68a;background:#fef3c7;color:#92400e}.removed-history-meta,.revision-meta{display:block;color:#64748b;font-size:.69rem;margin-top:4px;line-height:1.6}.revision-old{color:#94a3b8;text-decoration:line-through}.revision-arrow{color:#64748b;font-size:.75rem;margin:0 3px}.invoice-item-card.is-added{background:#f0fdf4;border-color:#bbf7d0}.invoice-item-card.is-changed{background:#fffbeb;border-color:#fde68a}.invoice-item-card.is-removed{background:#f8fafc;border-style:dashed;color:#94a3b8}.invoice-item-card.is-removed .value{text-decoration:line-through;color:#94a3b8}.invoice-item-card.is-removed .removed-history-badge,.invoice-item-card.is-removed .removed-history-meta{text-decoration:none}.invoice-item-card .label{color:var(--muted);font-size:.72rem}.invoice-item-card .value{font-weight:800;font-size:.84rem;overflow-wrap:anywhere}.invoice-note-card{background:#f8fbff;border:1px solid #e2e8f0;border-radius:14px;padding:12px}.invoice-empty-note{color:var(--muted);background:#f8fbff;border:1px dashed #cbd5e1;border-radius:14px;padding:14px;text-align:center;font-weight:800}.invoice-meta-list{display:grid;gap:8px}.invoice-meta-row{display:flex;justify-content:space-between;gap:10px;border-bottom:1px solid #eef2f7;padding-bottom:8px}.invoice-meta-row:last-child{border-bottom:0;padding-bottom:0}.invoice-meta-row span:first-child{color:var(--muted)}.invoice-meta-row span:last-child{font-weight:800;text-align:left;overflow-wrap:anywhere}
		@media(max-width:767.98px){.invoice-view-page{padding-inline:10px!important}.invoice-view-header{flex-direction:column;align-items:stretch}.invoice-view-actions .btn{flex:0 1 auto}.invoice-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.invoice-items-desktop{display:none}.invoice-items-mobile{display:grid;gap:10px}}
		@media(min-width:768px){.invoice-items-mobile{display:none}}
	</style>

	<div class="container py-4 invoice-view-page">
		<div class="invoice-view-header">
			<div>
				<h1 class="invoice-view-title">مشاهده فاکتور</h1>
				<div class="invoice-view-subtitle">فاکتور شماره {{ $invoice->uuid }} — اطلاعات کامل فاکتور و اقلام آن به صورت فقط‌خواندنی</div>
			</div>
			<div class="invoice-view-actions">
				<a href="{{ url()->previous() === url()->current() ? route('vouchers.sales.queue') : url()->previous() }}" class="btn btn-outline-secondary">بازگشت</a>
				<a href="{{ route('vouchers.sales.print', $invoice->uuid) }}" target="_blank" class="btn btn-primary">چاپ</a>
			</div>
		</div>


		@if(auth()->user()?->hasAnyRole(['admin','Admin','Manager','manager']) && ($invoice->hasZeroPriceItems() || $invoice->hasTotalMismatch()))
			<div class="alert alert-warning d-flex gap-2 flex-wrap">
				@if($invoice->hasZeroPriceItems())
					<span class="badge bg-danger">هشدار قیمت صفر</span>
				@endif
				@if($invoice->hasTotalMismatch())
					<span class="badge bg-warning text-dark">هشدار مغایرت مبلغ</span>
				@endif
			</div>
		@endif

		<div class="invoice-view-card">
			<div class="invoice-view-card-title">خلاصه فاکتور</div>
			<div class="invoice-summary-grid">
				<div class="invoice-summary-item"><div class="invoice-summary-label">شماره فاکتور</div><div class="invoice-summary-value" dir="ltr">{{ $invoice->uuid }}</div></div>
				<div class="invoice-summary-item"><div class="invoice-summary-label">مشتری</div><div class="invoice-summary-value">{{ $invoice->customer_name ?: '—' }}</div></div>
				<div class="invoice-summary-item"><div class="invoice-summary-label">موبایل مشتری</div><div class="invoice-summary-value" dir="ltr">{{ $invoice->customer_mobile ?: '—' }}</div></div>
				<div class="invoice-summary-item"><div class="invoice-summary-label">وضعیت فعلی</div><div class="invoice-summary-value"><span class="invoice-status-badge {{ $statusClass }}">{{ $statusLabels[$invoice->status] ?? 'نامشخص' }}</span></div></div>
				<div class="invoice-summary-item"><div class="invoice-summary-label">مبلغ کل</div><div class="invoice-summary-value">{{ $money($invoice->total) }}</div></div>
				<div class="invoice-summary-item"><div class="invoice-summary-label">تعداد اقلام</div><div class="invoice-summary-value">{{ number_format($itemsCount) }} قلم</div></div>
				<div class="invoice-summary-item"><div class="invoice-summary-label">فروشنده / اپراتور</div><div class="invoice-summary-value">{{ $invoice->preinvoiceOrder?->creator?->name ?: '—' }}</div></div>
				<div class="invoice-summary-item"><div class="invoice-summary-label">تاریخ ایجاد</div><div class="invoice-summary-value">{{ $toJalali($invoice->created_at) }}</div></div>
				<div class="invoice-summary-item"><div class="invoice-summary-label">تاریخ تایید/تغییر مالی</div><div class="invoice-summary-value">{{ $toJalali($invoice->status_changed_at) }}</div></div>
				<div class="invoice-summary-item"><div class="invoice-summary-label">تاریخ آماده ارسال</div><div class="invoice-summary-value">{{ $invoice->status === Invoice::STATUS_READY_TO_SHIP ? $toJalali($invoice->status_changed_at) : $toJalali($invoice->collected_at) }}</div></div>
				<div class="invoice-summary-item"><div class="invoice-summary-label">تاریخ ارسال</div><div class="invoice-summary-value">{{ $toJalali($invoice->shipped_at) }}</div></div>
				<div class="invoice-summary-item"><div class="invoice-summary-label">آدرس</div><div class="invoice-summary-value">{{ $invoice->customer_address ?: '—' }}</div></div>
			</div>
		</div>

		<div class="invoice-view-card">
			<div class="invoice-view-card-title">اطلاعات ارسال</div>
			@if($hasShippingInfo)
				<div class="invoice-meta-list">
					<div class="invoice-meta-row"><span>روش ارسال</span><span>{{ $shippingMethod ?: '—' }}</span></div>
					<div class="invoice-meta-row"><span>هزینه ارسال</span><span>{{ $money($shippingCost) }}</span></div>
					<div class="invoice-meta-row"><span>توضیحات ارسال</span><span>{{ $invoice->shipping_note ?: '—' }}</span></div>
					<div class="invoice-meta-row"><span>زمان ارسال</span><span>{{ $toJalali($invoice->shipped_at) }}</span></div>
					<div class="invoice-meta-row"><span>ارسال‌کننده</span><span>{{ $invoice->shippedBy?->name ?: '—' }}</span></div>
				</div>
			@else
				<div class="invoice-empty-note">اطلاعات ارسال هنوز ثبت نشده است.</div>
			@endif
		</div>

		<div class="invoice-view-card">
			<div class="invoice-view-card-title">اقلام فاکتور</div>
			<div class="invoice-items-desktop">
				<table class="table invoice-items-table align-middle">
					<thead><tr><th>محصول</th><th>مدل / تنوع</th><th>کد کالا / SKU</th><th>تعداد</th><th>قیمت</th><th>تخفیف ردیف</th><th>جمع</th></tr></thead>
					<tbody>
					@foreach($invoice->items as $it)
						@php
							$code = $it->variant?->variant_code ?: ($it->variant?->sku ?: ($it->product?->sku ?: ($it->product?->code ?: '—')));
							$discount = (int) ($it->line_discount_amount ?? 0);
							$itemRevisions = $currentItemRevisionHistory->get($it->id, collect());
							$latestRevision = $itemRevisions->last();
							$addedRevision = $itemRevisions->firstWhere('change_type', 'added');
							$isChangedRevision = $latestRevision && $latestRevision->change_type === 'multiple_changes';
							$revisionReason = $latestRevision ? ($removedReasonLabels[$latestRevision->reason_type] ?? ($latestRevision->reason_type ?: 'اصلاح فاکتور')) : null;
							$revisionAt = $latestRevision?->changed_at ? \Morilog\Jalali\Jalalian::fromDateTime(\Illuminate\Support\Carbon::parse($latestRevision->changed_at))->format('Y/m/d H:i') : null;
						@endphp
						<tr class="{{ $addedRevision ? 'revision-added-row' : ($isChangedRevision ? 'revision-changed-row' : '') }}">
							<td>
								{{ $it->product?->name ?? '#'.$it->product_id }}
								@if($addedRevision)<span class="revision-meta"><span class="revision-added-badge">اضافه‌شده به فاکتور</span> · اصلاح #{{ $addedRevision->revision_number }}</span>@endif
								@if($isChangedRevision)<span class="revision-meta"><span class="revision-changed-badge">ویرایش‌شده</span> · اصلاح #{{ $latestRevision->revision_number }} · {{ $revisionReason }} · {{ $latestRevision->changed_by_name ?: ('کاربر #'.$latestRevision->changed_by) }} · {{ $revisionAt }}</span>@endif
							</td>
							<td>{{ $it->variant?->variant_name ?? $it->variant?->variety_name ?? '—' }}</td>
							<td dir="ltr">{{ $code }}</td>
							<td>
								{{ number_format((int)$it->quantity) }}
								@if($isChangedRevision && $latestRevision->old_quantity !== null && (int)$latestRevision->old_quantity !== (int)$latestRevision->new_quantity)<span class="revision-meta"><span class="revision-old">{{ number_format((int)$latestRevision->old_quantity) }}</span><span class="revision-arrow">←</span>{{ number_format((int)$latestRevision->new_quantity) }}</span>@endif
							</td>
							<td>{{ $money($it->price) }}@if($isChangedRevision && $latestRevision->old_price !== null && (int)$latestRevision->old_price !== (int)$latestRevision->new_price)<span class="revision-meta"><span class="revision-old">{{ $money($latestRevision->old_price) }}</span><span class="revision-arrow">←</span>{{ $money($latestRevision->new_price) }}</span>@endif</td>
							<td>{{ $money($discount) }}@if($isChangedRevision && $latestRevision->old_discount !== null && (int)$latestRevision->old_discount !== (int)$latestRevision->new_discount)<span class="revision-meta"><span class="revision-old">{{ $money($latestRevision->old_discount) }}</span><span class="revision-arrow">←</span>{{ $money($latestRevision->new_discount) }}</span>@endif</td>
							<td>{{ $money($it->line_total) }}@if($isChangedRevision && $latestRevision->old_line_total !== null && (int)$latestRevision->old_line_total !== (int)$latestRevision->new_line_total)<span class="revision-meta"><span class="revision-old">{{ $money($latestRevision->old_line_total) }}</span><span class="revision-arrow">←</span>{{ $money($latestRevision->new_line_total) }}</span>@endif</td>
						</tr>
					@endforeach
					@foreach($removedItems as $removed)
						@php
							$removedReason = $removedReasonLabels[$removed->reason_type] ?? ($removed->reason_type ?: 'اصلاح انبار');
							$removedAt = $removed->changed_at ? \Morilog\Jalali\Jalalian::fromDateTime(\Illuminate\Support\Carbon::parse($removed->changed_at))->format('Y/m/d H:i') : '—';
						@endphp
						<tr class="removed-history-row">
							<td><span class="removed-history-text">{{ $removed->product_name_snapshot ?: '#'.$removed->product_id }}</span><span class="removed-history-meta"><span class="removed-history-badge">حذف‌شده از فاکتور</span> · اصلاح #{{ $removed->revision_number }}</span></td>
							<td><span class="removed-history-text">{{ $removed->variant_name_snapshot ?: '—' }}</span></td>
							<td dir="ltr"><span class="removed-history-text">{{ $removed->sku_snapshot ?: '—' }}</span></td>
							<td>{{ number_format((int)($removed->old_quantity ?? 0)) }} ← ۰</td>
							<td>{{ $money($removed->old_price ?? 0) }}</td>
							<td>{{ $money($removed->old_discount ?? 0) }}</td>
							<td>{{ $money($removed->old_line_total ?? 0) }}<span class="removed-history-meta">{{ $removedReason }} · {{ $removedAt }}</span></td>
						</tr>
					@endforeach
					@if($invoice->items->isEmpty() && $removedItems->isEmpty())
						<tr><td colspan="7" class="text-center text-muted py-4">قلمی ثبت نشده است.</td></tr>
					@endif
					</tbody>
				</table>
			</div>
			<div class="invoice-items-mobile">
				@foreach($invoice->items as $it)
					@php
						$code = $it->variant?->variant_code ?: ($it->variant?->sku ?: ($it->product?->sku ?: ($it->product?->code ?: '—')));
						$discount = (int) ($it->line_discount_amount ?? 0);
						$itemRevisions = $currentItemRevisionHistory->get($it->id, collect());
						$latestRevision = $itemRevisions->last();
						$addedRevision = $itemRevisions->firstWhere('change_type', 'added');
						$isChangedRevision = $latestRevision && $latestRevision->change_type === 'multiple_changes';
						$revisionReason = $latestRevision ? ($removedReasonLabels[$latestRevision->reason_type] ?? ($latestRevision->reason_type ?: 'اصلاح فاکتور')) : null;
						$revisionAt = $latestRevision?->changed_at ? \Morilog\Jalali\Jalalian::fromDateTime(\Illuminate\Support\Carbon::parse($latestRevision->changed_at))->format('Y/m/d H:i') : null;
					@endphp
					<div class="invoice-item-card {{ $addedRevision ? 'is-added' : ($isChangedRevision ? 'is-changed' : '') }}">
						<div class="d-flex justify-content-between gap-2 align-items-start mb-2">
							<div class="value">{{ $it->product?->name ?? '#'.$it->product_id }}</div>
							@if($addedRevision)<span class="revision-added-badge">اضافه‌شده</span>@elseif($isChangedRevision)<span class="revision-changed-badge">ویرایش‌شده</span>@endif
						</div>
						<div class="row g-2">
							<div class="col-6"><div class="label">مدل / تنوع</div><div class="value">{{ $it->variant?->variant_name ?? $it->variant?->variety_name ?? '—' }}</div></div>
							<div class="col-6"><div class="label">کد کالا / SKU</div><div class="value" dir="ltr">{{ $code }}</div></div>
							<div class="col-6"><div class="label">تعداد فعلی</div><div class="value">{{ number_format((int)$it->quantity) }}</div>@if($isChangedRevision && $latestRevision->old_quantity !== null)<div class="revision-meta">قبلی: {{ number_format((int)$latestRevision->old_quantity) }}</div>@endif</div>
							<div class="col-6"><div class="label">قیمت فعلی</div><div class="value">{{ $money($it->price) }}</div>@if($isChangedRevision && $latestRevision->old_price !== null)<div class="revision-meta">قبلی: {{ $money($latestRevision->old_price) }}</div>@endif</div>
							<div class="col-6"><div class="label">تخفیف</div><div class="value">{{ $money($discount) }}</div></div>
							<div class="col-6"><div class="label">جمع</div><div class="value">{{ $money($it->line_total) }}</div></div>
						</div>
						@if($latestRevision)<span class="revision-meta">اصلاح #{{ $latestRevision->revision_number }} · {{ $revisionReason }} · {{ $latestRevision->changed_by_name ?: ('کاربر #'.$latestRevision->changed_by) }} · {{ $revisionAt }}</span>@endif
					</div>
				@endforeach
				@foreach($removedItems as $removed)
					@php
						$removedReason = $removedReasonLabels[$removed->reason_type] ?? ($removed->reason_type ?: 'اصلاح انبار');
						$removedAt = $removed->changed_at ? \Morilog\Jalali\Jalalian::fromDateTime(\Illuminate\Support\Carbon::parse($removed->changed_at))->format('Y/m/d H:i') : '—';
					@endphp
					<div class="invoice-item-card is-removed">
						<div class="d-flex justify-content-between gap-2 align-items-start mb-2">
							<div class="value">{{ $removed->product_name_snapshot ?: '#'.$removed->product_id }}</div>
							<span class="removed-history-badge">حذف‌شده از فاکتور</span>
						</div>
						<div class="row g-2">
							<div class="col-6"><div class="label">مدل / تنوع</div><div class="value">{{ $removed->variant_name_snapshot ?: '—' }}</div></div>
							<div class="col-6"><div class="label">کد کالا / SKU</div><div class="value" dir="ltr">{{ $removed->sku_snapshot ?: '—' }}</div></div>
							<div class="col-6"><div class="label">تعداد قبلی</div><div class="value">{{ number_format((int)($removed->old_quantity ?? 0)) }}</div></div>
							<div class="col-6"><div class="label">تعداد فعلی</div><div class="value">۰ · قفل‌شده</div></div>
							<div class="col-6"><div class="label">قیمت قبلی</div><div class="value">{{ $money($removed->old_price ?? 0) }}</div></div>
							<div class="col-6"><div class="label">جمع قبلی</div><div class="value">{{ $money($removed->old_line_total ?? 0) }}</div></div>
						</div>
						<span class="removed-history-meta">اصلاح #{{ $removed->revision_number }} · {{ $removedReason }} · {{ $removed->changed_by_name ?: ('کاربر #'.$removed->changed_by) }} · {{ $removedAt }}</span>
					</div>
				@endforeach
				@if($invoice->items->isEmpty() && $removedItems->isEmpty())
					<div class="invoice-empty-note">قلمی ثبت نشده است.</div>
				@endif
			</div>
		</div>

		<div class="invoice-view-card">
			<div class="invoice-view-card-title">یادداشت‌ها</div>
			@if($noteRows->isNotEmpty() || $invoice->notes->isNotEmpty())
				<div class="d-grid gap-2">
					@foreach($noteRows as $row)
						<div class="invoice-note-card"><strong>{{ $row['title'] }}:</strong> {{ $row['body'] }}</div>
					@endforeach
					@foreach($invoice->notes as $note)
						<div class="invoice-note-card">{{ $note->body ?? $note->note ?? $note->description ?? '—' }}</div>
					@endforeach
				</div>
			@else
				<div class="invoice-empty-note">یادداشتی ثبت نشده است.</div>
			@endif
		</div>
	</div>
@endsection
