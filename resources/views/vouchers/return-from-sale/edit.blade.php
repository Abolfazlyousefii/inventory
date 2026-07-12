@extends('layouts.app')
@section('content')
@php
    $document = $document ?? null;
    $salesReturnRoutes = [
        'customersSearch' => route('vouchers.return-from-sale.customers.search'),
        'customerInvoices' => route('vouchers.return-from-sale.customers.invoices', ['customer' => '__CUSTOMER__']),
        'invoiceItems' => route('vouchers.return-from-sale.invoices.items', ['invoice' => '__INVOICE__']),
        'productsSearch' => route('vouchers.return-from-sale.products.search'),
        'productVariants' => route('vouchers.return-from-sale.products.variants', ['product' => '__PRODUCT__']),
        'preview' => route('vouchers.return-from-sale.preview'),
    ];
@endphp
<div class="container-fluid" dir="rtl" data-module="new-sales-return-create">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">ثبت برگشت از فروش</h4>
        <a class="btn btn-outline-secondary" href="{{ route('vouchers.return-from-sale.index') }}">بازگشت</a>
    </div>

    @if($errors->any())
        <div class="alert alert-danger small">لطفاً خطاهای فرم را بررسی کنید.</div>
    @endif

    <form method="POST" action="{{ $document ? route('vouchers.return-from-sale.update', $document) : route('vouchers.return-from-sale.store') }}" id="salesReturnForm">
        @csrf
        @if($document)
            @method('PATCH')
        @endif

        <div class="card mb-3">
            <div class="card-header bg-white fw-bold">اطلاعات برگشت</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">نوع برگشت</label>
                        <select class="form-select" name="source_type" id="sourceType">
                            <option value="internal_invoice">فاکتور داخلی</option>
                            <option value="sazeh_hesab">سازه‌حساب</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">مشتری</label>
                        <input class="form-control" name="customer_id" id="customerId" value="{{ old('customer_id', $document?->customer_id) }}" required>
                    </div>
                    <div class="col-md-3 internal-box">
                        <label class="form-label">فاکتور داخلی</label>
                        <input class="form-control" name="invoice_id" id="invoiceId" value="{{ old('invoice_id', $document?->invoice_id) }}">
                    </div>
                    <div class="col-md-3 sazeh-box d-none">
                        <label class="form-label">شماره فاکتور سازه‌حساب</label>
                        <input class="form-control" name="external_invoice_number" value="{{ old('external_invoice_number', $document?->external_invoice_number) }}">
                    </div>
                    <div class="col-md-3 sazeh-box d-none">
                        <label class="form-label">تاریخ فاکتور سازه‌حساب</label>
                        <input type="date" class="form-control" name="external_invoice_date" value="{{ old('external_invoice_date', $document?->external_invoice_date?->format('Y-m-d')) }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">انبار مقصد</label>
                        <select class="form-select" name="default_destination_warehouse_id" id="defaultWarehouse">
                            @foreach($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" data-type="{{ $warehouse->type }}">{{ $warehouse->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">علت برگشت</label>
                        <select class="form-select" name="return_reason">
                            @foreach($returnReasons as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold">اقلام برگشتی</span>
                <button class="btn btn-sm btn-outline-primary" type="button" id="addRow">افزودن کالا</button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="itemsTable">
                    <thead class="table-light">
                    <tr>
                        <th>منبع</th>
                        <th>دسته‌بندی</th>
                        <th>کالا</th>
                        <th>تنوع</th>
                        <th>کد یا بارکد</th>
                        <th>تعداد</th>
                        <th>وضعیت کالا</th>
                        <th>انبار مقصد</th>
                        <th>قیمت خرید</th>
                        <th>قیمت فروش</th>
                        <th>مبلغ بستانکاری</th>
                        <th>مبلغ کل</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">شماره حواله یا ارجاع</label>
                        <input class="form-control" name="reference_number" value="{{ old('reference_number', $document?->reference_number) }}">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">توضیحات</label>
                        <textarea class="form-control" name="description" rows="2">{{ old('description', $document?->description) }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary" name="action" value="draft">ثبت برگشت از فروش</button>
            <button class="btn btn-success" name="action" value="apply">ثبت و اعمال نهایی</button>
        </div>
    </form>
</div>

<script>
    window.salesReturnRoutes = @json($salesReturnRoutes);
</script>
<script>
(() => {
    const $ = selector => document.querySelector(selector);
    const tbody = $('#itemsTable tbody');
    const warehouses = @json($warehouses->map(fn($warehouse) => ['id' => $warehouse->id, 'name' => $warehouse->name, 'type' => $warehouse->type])->values());
    let idx = 0;

    function warehouseOptions() {
        return warehouses.map(warehouse => `<option value="${warehouse.id}" data-type="${warehouse.type}">${warehouse.name}</option>`).join('');
    }

    function row() {
        return `<tr>
            <td><select name="items[${idx}][item_source]" class="form-select form-select-sm"><option value="invoice_item">آیتم فاکتور</option><option value="existing_product">کالای موجود</option><option value="new_product">کالای جدید</option></select><input class="form-control form-control-sm mt-1" name="items[${idx}][invoice_item_id]" placeholder="شناسه آیتم فاکتور"></td>
            <td><select class="form-select form-select-sm" name="items[${idx}][new_product_payload][category_id]">@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></td>
            <td><input class="form-control form-control-sm" name="items[${idx}][new_product_payload][product_name]" placeholder="نام کالا"><input class="form-control form-control-sm mt-1" name="items[${idx}][product_variant_id]" placeholder="شناسه تنوع موجود"></td>
            <td><input class="form-control form-control-sm" name="items[${idx}][new_product_payload][variant_name]" placeholder="تنوع"></td>
            <td><input class="form-control form-control-sm" name="items[${idx}][new_product_payload][sku]" placeholder="SKU"><input class="form-control form-control-sm mt-1" name="items[${idx}][new_product_payload][barcode]" placeholder="Barcode"></td>
            <td><input type="number" min="0" class="form-control form-control-sm qty" name="items[${idx}][return_quantity]" value="1"></td>
            <td><select name="items[${idx}][item_condition]" class="form-select form-select-sm cond"><option value="healthy">سالم</option><option value="damaged">مرجوعی یا معیوب</option></select></td>
            <td><select name="items[${idx}][destination_warehouse_id]" class="form-select form-select-sm wh">${warehouseOptions()}</select></td>
            <td><input type="number" min="0" class="form-control form-control-sm" name="items[${idx}][new_product_payload][purchase_price]" placeholder="قیمت خرید"></td>
            <td><input type="number" min="0" class="form-control form-control-sm" name="items[${idx}][new_product_payload][sell_price]" placeholder="قیمت فروش"></td>
            <td><input type="number" min="0" class="form-control form-control-sm price" name="items[${idx}][refund_unit_price]" value="0"></td>
            <td class="line-total">0</td>
            <td><button type="button" class="btn btn-sm btn-outline-danger rm">حذف</button></td>
        </tr>`;
    }

    function refreshTotals() {
        tbody.querySelectorAll('tr').forEach(tr => {
            const quantity = Number(tr.querySelector('.qty')?.value || 0);
            const price = Number(tr.querySelector('.price')?.value || 0);
            tr.querySelector('.line-total').textContent = (quantity * price).toLocaleString('fa-IR');
        });
    }

    $('#addRow').addEventListener('click', () => {
        tbody.insertAdjacentHTML('beforeend', row());
        idx++;
        refreshTotals();
    });

    document.addEventListener('input', event => {
        if (event.target.closest('#itemsTable')) refreshTotals();
    });

    document.addEventListener('click', event => {
        if (event.target.classList.contains('rm')) {
            event.target.closest('tr').remove();
            refreshTotals();
        }
    });

    $('#sourceType').addEventListener('change', event => {
        const isSazeh = event.target.value === 'sazeh_hesab';
        document.querySelectorAll('.sazeh-box').forEach(el => el.classList.toggle('d-none', !isSazeh));
        document.querySelectorAll('.internal-box').forEach(el => el.classList.toggle('d-none', isSazeh));
    });

    $('#addRow').click();
})();
</script>
@endsection
