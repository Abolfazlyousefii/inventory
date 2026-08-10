@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">ثبت جدید غیرفعال‌سازی</h4>
    <a href="{{ route('product-deactivation-documents.index') }}" class="btn btn-outline-secondary">
        بازگشت به لیست
    </a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ route('product-deactivation-documents.store') }}">
            @csrf

            <div class="mb-3">
                <label class="form-label fw-bold">دلیل غیرفعال‌سازی</label>
                <textarea
                    name="reason_text"
                    class="form-control @error('reason_text') is-invalid @enderror"
                    rows="3"
                    placeholder="دلیل را به صورت کامل بنویسید..."
                >{{ old('reason_text') }}</textarea>

                @error('reason_text')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0">آیتم‌های سند</h6>
                <button type="button" class="btn btn-sm btn-outline-primary" id="addRowBtn">
                    + افزودن ردیف
                </button>
            </div>

            <div class="table-responsive border rounded">
                <table class="table table-sm align-middle mb-0" id="itemsTable">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width: 160px;">دسته‌بندی</th>
                            <th style="min-width: 180px;">زیر دسته‌بندی</th>
                            <th style="min-width: 220px;">کالا</th>
                            <th style="min-width: 220px;">تنوع کالا</th>
                            <th class="text-center" style="width: 80px;">حذف</th>
                        </tr>
                    </thead>
                    <tbody id="itemsTbody"></tbody>
                </table>
            </div>

            @error('items')
                <div class="text-danger small mt-2">{{ $message }}</div>
            @enderror

            <div class="mt-3">
                <button type="submit" class="btn btn-danger">ثبت سند</button>
            </div>
        </form>
    </div>
</div>

<template id="rowTemplate">
    <tr>
        <td>
            <select class="form-select form-select-sm js-category">
                <option value="">انتخاب دسته‌بندی</option>
            </select>
            <input type="hidden" class="js-category-input" name="">
        </td>
        <td>
            <select class="form-select form-select-sm js-subcategory">
                <option value="">انتخاب زیر دسته‌بندی</option>
            </select>
            <input type="hidden" class="js-subcategory-input" name="">
        </td>
        <td>
            <select class="form-select form-select-sm js-product">
                <option value="">انتخاب کالا</option>
            </select>
            <input type="hidden" class="js-product-input" name="">
        </td>
        <td>
            <select class="form-select form-select-sm js-variant">
                <option value="">بدون تنوع (غیرفعال‌سازی کل کالا)</option>
            </select>
            <input type="hidden" class="js-variant-input" name="">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger js-remove-row">×</button>
        </td>
    </tr>
</template>

@php
    $categoryPayload = collect($rootCategories ?? [])->map(function ($c) {
        return [
            'id' => (int) $c->id,
            'name' => (string) $c->name,
        ];
    })->values()->all();

    $subcategoryPayload = collect($subcategories ?? [])->map(function ($c) {
        return [
            'id' => (int) $c->id,
            'name' => (string) $c->name,
            'parent_id' => (int) ($c->parent_id ?? 0),
        ];
    })->values()->all();

    $productPayload = collect($products ?? [])->map(function ($p) {
        return [
            'id' => (int) $p->id,
            'name' => (string) $p->name,
            'root_category_id' => (int) ($p->root_category_id ?? 0),
            'subcategory_id' => (int) ($p->subcategory_id ?? 0),
            'variants' => collect($p->variants ?? [])->map(function ($v) {
                return [
                    'id' => (int) $v->id,
                    'name' => (string) $v->variant_name,
                ];
            })->values()->all(),
        ];
    })->values()->all();

    $oldItems = old('items', [
        [
            'category_id' => '',
            'subcategory_id' => '',
            'product_id' => '',
            'variant_id' => '',
        ]
    ]);
@endphp

