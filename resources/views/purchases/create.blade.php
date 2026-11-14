@extends('layouts.app')

@section('content')
	@php
		$isEdit = isset($purchase) && $purchase;
        $purchaseDraftConfig=['userId'=>auth()->id(),'baseUrl'=>url('/purchases/drafts'),'saveUrl'=>route('purchases.drafts.save'),'latestUrl'=>route('purchases.drafts.latest'),'hasInitial'=>session()->hasOldInput()];
		$cnyEnabled = config('cny_pricing.enabled') || ($purchase->purchase_origin ?? '') === 'china';
        $cnyRate = ($purchase->purchase_origin ?? '') === 'china' ? optional(\App\Models\CnyExchangeRate::find($purchase->cny_rate_id))->toArray() : app(\App\Services\CnyPricingService::class)->previewRate();
        if ($isEdit && $cnyRate) $cnyRate['sample'] = ($cnyRate['provider']??'') === 'sample';
		$formAction = $isEdit ? route('purchases.update', $purchase) : route('purchases.store');
		$initialItems = old('items');
		$purchaseItemsById = $isEdit ? $purchase->items->keyBy('id') : collect();
		$purchaseItemsByVariant = $isEdit ? $purchase->items->keyBy('product_variant_id') : collect();

		if (!is_array($initialItems)) {
			$initialItems = $isEdit
				? $purchase->items->map(fn ($it) => [
					'id' => $it->id,
                    'buy_cny' => $it->buy_cny_units ? app(\App\Services\CnyPriceCalculator::class)->amount((int)$it->buy_cny_units) : '',
					'product_id' => $it->product_id,
					'category_id' => $it->product?->category_id,
					'variant_id' => $it->product_variant_id,
					'name' => $it->product_name,
					'code' => $it->product_code,
					'variant_name' => $it->variant_name,
					'variant_code' => $it->variant?->variant_code,
					'quantity' => $it->quantity,
					'buy_price' => \App\Support\Currency::toRial($it->buy_price),
					'sell_price' => \App\Support\Currency::toRial($it->sell_price),
					'original_sell_price' => \App\Support\Currency::toRial($it->sell_price),
                    'original_buy_price' => (int)$it->buy_price,
					'product_buy_price' => \App\Support\Currency::toRial($it->buy_price),
					'product_sell_price' => \App\Support\Currency::toRial($it->sell_price),
					'price_overridden' => true,
					'discount_type' => $it->discount_type,
					'discount_value' => $it->discount_type === 'amount' ? \App\Support\Currency::toRial($it->discount_value) : $it->discount_value,
				])->values()->all()
				: [];
		} else {
			// Never trust a price baseline returned by the browser. Rebuild it from
			// the persisted purchase item after every validation redirect.
			$initialItems = collect($initialItems)->map(function ($item) use ($purchaseItemsById, $purchaseItemsByVariant) {
				if (!is_array($item)) {
					return $item;
				}

				$itemId = (int) ($item['id'] ?? 0);
				$variantId = (int) ($item['variant_id'] ?? $item['product_variant_id'] ?? 0);
				$storedItem = $itemId > 0 ? $purchaseItemsById->get($itemId) : null;

				if (!$storedItem && $variantId > 0) {
					$storedItem = $purchaseItemsByVariant->get($variantId);
				}

				if ($storedItem) {
					$item['original_sell_price'] = (int) $storedItem->sell_price;
                    $item['original_buy_price'] = (int)$storedItem->buy_price;
				} else {
					unset($item['original_sell_price']);
				}

				return $item;
			})->values()->all();
		}

