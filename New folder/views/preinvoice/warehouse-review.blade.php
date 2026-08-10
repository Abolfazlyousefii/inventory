@extends('layouts.app')

@php
    use Morilog\Jalali\Jalalian;

    $statusLabels = \App\Models\PreinvoiceOrder::statusLabels();

    $centralWarehouseId = \App\Services\WarehouseStockService::centralWarehouseId();

    $productsData = $products->map(function ($product) use ($centralWarehouseId) {
        return [
            'id' => (int) $product->id,
            'name' => $product->name,
            'stock' => (int) \App\Models\WarehouseStock::query()
                ->where('warehouse_id', $centralWarehouseId)
                ->where('product_id', $product->id)
                ->value('quantity'),
            'variants' => $product->variants->map(function ($variant) {
                return [
                    'id' => (int) $variant->id,
                    'name' => $variant->variant_name,
                    'stock' => max(0, (int) $variant->stock),
                    'price' => (int) ($variant->sell_price ?? 0),
                ];
            })->values()->toArray(),
        ];
    })->values()->toArray();

    $initialItems = $order->items->map(function ($item) {
        return [
            'id' => (int) $item->id,
            'item_id' => (int) $item->id,
            'product_id' => (int) $item->product_id,
            'variant_id' => (int) $item->variant_id,
            'quantity' => (int) $item->quantity,
            'price' => (int) $item->price,
        ];
    })->values()->toArray();

    $reviews = $order->reviews()->with('user:id,name')->latest()->get();

    $changeReasons = \App\Services\WarehouseReviewAuditService::REASONS;
@endphp

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">بررسی انبار پیش‌فاکتور</h4>
        <div class="d-flex gap-2">
            <a href="{{ route('warehouse.reviews.show', $order->uuid) }}" class="btn btn-outline-info">مشاهده سوابق این پیش‌فاکتور</a>
            <a href="{{ route('preinvoice.warehouse.index') }}" class="btn btn-outline-secondary">
                بازگشت به صف انبار
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body row g-3">
            <div class="col-md-3">
                <div class="text-muted small">شماره</div>
                <strong>{{ $order->uuid }}</strong>
            </div>

            <div class="col-md-3">
                <div class="text-muted small">تاریخ</div>
                <strong>
                    {{ \App\Support\JalaliDate::dateTime($order->display_document_date) }}
                </strong>
            </div>

            <div class="col-md-3">
                <div class="text-muted small">مشتری</div>
                <strong>{{ $order->customer_name }}</strong>
            </div>

            <div class="col-md-3">
                <div class="text-muted small">وضعیت</div>
                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                    {{ $statusLabels[$order->status] ?? $order->status }}
                </span>
            </div>
        </div>
    </div>

    <div class="card border-info-subtle shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">توضیحات پیش‌فاکتور</span>
                <span class="text-muted small">یادداشت ثبت‌کننده برای هماهنگی انبار و مالی</span>
            </div>
            <div class="text-body" style="white-space: pre-wrap;">{{ $order->description ?: 'توضیحی برای این پیش‌فاکتور ثبت نشده است.' }}</div>
        </div>
    </div>

    <form method="POST"
          action="{{ route('preinvoice.warehouse.save', $order->uuid) }}"
          id="warehouseForm"
          class="card shadow-sm border-0 mb-3">
        @csrf
        @method('PUT')
        <input type="hidden" name="_mode" id="warehouseFormMode" value="save">

        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0">اقلام پیش‌فاکتور</h6>
            <span class="text-muted small">انبار فقط می‌تواند تعداد را کمتر کند یا ردیف را حذف کند.</span>
        </div>

        <div class="card-body">
            <div class="table-responsive">
                <table class="table align-middle" id="itemsTable">
                    <thead>
                        <tr>
                            <th>کالا</th>
                            <th>تنوع</th>
                            <th>موجودی تنوع</th>
                            <th>موجودی کالای انبار مرکزی</th>
                            <th>تعداد</th>
                            <th>قیمت</th>
                            <th>دلیل تغییر/حذف</th>
                            <th>توضیح</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <div class="mb-3">
                <label class="form-label">دلیل اصلاح توسط انبار</label>
                <textarea class="form-control"
                          name="warehouse_review_note"
                          rows="3"
                          placeholder="در صورت ویرایش اقلام، دلیل را بنویسید.">{{ old('warehouse_review_note', $order->warehouse_review_note) }}</textarea>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">ذخیره تغییرات</button>
                <button class="btn btn-success" type="button" id="approveBtn">تایید و ارسال به صف مالی</button>
            </div>
        </div>
    </form>

    <form method="POST"
          action="{{ route('preinvoice.warehouse.reject', $order->uuid) }}"
          class="card border-danger shadow-sm">
        @csrf

        <div class="card-body">
            <h6 class="text-danger">رد / برگشت به ثبت‌کننده</h6>

            <textarea class="form-control mb-2"
                      name="warehouse_reject_reason"
                      rows="2"
                      placeholder="دلیل رد/برگشت را بنویسید">{{ old('warehouse_reject_reason', $order->warehouse_reject_reason) }}</textarea>

            <button type="submit"
                    class="btn btn-outline-danger"
                    onclick="return confirm('پیش‌فاکتور رد شود؟')">
                ثبت رد پیش‌فاکتور
            </button>
        </div>
    </form>

    <div class="card shadow-sm border-0 mt-3">
        <div class="card-header bg-white">
            <h6 class="mb-0">سوابق بازبینی انبار</h6>
        </div>

        <div class="card-body">
            @forelse($reviews as $review)
                <div class="border rounded p-2 mb-2">
                    <div class="small text-muted">
                        {{ $review->created_at ? Jalalian::fromDateTime($review->created_at)->format('Y/m/d H:i') : '—' }}
                        -
                        {{ $review->user?->name ?? 'سیستم' }}
                        -
                        {{ $review->action }}
                    </div>

                    @if($review->reason)
                        <div>{{ $review->reason }}</div>
                    @endif
                </div>
            @empty
                <div class="text-muted">سابقه‌ای ثبت نشده است.</div>
            @endforelse
        </div>
    </div>