<script>
    const categories = @json($categoryPayload);
    const subcategories = @json($subcategoryPayload);
    const products = @json($productPayload);
    const oldItems = @json($oldItems);
    const rootCategoryIds = new Set(categories.map((c) => Number(c.id || 0)));
    const categoryParentMap = new Map();
    categories.forEach((category) => {
        categoryParentMap.set(Number(category.id || 0), 0);
    });
    subcategories.forEach((category) => {
        categoryParentMap.set(Number(category.id || 0), Number(category.parent_id || 0));
    });

    const tbody = document.getElementById('itemsTbody');
    const template = document.getElementById('rowTemplate');
    const addRowBtn = document.getElementById('addRowBtn');

    function toOptions(items, placeholder, valueKey = 'id', textKey = 'name') {
        return `<option value="">${placeholder}</option>` + items.map(function (item) {
            return `<option value="${item[valueKey]}">${item[textKey]}</option>`;
        }).join('');
    }

    function updateInputNames(row, index) {
        row.querySelector('.js-category-input').name = `items[${index}][category_id]`;
        row.querySelector('.js-subcategory-input').name = `items[${index}][subcategory_id]`;
        row.querySelector('.js-product-input').name = `items[${index}][product_id]`;
        row.querySelector('.js-variant-input').name = `items[${index}][variant_id]`;
    }

    function bindRow(row, initial = {}) {
        const categoryEl = row.querySelector('.js-category');
        const subcategoryEl = row.querySelector('.js-subcategory');
        const productEl = row.querySelector('.js-product');
        const variantEl = row.querySelector('.js-variant');

        const categoryInput = row.querySelector('.js-category-input');
        const subcategoryInput = row.querySelector('.js-subcategory-input');
        const productInput = row.querySelector('.js-product-input');
        const variantInput = row.querySelector('.js-variant-input');

        categoryEl.innerHTML = toOptions(categories, 'انتخاب دسته‌بندی');

        function syncHidden() {
            categoryInput.value = categoryEl.value || '';
            subcategoryInput.value = subcategoryEl.value || '';
            productInput.value = productEl.value || '';
            variantInput.value = variantEl.value || '';
        }

        function renderSubcategories() {
            const categoryId = Number(categoryEl.value || 0);

            const items = subcategories.filter(function (s) {
                return Number(s.parent_id) === categoryId;
            });

            subcategoryEl.innerHTML = toOptions(items, 'انتخاب زیر دسته‌بندی');

            if (!items.length) {
                subcategoryEl.value = '';
            }

            renderProducts();
        }

        function renderProducts() {
            const categoryId = Number(categoryEl.value || 0);
            const subcategoryId = Number(subcategoryEl.value || 0);

            const filteredProducts = products.filter(function (p) {
                if (!categoryId) {
                    return true;
                }

                if (!subcategoryId) {
                    return Number(p.root_category_id) === categoryId;
                }

                return Number(p.subcategory_id) === subcategoryId;
            });

            productEl.innerHTML = toOptions(filteredProducts, 'انتخاب کالا');
            renderVariants();
        }

        function renderVariants() {
            const productId = Number(productEl.value || 0);

            const product = products.find(function (p) {
                return Number(p.id) === productId;
            });

            const variantOptions = (product && product.variants) ? product.variants : [];

            variantEl.innerHTML =
                `<option value="">بدون تنوع (غیرفعال‌سازی کل کالا)</option>` +
                variantOptions.map(function (v) {
                    return `<option value="${v.id}">${v.name}</option>`;
                }).join('');

            variantEl.disabled = variantOptions.length === 0;

            if (variantEl.disabled) {
                variantEl.value = '';
            }

            syncHidden();
        }

        categoryEl.addEventListener('change', function () {
            subcategoryEl.value = '';
            productEl.value = '';
            variantEl.value = '';
            renderSubcategories();
            syncHidden();
        });

        subcategoryEl.addEventListener('change', function () {
            productEl.value = '';
            variantEl.value = '';
            renderProducts();
            syncHidden();
        });

        productEl.addEventListener('change', function () {
            variantEl.value = '';
            renderVariants();
            syncHidden();
        });

        variantEl.addEventListener('change', syncHidden);

        row.querySelector('.js-remove-row').addEventListener('click', function () {
            if (tbody.querySelectorAll('tr').length <= 1) {
                return;
            }

            row.remove();
            reindexRows();
        });

        const initialCategoryId = Number(initial.category_id || 0);
        const initialSubcategoryId = Number(initial.subcategory_id || 0);

        if (initialCategoryId) {
            if (rootCategoryIds.has(initialCategoryId)) {
                categoryEl.value = String(initialCategoryId);
            } else {
                const parentId = Number(categoryParentMap.get(initialCategoryId) || 0);
                if (rootCategoryIds.has(parentId)) {
                    categoryEl.value = String(parentId);
                    if (!initialSubcategoryId) {
                        initial.subcategory_id = String(initialCategoryId);
                    }
                }
            }
        }

        renderSubcategories();

        if (initial.subcategory_id) {
            subcategoryEl.value = String(initial.subcategory_id);
        }

        renderProducts();

        if (initial.product_id) {
            productEl.value = String(initial.product_id);
        }

        renderVariants();

        if (initial.variant_id) {
            variantEl.value = String(initial.variant_id);
        }

        syncHidden();
    }

    function reindexRows() {
        tbody.querySelectorAll('tr').forEach(function (row, index) {
            updateInputNames(row, index);
        });
    }

    function addRow(initial = {}) {
        const fragment = template.content.cloneNode(true);
        const row = fragment.querySelector('tr');

        tbody.appendChild(row);
        bindRow(row, initial);
        reindexRows();
    }

    if (Array.isArray(oldItems) && oldItems.length) {
        oldItems.forEach(function (item) {
            addRow(item || {});
        });
    } else {
        addRow({});
    }

    addRowBtn.addEventListener('click', function () {
        addRow({});
    });
</script>
@endsection