$productsPayload = $products->map(function ($p) {
    return [
        'id' => (int) $p->id,
        'name' => (string) $p->name,
        'code' => (string) ($p->code ?: $p->short_barcode),
        'short_barcode' => (string) ($p->short_barcode ?? ''),
        'sku' => (string) ($p->sku ?? ''),
        'category_id' => $p->category_id ? (int) $p->category_id : null,

        // Keep the field.
        // It gets filled by fetchRealProductVariants().
        'variants' => [],
    ];
})->values()->all();

		$categoriesPayload = ($categories ?? collect())->map(fn ($category) => [
			'id' => (int) $category->id,
			'name' => (string) $category->name,
		])->values()->all();
	@endphp

	<style>
		.purchase-fast-page .purchase-main-card,
		.purchase-fast-page .purchase-quick-card,
		.purchase-fast-page .purchase-summary-card,
		.purchase-fast-page .purchase-product-card {
			border: 1px solid #e5e7eb;
			border-radius: 16px;
			box-shadow: 0 10px 24px rgba(15, 23, 42, .05);
		}

		.purchase-fast-page .section-title {
			font-weight: 800;
			color: #0f172a;
		}

		.purchase-fast-page .purchase-product-card {
			background: #fff;
			overflow: hidden;
		}

		.purchase-fast-page .product-card-head {
			background: linear-gradient(90deg, #f8fafc, #eef6ff);
			border-bottom: 1px solid #e5e7eb;
			padding: 12px 14px;
		}

		.purchase-fast-page .product-code-chip,
		.purchase-fast-page .product-stat-chip {
			border: 1px solid #dbeafe;
			background: #eff6ff;
			color: #1e40af;
			border-radius: 999px;
			padding: 4px 10px;
			font-size: 12px;
			font-weight: 700;
		}

		.purchase-fast-page .price-panel {
			background: #fbfdff;
			border: 1px dashed #cbd5e1;
			border-radius: 14px;
			padding: 10px;
		}

		.purchase-fast-page .variants-table th {
			white-space: nowrap;
			font-size: 12px;
			color: #475569;
		}

		.purchase-fast-page .variants-table td {
			vertical-align: middle;
		}

		.purchase-fast-page .variant-title {
			font-weight: 700;
			color: #1f2937;
		}

		.purchase-fast-page .mono {
			font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
		}

		.purchase-fast-page .summary-sticky {
			position: sticky;
			bottom: 0;
			z-index: 20;
			background: rgba(255, 255, 255, .96);
			backdrop-filter: blur(8px);
		}

		.purchase-fast-page .qty-input { max-width: 95px; }
		.purchase-fast-page .price-input { min-width: 130px; }
		.purchase-fast-page .purchase-variant-filter {
			background: #f8fafc;
			border: 1px solid #e2e8f0;
			border-radius: 14px;
			padding: 10px;
		}
		.purchase-fast-page .purchase-variant-row.has-qty > td {
			background: #ecfdf5;
		}
		.purchase-fast-page .purchase-variant-empty {
			border: 1px dashed #cbd5e1;
			border-radius: 12px;
			background: #fff7ed;
			color: #9a3412;
			padding: 10px;
		}
		.purchase-fast-page .current-sale-price {
			border: 1px solid #dbeafe;
			background: #eff6ff;
			color: #1e40af;
			border-radius: 10px;
			padding: 6px 8px;
			font-size: 12px;
			font-weight: 800;
			white-space: nowrap;
		}
		.purchase-fast-page .current-sale-price.missing {
			border-color: #fed7aa;
			background: #fff7ed;
			color: #9a3412;
		}
	</style>

	<div class="purchase-page-wrap purchase-fast-page">
		<div class="purchase-topbar d-flex justify-content-between align-items-center mb-3">
			<h4 class="page-title mb-0">{{ $isEdit ? 'ویرایش سند خرید' : 'ثبت خرید جدید' }}</h4>
			<a class="btn btn-sm btn-outline-light" href="{{ route('purchases.index') }}">بازگشت</a>
		</div>

        @if(!$isEdit)
        <section class="alert alert-light border d-flex flex-wrap align-items-center gap-2" aria-label="پیش‌نویس خرید">
            <span id="purchaseDraftStatus" role="status" aria-live="polite">در حال بررسی پیش‌نویس خرید…</span>
            <div id="purchaseDraftActions" class="d-flex flex-wrap gap-2"></div>
        </section>
        <div id="purchaseDraftPriceNotice" class="alert alert-warning" hidden>
            <span>اطلاعات واردشده حفظ شده است. پیش از ثبت، قیمت‌های روز و تنوع‌ها را بررسی کنید.</span>
            <button type="button" id="purchaseDraftReviewPrices" class="btn btn-sm btn-outline-dark">بررسی قیمت‌های روز</button>
        </div>
        @endif
		<form method="POST" action="{{ $formAction }}" id="purchaseForm">
			@csrf
            <input type="hidden" name="uniform_product_prices" value="1">
			@if($isEdit)
				@method('PUT')
			@endif
			<input type="hidden" name="submission_token" value="{{ old('submission_token', (string) \Illuminate\Support\Str::uuid()) }}">
			<input type="hidden" name="warehouse_id" value="{{ old('warehouse_id', $purchase->warehouse_id ?? '') }}">
			<input type="hidden" name="items_json" id="itemsPayload" value="">
            <input type="hidden" name="sale_price_change_confirmed" id="salePriceChangeConfirmed" value="0">
            <input type="hidden" name="sale_price_change_reason" id="salePriceChangeReason" value="">
            @if($isEdit)
                <div class="alert alert-info small mt-2 mb-2">
                    قیمت فروش ثبت‌شده در سند برای سابقه محفوظ است. ویرایش تعداد، توضیحات یا قیمت خرید،
                    قیمت فروش روز کالا را تغییر نمی‌دهد؛ تغییر عمدی قیمت فروش نیازمند تأیید کاربر مجاز است.
                    اگر خرید جدیدتری وجود داشته باشد، اصلاح قیمت خرید فقط روی همین سند و حساب تأمین‌کننده اعمال می‌شود.
                </div>
            @endif
            @if($errors->has('sale_price_change_confirmed') || $errors->has('items'))
                <div class="alert alert-danger small mt-2" role="alert">{{ $errors->first('sale_price_change_confirmed') ?: $errors->first('items') }}</div>
            @endif


			<div class="card purchase-main-card mb-3">
				<div class="card-body">
					<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
						<div>
							<div class="section-title">اطلاعات اصلی خرید</div>
							<div class="small text-muted">تأمین‌کننده یا مشتری، انبار مقصد و توضیحات سند را وارد کنید.</div>
						</div>
						<button class="btn btn-sm btn-primary" type="submit">{{ $isEdit ? 'ذخیره تغییرات سند خرید' : 'ثبت نهایی خرید' }}</button>
					</div>

					<div class="row g-2 align-items-end">
						<div class="col-md-4">
							<label class="form-label">تامین‌کننده / مشتری</label>
							<div class="d-flex gap-2">
								<select class="form-select form-select-sm @error('supplier_id') is-invalid @enderror" name="supplier_id" id="purchaseSupplierSelect" data-placeholder="جستجوی نام یا موبایل تأمین‌کننده / مشتری..." required>
									<option value="">انتخاب کنید...</option>
									@foreach($suppliers as $supplier)
										<option value="{{ $supplier->purchase_option_value ?? $supplier->id }}" @selected(old('supplier_id', $purchase->supplier_id ?? null)==($supplier->purchase_option_value ?? $supplier->id))>
											{{ $supplier->purchase_option_label ?? $supplier->name }}@if($supplier->phone) | {{ $supplier->phone }}@endif
										</option>
									@endforeach
								</select>
								@canPermission('customers.view')
								<a href="{{ route('persons.index') }}" class="btn btn-sm btn-outline-dark">اشخاص</a>
								@endcanPermission
							</div>
							@error('supplier_id')
							<div class="invalid-feedback d-block">{{ $message }}</div>
							@enderror
						</div>
						<div class="col-md-2">
							<label class="form-label">انبار مقصد</label>
							<input class="form-control form-control-sm" value="انبار مرکزی" readonly>
						</div>
						<div class="col-md-{{ $isEdit ? '3' : '6' }}">
                            <div class="cny-note-heading">
                                <label class="form-label" for="purchaseNote">توضیحات خرید</label>
                                @if($cnyEnabled)
                                <label class="cny-toggle" for="purchaseCnyEnabled"><input type="checkbox" id="purchaseCnyEnabled" @checked(old('purchase_origin',$purchase->purchase_origin??'domestic')==='china') @disabled($isEdit)> قیمت‌گذاری با یوان</label>
                                <input type="hidden" id="purchaseOrigin" name="purchase_origin" value="{{ old('purchase_origin',$purchase->purchase_origin??'domestic') }}">
                                <input type="hidden" name="cny_rate_id" value="{{ $cnyRate['id']??'' }}">
                                <small id="cny-rate-panel" class="cny-inline-rate" hidden>
                                    @if($cnyRate)
                                        هر یوان: <strong>{{ number_format(($cnyRate['rate_rial']??0)/10) }} تومان</strong>
                                        @if($cnyRate['sample']??false)<span class="cny-sample-badge">نمونه</span>@endif
                                    @else <span class="text-warning">نرخ معتبر در دسترس نیست</span> @endif
                                </small>
                                @endif
                            </div>
							<input class="form-control form-control-sm" id="purchaseNote" name="note" value="{{ old('note', $purchase->note ?? '') }}" placeholder="اختیاری">
                            @error('cny_pricing')<div class="text-danger small mt-1" role="alert">{{ $message }}</div>@enderror
						</div>
						@if($isEdit)
							<div class="col-md-3">
								<label class="form-label">تاریخ ثبت اولیه (غیرقابل تغییر)</label>
								<input type="text" class="form-control form-control-sm" value="{{ \App\Support\JalaliDate::dateTime($purchase->created_at) }}" readonly aria-label="تاریخ ثبت اولیه سند خرید">
							</div>
						@endif
					</div>
				</div>
			</div>

			<div class="card purchase-quick-card mb-3">
				<div class="card-body">
					<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
						<div>
							<div class="section-title">افزودن سریع محصول به خرید</div>
							<div class="small text-muted">محصول را با نام، کد، بارکد یا SKU جستجو کنید؛ دسته‌بندی فقط فیلتر اختیاری است.</div>
						</div>
					</div>

					<div class="row g-2 align-items-end">
						<div class="col-md-3">
							<label class="form-label">فیلتر دسته‌بندی</label>
							<select class="form-select form-select-sm" id="quickCategoryFilter">
								<option value="">همه دسته‌بندی‌ها</option>
								@foreach($categories as $category)
									<option value="{{ $category->id }}">{{ $category->name }}</option>
								@endforeach
							</select>
						</div>
						<div class="col-md-4">
							<label class="form-label">جستجوی محصول</label>
							<input type="search" class="form-control form-control-sm" id="quickProductSearch" placeholder="نام کالا، کد، بارکد یا SKU...">
						</div>
						<div class="col-md-4">
							<label class="form-label">انتخاب کالا</label>
							<select class="form-select form-select-sm" id="quickProductSelect"></select>
						</div>
						<div class="col-md-1 d-grid">
							<button type="button" class="btn btn-sm btn-outline-primary" id="addProductBtn">افزودن</button>
						</div>
					</div>
					<div class="small text-muted mt-2" id="quickProductHelp">بعد از افزودن محصول، همه تنوع‌های آن به صورت خودکار در کارت محصول نمایش داده می‌شوند.</div>
				</div>
			</div>

			<div id="purchaseProducts" class="d-flex flex-column gap-3"></div>

			<div class="modal fade" id="salePriceChangeModal" tabindex="-1" aria-labelledby="salePriceChangeModalTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
				<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
					<div class="modal-content border-0 shadow">
						<div class="modal-header">
							<div>
								<h5 class="modal-title fw-bold" id="salePriceChangeModalTitle">تأیید تغییر قیمت فروش</h5>
								<div class="small text-muted mt-1">پیش از ذخیره سند، اثر تغییر قیمت‌ها را بررسی و دلیل آن را ثبت کنید.</div>
							</div>
						</div>
						<div class="modal-body">
							<div class="alert alert-warning small" role="status">
								این تأیید فقط برای تغییرات قیمت فروش است. قیمت ثبت‌شده در سند و قیمت جاری کاتالوگ جداگانه نمایش داده می‌شوند.
							</div>
							<div class="table-responsive">
								<table class="table table-sm table-bordered align-middle">
									<thead class="table-light">
										<tr>
											<th>کالا / تنوع</th>
											<th>قیمت قبلی سند</th>
											<th>قیمت فعلی سیستم</th>
											<th>قیمت جدید سند</th>
											<th>اثر بر کاتالوگ</th>
										</tr>
									</thead>
									<tbody id="salePriceChangeRows"></tbody>
								</table>
							</div>
							<div class="mt-3">
								<label class="form-label fw-semibold" for="salePriceChangeReasonInput">دلیل تغییر قیمت <span class="text-danger">*</span></label>
								<textarea id="salePriceChangeReasonInput" class="form-control" rows="3" maxlength="500" aria-required="true" aria-describedby="salePriceChangeReasonError" placeholder="مثلاً: اصلاح قیمت طبق لیست قیمت تأییدشده مدیریت"></textarea>
								<div class="form-text">این توضیح در تاریخچه فعالیت ثبت می‌شود و حداکثر ۵۰۰ نویسه است.</div>
								<div class="invalid-feedback d-none" id="salePriceChangeReasonError">برای ادامه، دلیل تغییر قیمت را وارد کنید.</div>
							</div>
						</div>
						<div class="modal-footer">
							<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف و بازگشت به سند</button>
							<button type="button" class="btn btn-primary" id="confirmSalePriceChangeBtn">تأیید تغییرات و ادامه ثبت</button>
						</div>
					</div>
				</div>
			</div>

			<div class="card purchase-summary-card summary-sticky mt-3">
				<div class="card-body">
					<div class="row g-2 align-items-end">
						<div class="col-md-2">
							<div class="small text-muted">محصولات انتخاب‌شده</div>
							<div class="fw-bold"><span id="summaryProductCount">0</span> محصول</div>
						</div>
						<div class="col-md-2">
							<div class="small text-muted">تنوع‌های دارای تعداد</div>
							<div class="fw-bold"><span id="summaryVariantCount">0</span> ردیف</div>
						</div>
						<div class="col-md-2">
							<div class="small text-muted">جمع قبل تخفیف کلی</div>
							<div class="fw-bold"><span id="subtotalAmount">0</span> ریال</div>
						</div>
						<div class="col-md-2">
							<label class="form-label small mb-1" for="invoiceDiscountType">نوع تخفیف کلی</label>
							<select class="form-select form-select-sm" name="invoice_discount_type" id="invoiceDiscountType">
								<option value="">بدون تخفیف</option>
								<option value="amount" @selected(old('invoice_discount_type', $purchase->discount_type ?? '')==='amount')>مبلغی</option>
								<option value="percent" @selected(old('invoice_discount_type', $purchase->discount_type ?? '')==='percent')>درصدی</option>
							</select>
						</div>
						<div class="col-md-2">
							<label class="form-label small mb-1" for="invoiceDiscountValue">مقدار تخفیف</label>
							<input data-rial data-rial-kind-source="#invoiceDiscountType" type="text" inputmode="numeric" class="form-control form-control-sm formatted-number" id="invoiceDiscountValue" name="invoice_discount_value" value="{{ old('invoice_discount_value', ($purchase->discount_type ?? null) === 'amount' ? \App\Support\Currency::toRial($purchase->discount_value ?? 0) : ($purchase->discount_value ?? 0)) }}">
						</div>
						<div class="col-md-2 text-end">
							<div class="small text-muted">جمع تخفیف: <span id="totalDiscountAmount">0</span> ریال</div>
							<div class="fw-bold fs-5" style="color: var(--ink, #0f172a);">نهایی: <span id="totalAmount">0</span> ریال</div>
							<button class="btn btn-sm btn-primary mt-2" type="submit">{{ $isEdit ? 'ذخیره تغییرات' : 'ثبت نهایی خرید' }}</button>
						</div>
					</div>
				</div>
			</div>
            <div id="purchaseSummarySpace" aria-hidden="true"></div>
		</form>
	</div>

    <link rel="stylesheet" href="{{ asset('css/cny-purchase.css') }}?v={{ filemtime(public_path('css/cny-purchase.css')) }}">
    <script src="{{ asset('js/cny-pricing.js') }}?v={{ filemtime(public_path('js/cny-pricing.js')) }}"></script>
    @if(!$isEdit)<script src="{{ asset('js/purchase-draft-autosave.js') }}?v={{ substr(hash_file('sha256',public_path('js/purchase-draft-autosave.js')),0,12) }}"></script>@endif
	<script>
		(function () {
			const cnyRate = @json($cnyRate);
            const originEl = document.getElementById('purchaseOrigin');
            const isCny = () => originEl?.value === 'china';
			const products = @json($productsPayload);
			const categories = @json($categoriesPayload);
			const initialItems = @json($initialItems);
            const DRAFT_CONFIG = @json($purchaseDraftConfig);
            const IS_EDIT_PURCHASE = @json($isEdit);
            const CAN_CHANGE_SALE_PRICE = @json(\App\Support\PermissionCatalog::userHasPermission(auth()->user(), 'products.price_changes.apply'));
			const variantsUrlTemplate = @json(route('purchases.products.variants', ['product' => '__PRODUCT__']));

			const purchaseForm = document.getElementById('purchaseForm');
			const productCardsEl = document.getElementById('purchaseProducts');
			const payloadEl = document.getElementById('itemsPayload');
			const quickCategoryEl = document.getElementById('quickCategoryFilter');
			const quickSearchEl = document.getElementById('quickProductSearch');
			const quickProductEl = document.getElementById('quickProductSelect');
			const addProductBtn = document.getElementById('addProductBtn');
			const invoiceDiscountTypeEl = document.getElementById('invoiceDiscountType');
			const invoiceDiscountValueEl = document.getElementById('invoiceDiscountValue');
			const supplierSelectEl = document.getElementById('purchaseSupplierSelect');
            const draftPriceVersions=new Map();

			const summaryProductCountEl = document.getElementById('summaryProductCount');
			const summaryVariantCountEl = document.getElementById('summaryVariantCount');
			const subtotalEl = document.getElementById('subtotalAmount');
			const totalDiscountEl = document.getElementById('totalDiscountAmount');
			const totalEl = document.getElementById('totalAmount');

            // Reserve the floating panel's actual height so the last item remains reachable.
            const summaryPanel = purchaseForm.querySelector('.summary-sticky');
            const summarySpace = document.getElementById('purchaseSummarySpace');
            function positionPurchaseSummary() {
                const bounds = purchaseForm.getBoundingClientRect();
                summaryPanel.style.setProperty('--summary-left', `${Math.max(6, bounds.left)}px`);
                summaryPanel.style.setProperty('--summary-width', `${Math.min(bounds.width, window.innerWidth - 12)}px`);
                summaryPanel.classList.add('is-floating');
                summarySpace.style.height = `${summaryPanel.getBoundingClientRect().height + 24}px`;
            }
            if (window.ResizeObserver) {
                const summaryObserver = new ResizeObserver(positionPurchaseSummary);
                summaryObserver.observe(purchaseForm);
                summaryObserver.observe(summaryPanel);
            }
            window.addEventListener('resize', positionPurchaseSummary);
            positionPurchaseSummary();

			function escapeHtml(value) {
				return String(value ?? '')
					.replace(/&/g, '&amp;')
					.replace(/</g, '&lt;')
					.replace(/>/g, '&gt;')
					.replace(/"/g, '&quot;');
			}

			function normalizeDigits(value) {
				return String(value ?? '')
					.replace(/[٠-٩]/g, d => String(d.charCodeAt(0) - 0x0660))
					.replace(/[۰-۹]/g, d => String(d.charCodeAt(0) - 0x06F0));
			}

			function normalizePurchaseSearchText(value) {
				return normalizeDigits(value)
					.replace(/[ي]/g, 'ی')
					.replace(/[ك]/g, 'ک')
					.replace(/[\u200c\s]+/g, ' ')
					.replace(/[\/\\_.,;:()\[\]{}\-–—]+/g, ' ')
					.replace(/\s+/g, ' ')
					.trim()
					.toLowerCase();
			}

			function initPurchaseSupplierSearch() {
				if (!supplierSelectEl || !window.jQuery || !window.jQuery.fn?.select2) return;

				const $supplierSelect = window.jQuery(supplierSelectEl);
				const placeholder = supplierSelectEl.dataset.placeholder || 'جستجوی تأمین‌کننده / مشتری...';

				if ($supplierSelect.hasClass('select2-hidden-accessible')) {
					$supplierSelect.select2('destroy');
				}

				$supplierSelect.select2({
					width: '100%',
					dir: 'rtl',
					placeholder,
					allowClear: true,
					minimumInputLength: 1,
					language: {
						inputTooShort: () => 'برای نمایش نتیجه، نام یا موبایل را جستجو کنید.',
						noResults: () => 'نتیجه‌ای یافت نشد.',
						searching: () => 'در حال جستجو...'
					},
					matcher: function (params, data) {
						const term = normalizePurchaseSearchText(params.term || '');
						if (term === '') return null;
						const text = normalizePurchaseSearchText(data.text || '');
						const tokens = term.split(' ').filter(Boolean);
						return tokens.every(token => text.includes(token)) ? data : null;
					}
				});
			}

			function debounce(fn, delay = 200) {
				let timer = null;
				return function (...args) {
					clearTimeout(timer);
					timer = setTimeout(() => fn.apply(this, args), delay);
				};
			}

			function parseNumericInput(value) {
				const normalized = normalizeDigits(value).replace(/[^\d]/g, '');
				return Number(normalized || 0);
			}

			function formatNumericInput(value) {
				if (String(value ?? '').trim() === '') return '';
				return parseNumericInput(value).toLocaleString('en-US');
			}

			function formatFa(value) {
				return Number(value || 0).toLocaleString('fa-IR');
			}

			function formatMoney(value) {
				return `${formatFa(value)} ریال`;
			}

			function currentSalePriceMessage(value) {
				const price = Number(value || 0);
				return price > 0 ? `قیمت فروش فعلی: ${formatMoney(price)}` : 'قیمت فروش فعلی موجود نیست';
			}

			function calculateDiscount(base, type, value) {
				const v = Number(value || 0);
				if (!type || v <= 0 || base <= 0) return 0;
				if (type === 'percent') return Math.floor(base * Math.min(v, 100) / 100);
				return Math.min(v, base);
			}

			function productById(productId) {
				return products.find((p) => String(p.id) === String(productId)) || null;
			}

			function categoryName(categoryId) {
				return categories.find((c) => String(c.id) === String(categoryId))?.name || 'بدون دسته';
			}

			function variantLabel(variant) {
				const parts = [];
				if (variant.model_name) parts.push(variant.model_name);
				if (variant.variety_name && variant.variety_name !== '—') parts.push(variant.variety_name);
				return parts.join(' / ') || variant.title || variant.name || 'تنوع عمومی';
			}

			function normalizeVariant(variant, productId) {
				return {
					...variant,
					id: Number(variant.id || 0),
					product_id: Number(variant.product_id || productId || 0),
					name: variant.name || variant.variant_name || variant.title || '',
					code: variant.code || variant.variant_code || variant.sku || variant.barcode || '',
					stock: Number(variant.central_stock ?? variant.stock ?? 0),
					reserved: Number(variant.reserved || 0),
					barcode: variant.barcode || '',
					buy_price: Number(variant.buy_price || 0),
					sell_price: Number(variant.sell_price || variant.price || 0),
					color_name: variant.color_name || variant.color?.name || '',
					color_code: variant.color_code || variant.color?.code || '',
				};
			}

			function realVariantsForProduct(product, incomingVariants = null) {
				const rows = Array.isArray(incomingVariants) ? incomingVariants : (product.variants || []);
				const seen = new Set();

				return rows
					.map((variant) => normalizeVariant(variant, product.id))
					.filter((variant) => String(variant.product_id) === String(product.id) && variant.id > 0)
					.filter((variant) => {
						if (seen.has(variant.id)) return false;
						seen.add(variant.id);
						return true;
					});
			}

			async function fetchRealProductVariants(product) {
				const url = variantsUrlTemplate.replace('__PRODUCT__', encodeURIComponent(product.id));
                const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),10000);
                let response;
                try{response=await fetch(url, { headers: { 'Accept': 'application/json' },signal:controller.signal });}
                finally{clearTimeout(timer);}

				if (!response.ok) {
					throw new Error('خطا در دریافت تنوع‌های واقعی محصول.');
				}

				const payload = await response.json();
				product.variants = realVariantsForProduct(product, payload.variants || []);
                product.prices = payload.product_prices || {};
                product.draftOffline=false;
				return product.variants;
			}

			function filteredProducts() {
				const categoryId = quickCategoryEl.value || '';
				const term = normalizeDigits(quickSearchEl.value).trim().toLowerCase();

				return products.filter((product) => {
					if (categoryId && String(product.category_id) !== String(categoryId)) return false;
					if (!term) return true;

					const haystack = [product.name, product.code, product.short_barcode, product.sku]
						.concat((product.variants || []).flatMap((variant) => [variant.name, variant.code]))
						.map((v) => normalizeDigits(v).toLowerCase())
						.join(' ');

					return haystack.includes(term);
				});
			}

			function renderProductOptions() {
				const list = filteredProducts().slice(0, 200);
				if (!list.length) {
					quickProductEl.innerHTML = '<option value="">محصولی پیدا نشد</option>';
					return;
				}

				quickProductEl.innerHTML = '<option value="">انتخاب محصول...</option>' + list.map((product) => {
					const code = product.code || product.sku || '-';
					return `<option value="${product.id}">${escapeHtml(product.name)} (${escapeHtml(code)})</option>`;
				}).join('');
			}


			function variantSearchText(product, variant) {
				return [
					product.name,
					product.code,
					product.short_barcode,
					product.sku,
					variantLabel(variant),
					variant.name,
					variant.title,
					variant.model_name,
					variant.model_code,
					variant.variety_name,
					variant.variety_code,
					variant.design_title,
					variant.color_name,
					variant.color_code,
					variant.code,
					variant.variant_code,
					variant.sku,
					variant.barcode,
				].filter(Boolean).join(' ');
			}

			function rowHasQty(row) {
				return parseNumericInput(row.querySelector('[data-qty]')?.value || 0) > 0;
			}

			function purchaseSearchTokens(value) {
				return normalizePurchaseSearchText(value).split(' ').filter(Boolean);
			}

			function prepareVariantRowSearch(row) {
				if (!row.dataset.normalizedSearch) {
					row.dataset.normalizedSearch = normalizePurchaseSearchText([
						row.dataset.search || '',
						row.textContent || '',
					].join(' '));
				}

				return row.dataset.normalizedSearch;
			}

			function rowMatchesPurchaseSearch(row, tokens) {
				if (!tokens.length) return true;

				const haystack = prepareVariantRowSearch(row);
				return tokens.every((token) => haystack.includes(token));
			}

			function updateVariantFilter(card) {
				const searchEl = card.querySelector('[data-variant-search]');
				const onlyQtyEl = card.querySelector('[data-only-qty]');
				const rows = Array.from(card.querySelectorAll('[data-variant-row]'));
				const tokens = purchaseSearchTokens(searchEl?.value || '');
				const onlyQty = Boolean(onlyQtyEl?.checked);
				let visibleCount = 0;
				let qtyCount = 0;

				rows.forEach((row) => {
					const hasQty = rowHasQty(row);
					if (hasQty) qtyCount++;
					row.classList.toggle('has-qty', hasQty);

					const matchesSearch = rowMatchesPurchaseSearch(row, tokens);
					const matchesQty = !onlyQty || hasQty;
					const shouldShow = matchesSearch && matchesQty;
					row.classList.toggle('d-none', !shouldShow);
					if (shouldShow) visibleCount++;
				});

				const counterEl = card.querySelector('[data-variant-filter-counter]');
				if (counterEl) {
					counterEl.textContent = `${formatFa(rows.length)} تنوع | ${formatFa(visibleCount)} مورد نمایش داده شده | ${formatFa(qtyCount)} ردیف دارای تعداد`;
				}

				card.querySelector('[data-variant-empty]')?.classList.toggle('d-none', visibleCount > 0 || rows.length === 0);
			}

			function updateAllVariantFilters() {
				productCardsEl.querySelectorAll('[data-product-card]').forEach(updateVariantFilter);
			}

			function rowPriceOverridden(row) {
				return row.dataset.buyOverridden === '1' || row.dataset.sellOverridden === '1';
			}

			function updatePriceBadge(row) {
				const badge = row.querySelector('[data-price-badge]');
				if (badge) badge.classList.toggle('d-none', !rowPriceOverridden(row));
			}

			function cardDefaults(card) {
				return {
					buy: card.querySelector('[data-product-buy]')?.value || '',
					sell: card.querySelector('[data-product-sell]')?.value || '',
				};
			}

			function applyDefaultsToCard(card, force = false, includeActiveRows = false, fields = ['buy', 'sell']) {
				const defaults = cardDefaults(card);
				card.querySelectorAll('[data-variant-row]').forEach((row) => {
					const buyEl = row.querySelector('[data-buy]');
					const sellEl = row.querySelector('[data-sell]');
					const isActiveRow = parseNumericInput(row.querySelector('[data-qty]')?.value || 0) > 0;
					const shouldApplyToActiveRow = includeActiveRows && isActiveRow;

					if (fields.includes('buy') && (force || shouldApplyToActiveRow || row.dataset.buyOverridden !== '1')) {
						buyEl.value = defaults.buy === '' && card.dataset.buyMixed==='1' && row.dataset.originalPurchaseBuyPrice!==undefined ? formatNumericInput(row.dataset.originalPurchaseBuyPrice) : (defaults.buy === '' ? '' : formatNumericInput(defaults.buy));
						row.dataset.buyOverridden = (force || shouldApplyToActiveRow) ? '0' : (row.dataset.buyOverridden || '0');
					}

					if (fields.includes('sell') && (force || shouldApplyToActiveRow || row.dataset.sellOverridden !== '1')) {
						sellEl.value = defaults.sell === '' && card.dataset.sellMixed==='1' && row.dataset.originalPurchaseSellPrice!==undefined ? formatNumericInput(row.dataset.originalPurchaseSellPrice) : (defaults.sell === '' ? '' : formatNumericInput(defaults.sell));
						row.dataset.sellOverridden = (force || shouldApplyToActiveRow) ? '0' : (row.dataset.sellOverridden || '0');
					}

					updatePriceBadge(row);
				});
				recalc();
			}

			function productCardTemplate(product) {
				const variants = realVariantsForProduct(product);
				const variantsRows = variants.length ? variants.map((variant) => `
            <tr class="purchase-variant-row ${variant.purchase_blocked ? 'table-secondary' : ''}" data-variant-row data-variant-id="${variant.id}" ${variant.purchase_blocked ? 'data-purchase-blocked="1"' : ''} title="${escapeHtml(variant.purchase_blocked_message || '')}" data-buy-overridden="0" data-sell-overridden="0" data-search="${escapeHtml(variantSearchText(product, variant))}" data-normalized-search="${escapeHtml(normalizePurchaseSearchText(variantSearchText(product, variant)))}">
                <td>
                    <div class="variant-title">${escapeHtml(variantLabel(variant))}</div>
                    <div class="small text-muted">${escapeHtml(variant.name || '')}</div>
                    ${variant.purchase_blocked ? `<span class="badge text-bg-secondary mt-1">${escapeHtml(variant.purchase_blocked_label || 'تنوع قدیمی — غیرقابل خرید')}</span>` : ''}
                    ${variant.is_base_redirect ? '<span class="badge text-bg-success mt-1">تنوع پایه — برای خرید</span>' : ''}
                </td>
                <td class="mono small">${escapeHtml(variant.code || '—')}</td>
                <td>${formatFa(variant.stock || 0)}</td>
                <td><input type="number" min="0" class="form-control form-control-sm qty-input" data-qty value="" ${variant.purchase_blocked ? 'disabled' : ''}></td>
                <td class="purchase-price-cell"><input type="hidden" data-buy value="" ${variant.purchase_blocked ? 'disabled' : ''}></td>
                <td class="purchase-price-cell"><input type="hidden" data-sell value="" ${variant.purchase_blocked ? 'disabled' : ''}></td>
                <td class="purchase-price-cell"><span class="current-sale-price ${Number(variant.sell_price || 0) > 0 ? '' : 'missing'}" data-current-sale-price data-sale-price="${Number(variant.sell_price || 0)}">${escapeHtml(currentSalePriceMessage(variant.sell_price))}</span></td>
                <td class="purchase-price-cell"><span class="badge text-bg-warning d-none" data-price-badge>قیمت اختصاصی</span></td>
                <td><button type="button" class="btn btn-sm btn-outline-secondary" data-clear-row ${variant.purchase_blocked ? 'disabled' : ''}>خالی</button></td>
            </tr>
        `).join('') : `
            <tr>
                <td colspan="5" class="text-center text-muted py-3">برای این محصول تنوعی ثبت نشده است؛ ابتدا برای محصول یک تنوع عمومی تعریف کنید.</td>
            </tr>
        `;

				return `
            <div class="purchase-product-card" data-product-card data-product-id="${product.id}">
                <div class="product-card-head d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <div>
                        <div class="h6 mb-1">${escapeHtml(product.name)}</div>
                        <div class="d-flex gap-2 flex-wrap">
                            <span class="product-code-chip mono">کد: ${escapeHtml(product.code || product.sku || '—')}</span>
                            <span class="product-stat-chip">${escapeHtml(categoryName(product.category_id))}</span>
                            <span class="product-stat-chip">${formatFa(variants.length)} تنوع</span>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-remove-product>حذف کل محصول</button>
                </div>

                <div class="p-3">
                    <div class="cny-product-panel mb-3" data-cny-panel hidden>
                        <label class="form-label" for="cny-buy-${product.id}">قیمت خرید کل کالا <small>یوان · یکسان برای تمام تنوع‌ها</small></label>
                        <input id="cny-buy-${product.id}" type="text" inputmode="decimal" class="form-control" data-cny-buy autocomplete="off" placeholder="مثلاً ۵ یا ۵٫۲۵" maxlength="16">
                        <div class="cny-product-quote" data-cny-quote aria-live="polite">مبلغ یوانی را وارد کنید.</div>
                    </div>
                    <div class="price-panel mb-3" data-domestic-price-panel>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label small mb-1" for="product-buy-${product.id}">قیمت خرید کالا <small>ریال</small></label>
                                <input type="text" inputmode="numeric" class="form-control form-control-sm formatted-number" id="product-buy-${product.id}" data-product-buy placeholder="مثلاً 200,000">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small mb-1" for="product-sell-${product.id}">قیمت فروش کالا <small>ریال</small></label>
                                <input type="text" inputmode="numeric" class="form-control form-control-sm formatted-number" id="product-sell-${product.id}" data-product-sell placeholder="مثلاً 250,000">
                            </div>
                            <div class="col-md-4 small text-muted">یک قیمت برای کل کالا؛ در جدول پایین فقط تعداد هر تنوع را وارد کنید.</div>
                        </div>
                    </div>

                    <p class="small text-warning" data-legacy-price-note hidden>قیمت‌های این سند قدیمی متفاوت‌اند. خالی‌ماندن قیمت کالا، مبلغ‌های قبلی را حفظ می‌کند؛ ورود قیمت جدید روی همهٔ ردیف‌های این کالا اعمال می‌شود.</p>
                    <div class="purchase-variant-filter mb-3" data-variant-filter>
                        <div class="row g-2 align-items-center">
                            <div class="col-md-6">
                                <input type="search" class="form-control form-control-sm" data-variant-search placeholder="جستجو در تنوع‌ها، مدل، طرح، رنگ، کد یا بارکد...">
                            </div>
                            <div class="col-md-3">
                                <label class="form-check mb-0 small">
                                    <input class="form-check-input" type="checkbox" data-only-qty>
                                    <span class="form-check-label">نمایش فقط ردیف‌های دارای تعداد</span>
                                </label>
                            </div>
                            <div class="col-md-3 text-md-end">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-clear-filter>پاک کردن فیلتر</button>
                            </div>
                        </div>
                        <div class="small text-muted mt-2" data-variant-filter-counter>${formatFa(variants.length)} تنوع | ${formatFa(variants.length)} مورد نمایش داده شده | ۰ ردیف دارای تعداد</div>
                    </div>

                    <div class="purchase-variant-empty d-none mb-2 small" data-variant-empty>موردی با این جستجو پیدا نشد.</div>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle variants-table mb-2">
                            <thead class="table-light">
                                <tr>
                                    <th>نام مدل / طرح / تنوع</th>
                                    <th>کد تنوع / بارکد</th>
                                    <th>موجودی فعلی</th>
                                    <th>تعداد خرید</th>
                                    <th class="purchase-price-cell">قیمت خرید</th>
                                    <th class="purchase-price-cell">قیمت فروش خرید</th>
                                    <th class="purchase-price-cell">قیمت فروش فعلی سیستم</th>
                                    <th class="purchase-price-cell">وضعیت قیمت</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody>${variantsRows}</tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-end gap-3 small text-muted">
                        <div>جمع تعداد این محصول: <strong data-card-qty>0</strong></div>
                        <div>جمع مبلغ خرید این محصول: <strong data-card-total>0</strong> ریال</div>
                    </div>
                </div>
            </div>
        `;
			}

			async function addProductCard(productId, initialRows = [], draftCatalog = null) {
				const product = productById(productId);
				if (!product) return null;

				const existing = productCardsEl.querySelector(`[data-product-card][data-product-id="${product.id}"]`);
				if (existing) {
					existing.scrollIntoView({ behavior: 'smooth', block: 'center' });
					existing.classList.add('border-primary');
					setTimeout(() => existing.classList.remove('border-primary'), 1200);
					alert('این محصول قبلاً به لیست خرید اضافه شده است.');
					return existing;
				}

				try {
					await fetchRealProductVariants(product);
				} catch (error) {
                    if(draftCatalog && Array.isArray(draftCatalog.variants)){
                        product.variants=realVariantsForProduct(product,draftCatalog.variants);product.prices=draftCatalog.prices||{};
                        product.draftOffline=true;
                    }else{
					    alert(error.message || 'خطا در دریافت تنوع‌های محصول.');
					    return null;
                    }
				}

				productCardsEl.insertAdjacentHTML('beforeend', productCardTemplate(product));
				const card = productCardsEl.querySelector(`[data-product-card][data-product-id="${product.id}"]`);

				if (initialRows.length) {
					const first = initialRows[0];
                    card.querySelector('[data-cny-buy]').value = first.buy_cny || '';
                    for (const field of ['buy','sell']) {
                        const prices=[...new Set(initialRows.map(row=>String(row[field+'_price']??'')))];
                        card.dataset[field+'Mixed']=IS_EDIT_PURCHASE && prices.length>1?'1':'0';
                        card.querySelector('[data-product-'+field+']').value=prices.length===1?formatNumericInput(prices[0]):'';
                    }
                    card.querySelector('[data-legacy-price-note]').hidden=card.dataset.buyMixed!=='1' && card.dataset.sellMixed!=='1';

					initialRows.forEach((item) => {
						const row = card.querySelector(`[data-variant-row][data-variant-id="${item.variant_id}"]`);
						if (!row) return;

						row.dataset.purchaseItemId = item.id || '';
                        row.dataset.lineDiscountType = item.discount_type || '';
                        row.dataset.lineDiscountValue = String(parseNumericInput(item.discount_value || 0));
						if (item.original_sell_price !== undefined && item.original_sell_price !== null && item.original_sell_price !== '') {
							row.dataset.originalPurchaseSellPrice = String(parseNumericInput(item.original_sell_price));
                            row.dataset.originalPurchaseBuyPrice = String(parseNumericInput(item.original_buy_price ?? item.buy_price));
						} else {
							delete row.dataset.originalPurchaseSellPrice;
						}
						row.querySelector('[data-qty]').value = item.quantity || '';
						row.querySelector('[data-buy]').value = formatNumericInput(item.buy_price || '');
						row.querySelector('[data-sell]').value = formatNumericInput(item.sell_price || '');
						const livePrice = Number(row.querySelector('[data-current-sale-price]')?.dataset.salePrice || 0);
						if (Number(item.sell_price || 0) !== livePrice && (!isCny() || IS_EDIT_PURCHASE)) {
							row.querySelector('[data-current-sale-price]')?.insertAdjacentHTML('afterend',
								'<div class="small text-warning mt-1">قیمت این سند با قیمت فعلی فرق دارد؛ بدون تغییر عمدی قیمت فعلی محفوظ می‌ماند.</div>');
						}
						row.dataset.buyOverridden = item.price_overridden ? '1' : '0';
						row.dataset.sellOverridden = item.price_overridden ? '1' : '0';
						updatePriceBadge(row);
					});
				}

                if (!initialRows.length) {
                    card.querySelector('[data-product-buy]').value=product.prices.buy_price===null || product.prices.buy_price===undefined?'':formatNumericInput(product.prices.buy_price);
                    card.querySelector('[data-product-sell]').value=product.prices.sell_price===null || product.prices.sell_price===undefined?'':formatNumericInput(product.prices.sell_price);
                    applyDefaultsToCard(card,true);
                }
                refreshCnyCard(card);
				updateVariantFilter(card);
				recalc();
				return card;
			}

            const domesticPrices = new WeakMap();
            function refreshCnyCard(card) {
                const enabled = isCny(), input = card.querySelector('[data-cny-buy]');
                card.querySelector('[data-cny-panel]').hidden = !enabled;
                card.querySelector('[data-domestic-price-panel]').hidden = enabled;
                input.readOnly = IS_EDIT_PURCHASE;
                input.required = enabled && [...card.querySelectorAll('[data-qty]')].some(el => Number(el.value) > 0);
                if (!enabled) {
                    if (card.classList.contains('is-cny')) {
                        domesticPrices.get(card)?.forEach(([el,value]) => { if (el.isConnected) el.value=value; });
                        card.querySelectorAll('[data-buy],[data-sell]').forEach(el => el.readOnly=false);
                    }
                    card.classList.remove('is-cny'); card.querySelectorAll('[data-current-sale-price]').forEach(el=>el.textContent=currentSalePriceMessage(el.dataset.salePrice)); input.setCustomValidity(''); return;
                }
                if (!card.classList.contains('is-cny')) domesticPrices.set(card,[...card.querySelectorAll('[data-buy],[data-sell],[data-product-buy],[data-product-sell]')].map(el=>[el,el.value]));
                card.classList.add('is-cny');card.querySelectorAll('[data-buy],[data-sell]').forEach(el => el.readOnly=true);
                card.querySelectorAll('[data-current-sale-price]').forEach(el=>el.textContent='قیمت فروش فعلی: '+(Number(el.dataset.salePrice)/10).toLocaleString('fa-IR')+' تومان');
                try {
                    if (!cnyRate) throw Error('نرخ معتبر در دسترس نیست؛ نرخ را به‌روز کنید.');
                    const quote = window.AriyaCny.quote(input.value, Number(cnyRate.rate_rial));
                    if (!IS_EDIT_PURCHASE) {
                        card.querySelector('[data-product-buy]').value=formatNumericInput(quote.buy);
                        card.querySelector('[data-product-sell]').value=formatNumericInput(quote.sale);
                        card.querySelectorAll('[data-buy]').forEach(el=>el.value=formatNumericInput(quote.buy));
                        card.querySelectorAll('[data-sell]').forEach(el=>el.value=formatNumericInput(quote.sale));
                    }
                    const price = value => (Number(value)/10).toLocaleString('fa-IR',{maximumFractionDigits:1})+' تومان';
                    card.querySelector('[data-cny-quote]').innerHTML=`<div><small>قیمت خرید</small><strong>${price(quote.buy)}</strong></div><div><small>قیمت فروش یوانی با احتیاط و سود</small><strong>${Number(quote.saleCny).toLocaleString('fa-IR',{maximumFractionDigits:6})} یوان</strong></div><div class="cny-sale-result"><small>قیمت فروش نهایی</small><strong>${price(quote.sale)}</strong></div>`;
                    input.setCustomValidity('');
                } catch(error) {
                    input.setCustomValidity(input.required ? error.message : '');
                    card.querySelector('[data-cny-quote]').textContent=error.message;
                    if (!IS_EDIT_PURCHASE) card.querySelectorAll('[data-buy],[data-sell],[data-product-buy],[data-product-sell]').forEach(el=>el.value='');
                }
            }
            function refreshCnyMode() {
                productCardsEl.closest('.purchase-fast-page')?.classList.toggle('is-cny-mode',isCny());
                productCardsEl.closest('.purchase-fast-page')?.classList.toggle('has-cny-pricing',!!originEl);
                const panel=document.getElementById('cny-rate-panel'); if(panel)panel.hidden=!isCny();
                productCardsEl.querySelectorAll('[data-product-card]').forEach(refreshCnyCard); recalc();
            }
            document.getElementById('purchaseCnyEnabled')?.addEventListener('change',event=>{
                originEl.value=event.target.checked?'china':'domestic'; refreshCnyMode();
            });

			function recalc() {
				let subtotal = 0;
				let activeVariantCount = 0;

				productCardsEl.querySelectorAll('[data-product-card]').forEach((card) => {
                    const anyQuantity=[...card.querySelectorAll('[data-qty]')].some(el=>Number(el.value)>0);
                    const newRows=[...card.querySelectorAll('[data-variant-row]')].some(row=>Number(row.querySelector('[data-qty]').value)>0 && !row.dataset.purchaseItemId);
                    const sellInput=card.querySelector('[data-product-sell]'),buyInput=card.querySelector('[data-product-buy]');
                    buyInput.required=sellInput.required=!isCny() && anyQuantity && (!IS_EDIT_PURCHASE || newRows);
                    sellInput.setCustomValidity(sellInput.required && parseNumericInput(sellInput.value)<=0?'قیمت فروش کالا باید بیشتر از صفر باشد.':'');
                    const p=productById(card.dataset.productId), desired=parseNumericInput(sellInput.value);
                    card.dataset.saleGroupChange=!IS_EDIT_PURCHASE && desired>0 && ((p?.variants||[]).some(v=>Number(v.sell_price)!==desired) || (!isCny() && p?.prices?.cny_enabled))?'1':'0';
					let cardQty = 0;
					let cardTotal = 0;

					card.querySelectorAll('[data-variant-row]').forEach((row) => {
						const qty = Number(row.querySelector('[data-qty]')?.value || 0);
						const buy = parseNumericInput(row.querySelector('[data-buy]')?.value || 0);
						const lineSubtotal = qty > 0 ? qty * buy : 0;
                        const lineTotal = Math.max(0, lineSubtotal - calculateDiscount(lineSubtotal, row.dataset.lineDiscountType || '', Number(row.dataset.lineDiscountValue || 0)));

						if (qty > 0) {
							activeVariantCount++;
							cardQty += qty;
							cardTotal += lineTotal;
						}
					});

					card.querySelector('[data-card-qty]').textContent = formatFa(cardQty);
					card.querySelector('[data-card-total]').textContent = formatFa(cardTotal);
					updateVariantFilter(card);
					subtotal += cardTotal;
				});

				const invoiceDiscount = calculateDiscount(
					subtotal,
					invoiceDiscountTypeEl.value,
					parseNumericInput(invoiceDiscountValueEl.value || 0)
				);
				const total = Math.max(0, subtotal - invoiceDiscount);

				summaryProductCountEl.textContent = formatFa(productCardsEl.querySelectorAll('[data-product-card]').length);
				summaryVariantCountEl.textContent = formatFa(activeVariantCount);
				subtotalEl.textContent = formatFa(subtotal);
				totalDiscountEl.textContent = formatFa(invoiceDiscount);
				totalEl.textContent = formatFa(total);
			}

			function formatInputElement(input) {
				const atEnd = input.selectionStart === input.value.length;
				input.value = formatNumericInput(input.value);
				if (atEnd) input.setSelectionRange(input.value.length, input.value.length);
			}


			function numericPayloadValue(value) {
				return String(value ?? '').trim() === '' ? '' : parseNumericInput(value);
			}

			function buildPayload() {
				const items = [];

				productCardsEl.querySelectorAll('[data-product-card]').forEach((card) => {
					const productId = card.dataset.productId;
					const product = productById(productId);
					const defaults = cardDefaults(card);

					card.querySelectorAll('[data-variant-row]').forEach((row) => {
						const qty = parseNumericInput(row.querySelector('[data-qty]')?.value || 0);
						const purchaseItemId = row.dataset.purchaseItemId || '';
						if (qty <= 0 && !purchaseItemId) return;

						items.push({
							...(purchaseItemId ? { id: purchaseItemId } : {}),
							client_key: `product-${productId}-variant-${row.dataset.variantId}`,
							product_id: productId,
                            product_price_version: draftPriceVersions.get(String(productId)) || product?.prices?.version || '',
                            ...(isCny() ? {buy_cny: window.AriyaCny.latin(card.querySelector('[data-cny-buy]').value)} : {}),
							variant_id: row.dataset.variantId,
							quantity: qty,
                            discount_type: row.dataset.lineDiscountType || null,
                            discount_value: Number(row.dataset.lineDiscountValue || 0),
							buy_price: numericPayloadValue(row.querySelector('[data-buy]')?.value || ''),
							sell_price: numericPayloadValue(row.querySelector('[data-sell]')?.value || ''),
                            expected_current_sell_price: Number(row.querySelector('[data-current-sale-price]')?.dataset.salePrice || 0),
							product_buy_price: numericPayloadValue(defaults.buy || ''),
							product_sell_price: numericPayloadValue(defaults.sell || ''),
							name: product?.name || '',
							code: product?.code || product?.sku || '',
						});
					});
				});

				payloadEl.value = JSON.stringify(items);

				return items.length;
			}

			function findVariantRow(item) {
				return Array.from(productCardsEl.querySelectorAll('[data-variant-row]'))
					.find((row) => Number(row.dataset.variantId) === Number(item.variant_id)) || null;
			}

			function getSalePriceChanges(rows, forceApproval = false) {
				return rows.flatMap((item) => {
					const row = findVariantRow(item);
					if (!row || Number(item.quantity) <= 0) return [];

					const livePrice = Number(row.querySelector('[data-current-sale-price]')?.dataset.salePrice || 0);
					const original = row.dataset.originalPurchaseSellPrice;
					const hasPersistedBaseline = IS_EDIT_PURCHASE && original !== undefined && original !== '';
					const baselinePrice = hasPersistedBaseline ? Number(original) : livePrice;
					const proposedPrice = Number(item.sell_price || 0);

					const card = row.closest('[data-product-card]');
                    const groupNeedsApproval=card?.dataset?.saleGroupChange==='1';
                    if (proposedPrice <= 0 || (proposedPrice === baselinePrice && !forceApproval && !groupNeedsApproval)) return [];
					return [{
						item,
						productName: card?.querySelector('.product-card-head .h6')?.textContent?.trim() || 'کالای بدون نام',
						variantName: row.querySelector('.variant-title')?.textContent?.trim() || 'تنوع عمومی',
						variantCode: row.querySelector('.mono')?.textContent?.trim() || '—',
						originalPrice: hasPersistedBaseline ? baselinePrice : null,
						livePrice,
						proposedPrice,
						changesCatalog: proposedPrice !== livePrice || groupNeedsApproval,
					}];
				});
			}

			function salePriceChangeSignature(changes) {
				return changes
					.map((change) => [
						Number(change.item.variant_id),
						change.originalPrice === null ? 'new' : change.originalPrice,
						change.livePrice,
						change.proposedPrice,
                        isCny() ? String(change.item.buy_cny)+':'+cnyRate?.id : 'domestic',
					].join(':'))
					.sort()
					.join('|');
			}

			function renderSalePriceChangeRows(changes) {
				const body = document.getElementById('salePriceChangeRows');
                const groups=new Map();
                changes.forEach(change=>{const key=change.item.product_id??change.productName;if(!groups.has(key))groups.set(key,[]);groups.get(key).push(change);});
                body.innerHTML = [...groups.values()].map((group) => {
                    const change=group[0];
                    const priceLabel=values=>{const unique=[...new Set(values)];return unique.length===1?(unique[0]===null?'—':formatMoney(unique[0])):'قیمت‌های قبلی متفاوت';};
					const productLabel = escapeHtml(change.productName)
						+ '<div class="small text-muted">' + group.length.toLocaleString('fa-IR') + ' تنوع دارای تغییر قیمت</div>';
					const originalLabel = priceLabel(group.map(row=>row.originalPrice));
					const catalogEffect = isCny() && !IS_EDIT_PURCHASE ? '<span class="badge text-bg-primary">تأیید قیمت و فعال‌کردن به‌روزرسانی خودکار یوانی</span>' : group.some(row=>row.changesCatalog)
						? '<span class="badge text-bg-warning">درخواست تغییر قیمت جاری کالا</span>'
						: '<span class="badge text-bg-success">فقط سابقه سند اصلاح می‌شود</span>';

					return '<tr>'
						+ '<td>' + productLabel + '</td>'
						+ '<td class="text-nowrap">' + originalLabel + '</td>'
						+ '<td class="text-nowrap">' + priceLabel(group.map(row=>row.livePrice)) + '</td>'
						+ '<td class="text-nowrap fw-bold">' + priceLabel(group.map(row=>row.proposedPrice)) + '</td>'
						+ '<td>' + catalogEffect + '</td>'
						+ '</tr>';
				}).join('');
			}

			const salePriceChangeModalEl = document.getElementById('salePriceChangeModal');
			const salePriceChangeModal = window.bootstrap?.Modal
				? window.bootstrap.Modal.getOrCreateInstance(salePriceChangeModalEl)
				: null;
			const salePriceChangeReasonInput = document.getElementById('salePriceChangeReasonInput');
			const salePriceChangeReasonError = document.getElementById('salePriceChangeReasonError');
			const salePriceChangeConfirmedInput = document.getElementById('salePriceChangeConfirmed');
			const salePriceChangeReasonHidden = document.getElementById('salePriceChangeReason');
			const confirmSalePriceChangeBtn = document.getElementById('confirmSalePriceChangeBtn');
			let pendingSalePriceChangeSignature = null;

			function openSalePriceChangeModal(changes) {
				if (!salePriceChangeModal) {
					alert('پنجره تأیید قیمت در دسترس نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.');
					return false;
				}

				pendingSalePriceChangeSignature = salePriceChangeSignature(changes);
				renderSalePriceChangeRows(changes);
				salePriceChangeReasonInput.value = '';
				salePriceChangeReasonError.classList.add('d-none');
				salePriceChangeReasonInput.classList.remove('is-invalid');
				salePriceChangeModal.show();
				return true;
			}

			confirmSalePriceChangeBtn.addEventListener('click', function () {
				const reason = salePriceChangeReasonInput.value.trim();
				if (!reason) {
					salePriceChangeReasonInput.classList.add('is-invalid');
					salePriceChangeReasonError.classList.remove('d-none');
					salePriceChangeReasonInput.focus();
					return;
				}

				// Rebuild the payload and re-check the exact changes shown in the modal.
				buildPayload();
				const latestRows = JSON.parse(payloadEl.value || '[]');
				const latestChanges = getSalePriceChanges(latestRows, isCny() && !IS_EDIT_PURCHASE);
				const latestSignature = salePriceChangeSignature(latestChanges);

				if (latestSignature !== pendingSalePriceChangeSignature) {
					if (!latestChanges.length) {
						salePriceChangeConfirmedInput.value = '0';
						salePriceChangeReasonHidden.value = '';
						delete purchaseForm.dataset.approvedSalePriceSignature;
						salePriceChangeModal.hide();
						purchaseForm.requestSubmit();
						return;
					}

					openSalePriceChangeModal(latestChanges);
					return;
				}

				salePriceChangeConfirmedInput.value = '1';
				salePriceChangeReasonHidden.value = reason.slice(0, 500);
				purchaseForm.dataset.approvedSalePriceSignature = latestSignature;
				salePriceChangeModal.hide();
				purchaseForm.requestSubmit();
			});

			quickCategoryEl.addEventListener('change', renderProductOptions);
			quickSearchEl.addEventListener('input', renderProductOptions);

			const debouncedVariantFilter = debounce((card) => updateVariantFilter(card), 200);

			addProductBtn.addEventListener('click', () => {
				if (!quickProductEl.value) return;
				addProductCard(quickProductEl.value, []);
			});

			productCardsEl.addEventListener('input', (event) => {
				const target = event.target;
				const card = target.closest('[data-product-card]');
				if (!card) return;

                if (isCny() && target.matches('[data-cny-buy],[data-qty]')) { refreshCnyCard(card); recalc(); return; }
				if (target.classList.contains('formatted-number')) {
					formatInputElement(target);
				}

				if (target.matches('[data-variant-search]')) {
					if (normalizePurchaseSearchText(target.value) === '') {
						updateVariantFilter(card);
					} else {
						debouncedVariantFilter(card);
					}
					return;
				}

				if (target.matches('[data-only-qty]')) {
					updateVariantFilter(card);
					return;
				}

				if (target.matches('[data-product-buy], [data-product-sell]')) {
					applyDefaultsToCard(card, true, true, [target.matches('[data-product-buy]') ? 'buy' : 'sell']);
					return;
				}

				if (target.matches('[data-buy], [data-sell]')) {
					const row = target.closest('[data-variant-row]');
					if (row) {
						if (target.matches('[data-buy]')) row.dataset.buyOverridden = '1';
						if (target.matches('[data-sell]')) row.dataset.sellOverridden = '1';
						updatePriceBadge(row);
					}
				}

				recalc();
			});

			productCardsEl.addEventListener('search', (event) => {
				const target = event.target;
				const card = target.closest('[data-product-card]');
				if (card && target.matches('[data-variant-search]')) {
					updateVariantFilter(card);
				}
			});

			productCardsEl.addEventListener('change', (event) => {
				const target = event.target;
				const card = target.closest('[data-product-card]');
				if (card && target.matches('[data-only-qty]')) {
					updateVariantFilter(card);
				}
			});

			productCardsEl.addEventListener('click', (event) => {
				const card = event.target.closest('[data-product-card]');
				if (!card) return;

				if (event.target.matches('[data-clear-filter]')) {
					const searchEl = card.querySelector('[data-variant-search]');
					const onlyQtyEl = card.querySelector('[data-only-qty]');
					if (searchEl) searchEl.value = '';
					if (onlyQtyEl) onlyQtyEl.checked = false;
					updateVariantFilter(card);
					searchEl?.focus();
					return;
				}

				if (event.target.matches('[data-remove-product]')) {
					card.remove();
					recalc();
					return;
				}

				if (event.target.matches('[data-clear-row]')) {
					const row = event.target.closest('[data-variant-row]');
					if (!row) return;
					row.querySelector('[data-qty]').value = '';
					updateVariantFilter(card);
					recalc();
				}
			});

			productCardsEl.addEventListener('keydown', (event) => {
				const card = event.target.closest('[data-product-card]');
				if (!card || event.key !== 'Enter') return;

				if (event.target.matches('[data-variant-search]')) {
					event.preventDefault();
					updateVariantFilter(card);
					const visibleRows = Array.from(card.querySelectorAll('[data-variant-row]')).filter((row) => !row.classList.contains('d-none'));
					if (visibleRows.length === 1) {
						const qtyEl = visibleRows[0].querySelector('[data-qty]');
						qtyEl?.focus();
						qtyEl?.select();
					}
				}

				if (event.target.matches('[data-qty]')) {
					event.preventDefault();
					const searchEl = card.querySelector('[data-variant-search]');
					searchEl?.focus();
					searchEl?.select();
				}
			});

			invoiceDiscountTypeEl.addEventListener('change', recalc);
			invoiceDiscountValueEl.addEventListener('input', () => {
				formatInputElement(invoiceDiscountValueEl);
				recalc();
			});

			purchaseForm.addEventListener('submit', (event) => {
                if(event.defaultPrevented)return;
                if(!IS_EDIT_PURCHASE && [...productCardsEl.querySelectorAll('[data-product-card]')].some(card=>{
                    const product=productById(card.dataset.productId),saved=draftPriceVersions.get(String(product.id));
                    return product.draftOffline || (saved && saved!==product.prices?.version);
                })){
                    event.preventDefault();document.getElementById('purchaseDraftPriceNotice').hidden=false;
                    document.getElementById('purchaseDraftReviewPrices').focus();return;
                }
				if (purchaseForm.dataset.submitting === '1') {
					event.preventDefault();
					return;
				}

				invoiceDiscountValueEl.value = String(invoiceDiscountValueEl.value || '').trim() === '' ? '' : parseNumericInput(invoiceDiscountValueEl.value);
                if (isCny()) { productCardsEl.querySelectorAll('[data-product-card]').forEach(refreshCnyCard); if(!purchaseForm.reportValidity()){event.preventDefault();return;} }
				const count = buildPayload();
				if (count === 0) {
					event.preventDefault();
					alert('حداقل یک تنوع را برای خرید انتخاب کنید.');
					return;
				}

				const rows = JSON.parse(payloadEl.value || '[]');
				const saleChanges = getSalePriceChanges(rows, isCny() && !IS_EDIT_PURCHASE);
				if (saleChanges.length) {
					if (!CAN_CHANGE_SALE_PRICE) {
						event.preventDefault();
						alert('برای تغییر قیمت فروش، مجوز اعمال تغییر قیمت کالا لازم است. سایر اطلاعات سند را بدون تغییر قیمت فروش ذخیره کنید.');
						return;
					}

					const currentSignature = salePriceChangeSignature(saleChanges);
					const approvedSignature = purchaseForm.dataset.approvedSalePriceSignature || '';
					const hasExplicitApproval = salePriceChangeConfirmedInput.value === '1'
						&& salePriceChangeReasonHidden.value.trim() !== ''
						&& approvedSignature === currentSignature;

					if (!hasExplicitApproval) {
						event.preventDefault();
						salePriceChangeConfirmedInput.value = '0';
						salePriceChangeReasonHidden.value = '';
						delete purchaseForm.dataset.approvedSalePriceSignature;
						openSalePriceChangeModal(saleChanges);
						return;
					}
				} else {
					salePriceChangeConfirmedInput.value = '0';
					salePriceChangeReasonHidden.value = '';
					delete purchaseForm.dataset.approvedSalePriceSignature;
				}

				purchaseForm.dataset.submitting = '1';
				purchaseForm.querySelectorAll('button[type="submit"]').forEach((button) => {
					button.disabled = true;
					button.dataset.originalText = button.textContent;
					button.textContent = 'در حال ثبت...';
				});
			});

            function capturePurchaseDraft(){
                const fields={};
                for(const name of ['supplier_id','note','purchase_origin','invoice_discount_type','invoice_discount_value'])fields[name]=purchaseForm.elements.namedItem(name)?.value||'';
                return {version:1,fields,cards:[...productCardsEl.querySelectorAll('[data-product-card]')].map(card=>{
                    const product=productById(card.dataset.productId);
                    return {product_id:product.id,price_version:draftPriceVersions.get(String(product.id))||product.prices?.version||'',buy:card.querySelector('[data-product-buy]').value,sell:card.querySelector('[data-product-sell]').value,buy_cny:card.querySelector('[data-cny-buy]').value,
                        catalog:{variants:product.variants,prices:product.prices},
                        items:[...card.querySelectorAll('[data-variant-row]')].map(row=>({product_id:product.id,variant_id:Number(row.dataset.variantId),quantity:row.querySelector('[data-qty]').value,buy_price:numericPayloadValue(row.querySelector('[data-buy]').value),sell_price:numericPayloadValue(row.querySelector('[data-sell]').value),discount_type:row.dataset.lineDiscountType||null,discount_value:Number(row.dataset.lineDiscountValue||0),price_overridden:rowPriceOverridden(row)}))};
                })};
            }
            async function restorePurchaseDraft(payload){
                if(!payload||payload.version!==1||!Array.isArray(payload.cards))throw new Error('ساختار پیش‌نویس معتبر نیست.');
                const fields=payload.fields||{};
                if(fields.purchase_origin==='china'&&!originEl)throw new Error('قیمت‌گذاری یوانی اکنون در دسترس نیست.');
                if(fields.supplier_id && ![...supplierSelectEl.options].some(option=>option.value===fields.supplier_id))throw new Error('تأمین‌کنندهٔ قبلی در دسترس نیست.');
                productCardsEl.replaceChildren();draftPriceVersions.clear();
                for(const name of ['supplier_id','note','invoice_discount_type','invoice_discount_value']){
                    const el=purchaseForm.elements.namedItem(name);if(el)el.value=fields[name]||'';
                }
                if(originEl){originEl.value=fields.purchase_origin||'domestic';const checkbox=document.getElementById('purchaseCnyEnabled');if(checkbox)checkbox.checked=isCny();}
                if(window.jQuery)supplierSelectEl && window.jQuery(supplierSelectEl).trigger('change.select2');
                for(const saved of payload.cards){
                    const card=await addProductCard(saved.product_id,saved.items||[],saved.catalog);
                    if(!card)throw new Error('کالای '+saved.product_id+' قابل بازیابی نیست.');
                    for(const row of saved.items||[])if(!card.querySelector(`[data-variant-row][data-variant-id="${Number(row.variant_id)}"]`))throw new Error('یک تنوع حذف یا تغییر کرده است؛ بازیابی متوقف شد و اصل اطلاعات محفوظ ماند.');
                    card.querySelector('[data-product-buy]').value=saved.buy??'';
                    card.querySelector('[data-product-sell]').value=saved.sell??'';
                    card.querySelector('[data-cny-buy]').value=saved.buy_cny??'';
                    draftPriceVersions.set(String(saved.product_id),saved.price_version||'');
                    refreshCnyCard(card);
                }
                refreshCnyMode();recalc();
                document.getElementById('purchaseDraftPriceNotice').hidden=![...productCardsEl.querySelectorAll('[data-product-card]')].some(card=>{
                    const p=productById(card.dataset.productId);return p.draftOffline||draftPriceVersions.get(String(p.id))!==p.prices?.version;
                });
            }
            if(!IS_EDIT_PURCHASE)document.getElementById('purchaseDraftReviewPrices').addEventListener('click',async()=>{
                const lines=[];
                try{
                    for(const card of productCardsEl.querySelectorAll('[data-product-card]')){
                        const product=productById(card.dataset.productId);await fetchRealProductVariants(product);
                        for(const row of card.querySelectorAll('[data-variant-row]')){
                            const current=product.variants.find(v=>Number(v.id)===Number(row.dataset.variantId));
                            if(!current && Number(row.querySelector('[data-qty]').value)>0)throw new Error('یکی از تنوع‌های انتخاب‌شده دیگر در دسترس نیست.');
                            if(current){const label=row.querySelector('[data-current-sale-price]');label.dataset.salePrice=current.sell_price;label.textContent=currentSalePriceMessage(current.sell_price);}
                        }
                        lines.push(product.name+' — خرید روز: '+formatFa(product.prices.buy_price||0)+' ریال؛ فروش روز: '+formatFa(product.prices.sell_price||0)+' ریال');
                    }
                    if(!window.confirm(lines.join('\n')+'\n\nقیمت‌های واردشدهٔ شما حفظ می‌شود. قیمت روز را بررسی کردید و ادامه می‌دهید؟'))return;
                    draftPriceVersions.clear();document.getElementById('purchaseDraftPriceNotice').hidden=true;recalc();
                    purchaseForm.dispatchEvent(new Event('change',{bubbles:true}));
                }catch(e){alert('بررسی کامل نشد؛ '+e.message);}
            });
			async function hydrateInitialItems() {
				if (!Array.isArray(initialItems) || !initialItems.length) return;

				const groups = new Map();
				initialItems.forEach((item) => {
					if (!groups.has(String(item.product_id))) groups.set(String(item.product_id), []);
					groups.get(String(item.product_id)).push(item);
				});

				for (const [productId, items] of groups.entries()) {
					await addProductCard(productId, items);
				}
			}

            refreshCnyMode();
			initPurchaseSupplierSearch();
			renderProductOptions();
			hydrateInitialItems().then(()=>{
                if(!IS_EDIT_PURCHASE && window.PurchaseDraftAutosave)window.PurchaseDraftAutosave.start({
                    ...DRAFT_CONFIG,form:purchaseForm,cardsElement:productCardsEl,
                    token:()=>purchaseForm.elements.namedItem('submission_token').value,
                    setToken:value=>purchaseForm.elements.namedItem('submission_token').value=value,
                    snapshot:capturePurchaseDraft,restore:restorePurchaseDraft,
                    hasContent:payload=>payload.cards.length>0||!!payload.fields.supplier_id||!!payload.fields.note||Number(payload.fields.invoice_discount_value)>0
                });
            });
			recalc();
			updateAllVariantFilters();

			const firstInvalidField = purchaseForm.querySelector('.is-invalid');
			if (firstInvalidField) {
				setTimeout(() => {
					firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
					firstInvalidField.focus({ preventScroll: true });
				}, 100);
			}
		})();
	</script>
@endsection