</div>

<script>
    const products = @json($productsData);
    const initialItems = @json($initialItems);
    const changeReasons = @json($changeReasons);
    const removedItems = [];

    const tbody = document.querySelector('#itemsTable tbody');

    function reasonOptions(selectedValue = '') {
        return '<option value="">انتخاب دلیل...</option>' + Object.entries(changeReasons).map(([value, label]) => {
            const isSelected = String(selectedValue || '') === String(value) ? 'selected' : '';
            return `<option value="${value}" ${isSelected}>${label}</option>`;
        }).join('');
    }

    function optionHtml(items, selectedValue = null) {
        return items.map(item => {
            const isSelected = Number(selectedValue) === Number(item.id) ? 'selected' : '';
            return `<option value="${item.id}" ${isSelected}>${item.name}</option>`;
        }).join('');
    }

    function addRow(data = {}) {
        const tr = document.createElement('tr');

        tr.innerHTML = `
            <td>
                <select class="form-select product" required disabled>
                    <option value="">انتخاب...</option>
                </select>
            </td>
            <td>
                <select class="form-select variant" required disabled>
                    <option value="">انتخاب...</option>
                </select>
            </td>
            <td class="variant-stock">0</td>
            <td class="product-stock">0</td>
            <td>
                <input type="number" class="form-control qty" min="1" max="${data.quantity || 1}" value="${data.quantity || 1}" required>
            </td>
            <td>
                <input type="number" class="form-control price" min="0" value="${data.price || 0}" required readonly>
            </td>
            <td>
                <select class="form-select change-reason">${reasonOptions(data.change_reason || '')}</select>
            </td>
            <td>
                <input type="text" class="form-control change-note" maxlength="1000" value="${data.change_note || ''}" placeholder="اگر دلیل سایر است، توضیح الزامی است">
            </td>
            <td>
                <button type="button" class="btn btn-sm btn-outline-danger remove">حذف</button>
            </td>
        `;

        tbody.appendChild(tr);

        const productSelect = tr.querySelector('.product');
        const removeBtn = tr.querySelector('.remove');

        productSelect.innerHTML = '<option value="">انتخاب...</option>' + optionHtml(products, data.product_id);
        productSelect.addEventListener('change', function () {
            fillVariants(tr);
        });

        tr.dataset.originalQuantity = String(data.quantity || 1);
        tr.dataset.itemId = String(data.item_id || data.id || '');
        tr.dataset.productId = String(data.product_id || '');
        tr.dataset.variantId = String(data.variant_id || '');

        removeBtn.addEventListener('click', function () {
            const reason = tr.querySelector('.change-reason').value;
            const note = tr.querySelector('.change-note').value;

            if (!reason) {
                alert('برای حذف کالا ابتدا دلیل حذف را انتخاب کنید.');
                return;
            }

            if (reason === 'other' && !note.trim()) {
                alert('برای دلیل «سایر»، توضیح متنی الزامی است.');
                return;
            }

            removedItems.push({
                id: tr.dataset.itemId || '',
                item_id: tr.dataset.itemId || '',
                product_id: tr.dataset.productId || tr.querySelector('.product').value,
                variant_id: tr.dataset.variantId || tr.querySelector('.variant').value,
                _delete: 1,
                change_reason: reason,
                change_note: note
            });
            tr.remove();
        });

        fillVariants(tr, data.variant_id);
    }

    function fillVariants(tr, selectedVariant = null) {
        const productSelect = tr.querySelector('.product');
        const variantSelect = tr.querySelector('.variant');
        const productStockCell = tr.querySelector('.product-stock');
        const variantStockCell = tr.querySelector('.variant-stock');
        const priceInput = tr.querySelector('.price');

        const productId = Number(productSelect.value || 0);
        const product = products.find(p => Number(p.id) === productId);

        if (!product) {
            productStockCell.textContent = '0';
            variantStockCell.textContent = '0';
            variantSelect.innerHTML = '<option value="">انتخاب...</option>';
            return;
        }

        productStockCell.textContent = product.stock ?? 0;
        variantSelect.innerHTML = '<option value="">انتخاب...</option>' + optionHtml(product.variants || [], selectedVariant);

        const onVariantChange = function () {
            const variantId = Number(variantSelect.value || 0);
            const variant = (product.variants || []).find(v => Number(v.id) === variantId);

            variantStockCell.textContent = variant ? (variant.stock ?? 0) : 0;

            if (variant && (!priceInput.value || Number(priceInput.value) === 0)) {
                priceInput.value = variant.price ?? 0;
            }
        };

        variantSelect.onchange = onVariantChange;
        onVariantChange();
    }

    function attachHiddenInputs(form) {
        form.querySelectorAll('[name^="items["], [name^="removed_items["]').forEach(el => el.remove());

        [...tbody.querySelectorAll('tr')].forEach((tr, index) => {
            const itemId = tr.dataset.itemId || '';
            const productId = tr.querySelector('.product').value;
            const variantId = tr.querySelector('.variant').value;
            const quantity = tr.querySelector('.qty').value;
            const price = tr.querySelector('.price').value;
            const reason = tr.querySelector('.change-reason').value;
            const note = tr.querySelector('.change-note').value;

            const fields = {
                id: itemId,
                item_id: itemId,
                product_id: productId,
                variant_id: variantId,
                quantity: quantity,
                price: price,
                change_reason: reason,
                change_note: note
            };

            Object.entries(fields).forEach(([key, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `items[${index}][${key}]`;
                input.value = value;
                form.appendChild(input);
            });
        });

        removedItems.forEach((row, index) => {
            Object.entries(row).forEach(([key, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `removed_items[${index}][${key}]`;
                input.value = value;
                form.appendChild(input);
            });
        });
    }

    if (initialItems.length) {
        initialItems.forEach(item => addRow(item));
    }

    document.getElementById('warehouseForm').addEventListener('submit', function (event) {
        const methodInput = this.querySelector('input[name="_method"]');
        const modeInput = document.getElementById('warehouseFormMode');

        if (methodInput && modeInput && modeInput.value === 'save') {
            methodInput.value = 'PUT';
            methodInput.disabled = false;
        }

        if (!validateChangedRows()) {
            event.preventDefault();
            return;
        }

        attachHiddenInputs(this);
    });

    function validateChangedRows() {
        for (const tr of [...tbody.querySelectorAll('tr')]) {
            const oldQty = Number(tr.dataset.originalQuantity || 0);
            const newQty = Number(tr.querySelector('.qty').value || 0);
            const reason = tr.querySelector('.change-reason').value;
            const note = tr.querySelector('.change-note').value;

            if (newQty < oldQty && !reason) {
                alert('برای کاهش تعداد کالا، انتخاب دلیل الزامی است.');
                return false;
            }

            if (newQty < oldQty && reason === 'other' && !note.trim()) {
                alert('برای دلیل «سایر»، توضیح متنی الزامی است.');
                return false;
            }
        }

        return true;
    }

    document.getElementById('approveBtn').addEventListener('click', function () {
        const form = document.getElementById('warehouseForm');
        if (!validateChangedRows()) {
            return;
        }
        attachHiddenInputs(form);

        form.action = "{{ route('preinvoice.warehouse.approve', $order->uuid) }}";
        form.method = 'POST';

        const methodInput = form.querySelector('input[name="_method"]');
        const modeInput = document.getElementById('warehouseFormMode');

        if (methodInput) {
            methodInput.disabled = true;
        }

        if (modeInput) {
            modeInput.value = 'approve';
        }

        form.submit();
    });
</script>
@endsection
