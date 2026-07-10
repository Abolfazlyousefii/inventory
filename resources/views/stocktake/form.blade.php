@extends('layouts.app')

@section('content')
@php
    $isEdit = $mode === 'edit';
    $isDraft = $document?->status === 'draft';
    $action = $isEdit ? route('stock-count-documents.update', $document) : route('stock-count-documents.store');
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="page-title mb-0">{{ $isEdit ? 'ویرایش سند انبارگردانی' : 'ثبت سند انبارگردانی' }}</h4>
    <a href="{{ route('stock-count-documents.index') }}" class="btn btn-outline-secondary">بازگشت</a>
</div>

@if($isEdit && !$isDraft)
    <div class="alert alert-warning">این سند نهایی/لغو شده و قابل ویرایش نیست. برای مشاهده به صفحه نمایش سند مراجعه کنید.</div>
@endif

<form method="POST" action="{{ $action }}">
    @csrf
    @if($isEdit)
        @method('PUT')
    @endif

    <div class="card mb-3">
        <div class="card-body row g-3">
            <div class="col-md-3">
                <label class="form-label">انبار <span class="text-danger">*</span></label>
                <select name="warehouse_id" id="warehouseId" class="form-select" required @disabled($isEdit && !$isDraft)>
                    <option value="">انتخاب...</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected((string) old('warehouse_id', $document?->warehouse_id) === (string) $warehouse->id)>{{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">تاریخ سند <span class="text-danger">*</span></label>
                <input type="date" name="document_date" class="form-control" required value="{{ old('document_date', optional($document?->document_date)->format('Y-m-d') ?? now()->format('Y-m-d')) }}" @readonly($isEdit && !$isDraft)>
            </div>
            <div class="col-md-3">
                <label class="form-label">شماره سند</label>
                <input class="form-control" value="{{ $document?->document_number ?? 'پس از ذخیره ایجاد می‌شود' }}" readonly>
            </div>
            <div class="col-md-3">
                <label class="form-label">وضعیت</label>
                <input class="form-control" value="{{ $document?->status ?? 'draft' }}" readonly>
            </div>
            <div class="col-12">
                <label class="form-label">توضیحات</label>
                <textarea name="description" class="form-control" rows="2" @readonly($isEdit && !$isDraft)>{{ old('description', $document?->description) }}</textarea>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between mb-2">
                <h6 class="mb-0">ردیف‌های کالا</h6>
                @if(!$isEdit || $isDraft)
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addRowBtn">افزودن ردیف</button>
                @endif
            </div>

            <div class="table-responsive">
                <table class="table table-bordered align-middle" id="itemsTable">
                    <thead>
                    <tr>
                        <th style="min-width: 220px;">کالا</th>
                        <th style="min-width: 240px;">تنوع</th>
                        <th>موجودی سیستم</th>
                        <th>موجودی واقعی</th>
                        <th>اختلاف</th>
                        <th>توضیح ردیف</th>
                        <th>حذف</th>
                    </tr>
                    </thead>
                    <tbody>
                    @php
                        $oldItems = old('items');
                        $items = $oldItems ?? ($document?->items?->map(fn($item) => [
                            'product_id' => $item->product_id,
                            'variant_id' => $item->product_variant_id,
                            'system_quantity' => $item->system_quantity,
                            'actual_quantity' => $item->actual_quantity,
                            'description' => $item->description,
                        ])->toArray() ?? []);
                    @endphp
                    @forelse($items as $index => $item)
                        <tr>
                            <td>
                                <select name="items[{{ $index }}][product_id]" class="form-select product-select" required @disabled($isEdit && !$isDraft)>
                                    <option value="">انتخاب...</option>
                                    @foreach($products as $product)
                                        <option value="{{ $product->id }}" @selected((string) ($item['product_id'] ?? '') === (string) $product->id)>
                                            {{ $product->name }} [{{ $product->sku }}]
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <select name="items[{{ $index }}][variant_id]" class="form-select variant-select" required @disabled($isEdit && !$isDraft)>
                                    <option value="">انتخاب...</option>
                                    @foreach($variants as $variant)
                                        <option
                                            value="{{ $variant->id }}"
                                            data-product-id="{{ $variant->product_id }}"
                                            @selected((string) ($item['variant_id'] ?? '') === (string) $variant->id)
                                        >
                                            {{ $variant->variant_name ?: 'تنوع بدون نام' }}
                                            @if($variant->variant_code)
                                                [{{ $variant->variant_code }}]
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <input class="form-control system-quantity" type="number" name="items[{{ $index }}][system_quantity]" value="{{ $item['system_quantity'] ?? 0 }}" readonly>
                            </td>
                            <td>
                                <input class="form-control actual-quantity" type="number" min="0" name="items[{{ $index }}][actual_quantity]" value="{{ $item['actual_quantity'] ?? 0 }}" required @readonly($isEdit && !$isDraft)>
                            </td>
                            <td><span class="difference-badge badge text-bg-light">0</span></td>
                            <td><input class="form-control" name="items[{{ $index }}][description]" value="{{ $item['description'] ?? '' }}" @readonly($isEdit && !$isDraft)></td>
                            <td>
                                @if(!$isEdit || $isDraft)
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-row">×</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if(!$isEdit || $isDraft)
                <button class="btn btn-primary">ذخیره سند</button>
            @endif
            @if($isEdit)
                <a class="btn btn-outline-dark" href="{{ route('stock-count-documents.view', $document) }}">نمایش سند</a>
            @endif
        </div>
    </div>
</form>

<template id="rowTemplate">
    <tr>
        <td>
            <select class="form-select product-select" required>
                <option value="">انتخاب...</option>
                @foreach($products as $product)
                    <option value="{{ $product->id }}">{{ $product->name }} [{{ $product->sku }}]</option>
                @endforeach
            </select>
        </td>
        <td>
            <select class="form-select variant-select" required>
                <option value="">انتخاب...</option>
                @foreach($variants as $variant)
                    <option value="{{ $variant->id }}" data-product-id="{{ $variant->product_id }}">
                        {{ $variant->variant_name ?: 'تنوع بدون نام' }}
                        @if($variant->variant_code)
                            [{{ $variant->variant_code }}]
                        @endif
                    </option>
                @endforeach
            </select>
        </td>
        <td><input class="form-control system-quantity" type="number" readonly value="0"></td>
        <td><input class="form-control actual-quantity" type="number" min="0" required value="0"></td>
        <td><span class="difference-badge badge text-bg-light">0</span></td>
        <td><input class="form-control" value=""></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger remove-row">×</button></td>
    </tr>
</template>

@if(!$isEdit || $isDraft)
<script>
(function(){
    const tableBody = document.querySelector('#itemsTable tbody');
    const rowTemplate = document.getElementById('rowTemplate');
    const addRowBtn = document.getElementById('addRowBtn');
    const warehouseSelect = document.getElementById('warehouseId');

    function reindexRows(){
        [...tableBody.querySelectorAll('tr')].forEach((tr, index) => {
            tr.querySelector('.product-select').setAttribute('name', `items[${index}][product_id]`);
            tr.querySelector('.variant-select').setAttribute('name', `items[${index}][variant_id]`);
            tr.querySelector('.system-quantity').setAttribute('name', `items[${index}][system_quantity]`);
            tr.querySelector('.actual-quantity').setAttribute('name', `items[${index}][actual_quantity]`);
            tr.querySelector('td:nth-child(6) input').setAttribute('name', `items[${index}][description]`);
        });
    }

    function filterVariantsByProduct(tr){
        const productId = tr.querySelector('.product-select').value;
        const variantSelect = tr.querySelector('.variant-select');
        const currentValue = variantSelect.value;

        let stillValid = false;
        [...variantSelect.options].forEach((option, index) => {
            if (index === 0) {
                option.hidden = false;
                return;
            }

            const matches = !productId || option.dataset.productId === productId;
            option.hidden = !matches;
            if (matches && option.value === currentValue) {
                stillValid = true;
            }
        });

        if (!stillValid) {
            variantSelect.value = '';
        }
    }

    async function fillSystemQuantity(tr){
        const productId = tr.querySelector('.product-select').value;
        const variantId = tr.querySelector('.variant-select')?.value || '';
        const warehouseId = warehouseSelect.value;
        if (!productId || !warehouseId) {
            tr.querySelector('.system-quantity').value = 0;
            renderDiff(tr);
            return;
        }

        const url = `{{ route('stock-count-documents.system-quantity') }}?warehouse_id=${warehouseId}&product_id=${productId}&variant_id=${variantId}`;
        const resp = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }});
        const data = await resp.json();
        tr.querySelector('.system-quantity').value = data.system_quantity ?? 0;
        renderDiff(tr);
    }

    function renderDiff(tr){
        const system = Number(tr.querySelector('.system-quantity').value || 0);
        const actual = Number(tr.querySelector('.actual-quantity').value || 0);
        const diff = actual - system;
        const badge = tr.querySelector('.difference-badge');
        badge.textContent = diff;
        badge.className = 'difference-badge badge ' + (diff > 0 ? 'text-bg-success' : (diff < 0 ? 'text-bg-danger' : 'text-bg-light'));
    }

    function bindRow(tr){
        tr.querySelector('.product-select').addEventListener('change', () => {
            filterVariantsByProduct(tr);
            fillSystemQuantity(tr);
        });
        tr.querySelector('.actual-quantity').addEventListener('input', () => renderDiff(tr));
        const removeBtn = tr.querySelector('.remove-row');
        if (removeBtn) {
            removeBtn.addEventListener('click', () => {
                tr.remove();
                reindexRows();
            });
        }
        filterVariantsByProduct(tr);
        renderDiff(tr);
    }

    addRowBtn?.addEventListener('click', () => {
        const tr = rowTemplate.content.firstElementChild.cloneNode(true);
        tableBody.appendChild(tr);
        bindRow(tr);
        reindexRows();
    });

    warehouseSelect?.addEventListener('change', () => {
        tableBody.querySelectorAll('tr').forEach((tr) => fillSystemQuantity(tr));
    });

    tableBody.querySelectorAll('tr').forEach(bindRow);
    reindexRows();
})();
</script>
@endif
@endsection
