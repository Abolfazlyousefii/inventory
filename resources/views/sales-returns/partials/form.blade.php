@include('sales-returns.partials.flash')
@if(!$centralWarehouse)<div class="alert alert-warning">انبار مرکزی در تنظیمات سیستم تعریف نشده است.</div>@endif
@if(!$returnWarehouse)<div class="alert alert-warning">انبار مرجوعی در تنظیمات سیستم تعریف نشده است.</div>@endif
@php
    $isEdit = isset($document);
    $oldItems = old('items', $isEdit ? $document->items->map(fn($item) => [
        'invoice_item_id'=>$item->invoice_item_id,
        'product_id'=>$item->product_id,
        'product_variant_id'=>$item->product_variant_id,
        'product_name_snapshot'=>$item->product_name_snapshot,
        'variant_name_snapshot'=>$item->variant_name_snapshot,
        'return_quantity'=>$item->return_quantity,
        'item_condition'=>$item->item_condition,
        'destination_warehouse_id'=>$item->destination_warehouse_id,
        'refund_unit_price'=>$item->refund_unit_price,
        'new_product_payload'=>$item->new_product_payload,
    ])->values()->all() : [['return_quantity'=>1,'item_condition'=>'healthy']]);
    $source = old('source_type', $isEdit ? $document->source_type : \App\Models\SalesReturnDocument::SOURCE_INTERNAL_INVOICE);
@endphp
<form method="POST" action="{{ $isEdit ? route('sales-returns.update', $document) : route('sales-returns.store') }}" id="salesReturnForm" data-preview-url="{{ route('sales-returns.preview') }}">
    @csrf
    @if($isEdit) @method('PATCH') @endif
    <div class="card mb-3"><div class="card-header">اطلاعات سند</div><div class="card-body row g-3">
        <div class="col-md-3"><label class="form-label">نوع برگشت</label><select name="source_type" id="sourceType" class="form-select"><option value="internal_invoice" @selected($source==='internal_invoice')>داخلی</option><option value="sazeh_hesab" @selected($source==='sazeh_hesab')>سازه‌حساب</option></select>@error('source_type')<div class="text-danger small">{{ $message }}</div>@enderror</div>
        <div class="col-md-3"><label class="form-label">شناسه مشتری</label><input name="customer_id" id="customerId" value="{{ old('customer_id', $document->customer_id ?? '') }}" class="form-control" placeholder="از جستجوی Ajax انتخاب کنید">@error('customer_id')<div class="text-danger small">{{ $message }}</div>@enderror</div>
        <div class="col-md-6"><label class="form-label">جستجوی مشتری</label><input id="customerSearch" class="form-control" placeholder="نام، موبایل یا کد مشتری"><div id="customerResults" class="list-group small position-absolute" style="z-index:10"></div></div>
        <div class="col-md-3 internal-field"><label class="form-label">شناسه فاکتور داخلی</label><input name="invoice_id" id="invoiceId" value="{{ old('invoice_id', $document->invoice_id ?? '') }}" class="form-control">@error('invoice_id')<div class="text-danger small">{{ $message }}</div>@enderror</div>
        <div class="col-md-3 sazeh-field"><label class="form-label">شماره فاکتور سازه‌حساب</label><input name="external_invoice_number" value="{{ old('external_invoice_number', $document->external_invoice_number ?? '') }}" class="form-control">@error('external_invoice_number')<div class="text-danger small">{{ $message }}</div>@enderror</div>
        <div class="col-md-3 sazeh-field"><label class="form-label">تاریخ فاکتور</label><input type="date" name="external_invoice_date" value="{{ old('external_invoice_date', isset($document) && $document->external_invoice_date ? $document->external_invoice_date->format('Y-m-d') : '') }}" class="form-control">@error('external_invoice_date')<div class="text-danger small">{{ $message }}</div>@enderror</div>
        <div class="col-md-3"><label class="form-label">علت</label><input name="return_reason" value="{{ old('return_reason', $document->return_reason ?? '') }}" class="form-control"></div>
        <div class="col-md-12"><label class="form-label">توضیحات</label><textarea name="description" class="form-control">{{ old('description', $document->description ?? '') }}</textarea></div>
    </div></div>

    <div class="card mb-3"><div class="card-header d-flex justify-content-between"><span>اقلام برگشتی</span><button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn">افزودن ردیف</button></div><div class="card-body">
        <div class="alert alert-info small">Draft هیچ اثر مالی یا انباری ندارد. مبلغ داخلی فقط از Snapshot فاکتور توسط Backend محاسبه می‌شود.</div>
        <div id="itemsContainer">
            @foreach($oldItems as $i => $item)
            <div class="border rounded p-3 mb-3 item-row" data-index="{{ $i }}">
                <div class="row g-2">
                    <div class="col-md-2 internal-field"><label class="form-label">invoice_item_id</label><input name="items[{{ $i }}][invoice_item_id]" value="{{ data_get($item,'invoice_item_id') }}" class="form-control">@error("items.$i.invoice_item_id")<div class="text-danger small">{{ $message }}</div>@enderror</div>
                    <div class="col-md-2 sazeh-field"><label class="form-label">product_id</label><input name="items[{{ $i }}][product_id]" value="{{ data_get($item,'product_id') }}" class="form-control"></div>
                    <div class="col-md-2 sazeh-field"><label class="form-label">variant_id</label><input name="items[{{ $i }}][product_variant_id]" value="{{ data_get($item,'product_variant_id') }}" class="form-control"></div>
                    <div class="col-md-2"><label class="form-label">تعداد</label><input type="number" min="1" name="items[{{ $i }}][return_quantity]" value="{{ data_get($item,'return_quantity',1) }}" class="form-control">@error("items.$i.return_quantity")<div class="text-danger small">{{ $message }}</div>@enderror</div>
                    <div class="col-md-2"><label class="form-label">وضعیت</label><select name="items[{{ $i }}][item_condition]" class="form-select"><option value="healthy" @selected(data_get($item,'item_condition')==='healthy')>سالم</option><option value="damaged" @selected(data_get($item,'item_condition')==='damaged')>معیوب</option></select></div>
                    <div class="col-md-2"><label class="form-label">مقصد</label><select name="items[{{ $i }}][destination_warehouse_id]" class="form-select"><option value="">پیش‌فرض Backend</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((string)data_get($item,'destination_warehouse_id')===(string)$warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></div>
                    <div class="col-md-2 sazeh-field"><label class="form-label">مبلغ بستانکاری واحد</label><input type="number" min="1" name="items[{{ $i }}][refund_unit_price]" value="{{ data_get($item,'refund_unit_price') }}" class="form-control">@error("items.$i.refund_unit_price")<div class="text-danger small">{{ $message }}</div>@enderror</div>
                    <div class="col-md-12 sazeh-field">
                        @canPermission('sales_returns.create_product')
                        <button type="button" class="btn btn-sm btn-outline-secondary new-product-toggle">کالای جدید Payload</button>
                        <div class="new-product-box mt-2 p-2 bg-light border rounded">
                            <div class="small text-muted mb-2">کالای جدید تا زمان ثبت نهایی سند در فهرست کالاها ساخته نمی‌شود.</div>
                            <div class="row g-2">
                                <div class="col-md-3"><input name="items[{{ $i }}][new_product_payload][product_name]" value="{{ data_get($item,'new_product_payload.product_name') }}" class="form-control" placeholder="نام محصول"></div>
                                <div class="col-md-3"><input name="items[{{ $i }}][new_product_payload][variant_name]" value="{{ data_get($item,'new_product_payload.variant_name') }}" class="form-control" placeholder="نام تنوع"></div>
                                <div class="col-md-2"><input name="items[{{ $i }}][new_product_payload][category_id]" value="{{ data_get($item,'new_product_payload.category_id') }}" class="form-control" placeholder="دسته‌بندی"></div>
                                <div class="col-md-2"><input name="items[{{ $i }}][new_product_payload][sku]" value="{{ data_get($item,'new_product_payload.sku') }}" class="form-control" placeholder="SKU/Barcode"></div>
                                <div class="col-md-2"><input type="number" name="items[{{ $i }}][new_product_payload][purchase_price]" value="{{ data_get($item,'new_product_payload.purchase_price') }}" class="form-control" placeholder="خرید"></div>
                                <div class="col-md-2"><input type="number" name="items[{{ $i }}][new_product_payload][sell_price]" value="{{ data_get($item,'new_product_payload.sell_price') }}" class="form-control" placeholder="فروش"></div>
                                <div class="col-md-2"><select name="items[{{ $i }}][new_product_payload][sales_enabled]" class="form-select"><option value="1">قابل فروش</option><option value="0">غیرفعال فروش</option></select></div>
                            </div>
                        </div>
                        @endcanPermission
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div></div>
    <div class="d-flex gap-2"><button class="btn btn-success">ذخیره پیش‌نویس</button><a href="{{ route('sales-returns.index') }}" class="btn btn-outline-secondary">بازگشت</a></div>
</form>
<script>
(function(){
 const source=document.getElementById('sourceType');
 function sync(){ const s=source.value; document.querySelectorAll('.internal-field').forEach(e=>e.style.display=s==='internal_invoice'?'':'none'); document.querySelectorAll('.sazeh-field').forEach(e=>e.style.display=s==='sazeh_hesab'?'':'none'); }
 source?.addEventListener('change',sync); sync();
 document.querySelectorAll('.new-product-toggle').forEach(btn=>btn.addEventListener('click',()=>btn.nextElementSibling?.classList.toggle('d-none')));
 document.getElementById('addItemBtn')?.addEventListener('click',()=>{ const c=document.getElementById('itemsContainer'); const first=c.querySelector('.item-row'); const n=c.children.length; const clone=first.cloneNode(true); clone.querySelectorAll('input,select').forEach(el=>{ el.name=el.name.replace(/items\[\d+\]/,'items['+n+']'); if(el.tagName==='INPUT') el.value=''; }); c.appendChild(clone); sync(); });
 const search=document.getElementById('customerSearch'), results=document.getElementById('customerResults'), customer=document.getElementById('customerId'); let timer;
 search?.addEventListener('input',()=>{ clearTimeout(timer); timer=setTimeout(async()=>{ if(search.value.length<2){results.innerHTML='';return;} const r=await fetch('{{ route('sales-returns.customers.search') }}?q='+encodeURIComponent(search.value)); const j=await r.json(); results.innerHTML=(j.data||[]).map(x=>`<button type="button" class="list-group-item list-group-item-action" data-id="${x.id}">${x.text} - ${x.mobile||''}</button>`).join(''); results.querySelectorAll('button').forEach(b=>b.onclick=()=>{customer.value=b.dataset.id; search.value=b.textContent; results.innerHTML='';}); },300); });
})();
</script>
