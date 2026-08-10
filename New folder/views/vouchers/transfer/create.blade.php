@extends('layouts.app')

@php
$productsJson = $products->map(fn($p) => ['id'=>$p->id,'name'=>$p->name,'code'=>$p->code ?? $p->sku,'category_id'=>$p->category_id])->values();
$variantsJson = $variants->map(fn($v) => [
    'id' => $v->id,
    'product_id' => $v->product_id,
    'name' => $v->variant_name,
    'code' => $v->variant_code,
    'model_name' => $v->model_name,
    'variety_code' => $v->variety_code,
    'stock' => (int) $v->stock,
])->values();
@endphp

@section('content')
<div class="container py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">ثبت حواله بین انبار</h4>
        <a class="btn btn-outline-secondary" href="{{ route('vouchers.section.index', 'transfer') }}">بازگشت</a>
    </div>

    <div class="card"><div class="card-body">
        <form method="POST" action="{{ route('vouchers.section.store', 'transfer') }}">
            @csrf
            <input type="hidden" name="voucher_type" value="between_warehouses">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">انبار مبدا</label><select name="from_warehouse_id" class="form-select" required><option value="">انتخاب...</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label">انبار مقصد</label><select name="to_warehouse_id" class="form-select" required><option value="">انتخاب...</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label">شماره حواله (اختیاری)</label><input name="reference" class="form-control" value="{{ old('reference') }}"></div>
                <div class="col-12"><label class="form-label">توضیحات (اختیاری)</label><input name="note" class="form-control" value="{{ old('note') }}"></div>

                <div class="col-12">
                    <table class="table" id="itemsTable"><thead><tr><th>محصول (جستجو با نام/کد)</th><th>مدل / طرح‌بندی</th><th>تنوع/طرح</th><th>موجودی تنوع</th><th>تعداد</th><th></th></tr></thead><tbody></tbody></table>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="addItemBtn">+ افزودن ردیف</button>
                </div>
                <div class="col-12"><button class="btn btn-primary">ثبت حواله بین انبار</button></div>
            </div>
        </form>
    </div></div>
</div>
<script>
const products=@json($productsJson),variants=@json($variantsJson),tbody=document.querySelector('#itemsTable tbody'),addBtn=document.getElementById('addItemBtn');
function po(s=''){return '<option value="">انتخاب محصول...</option>'+products.map(p=>`<option value="${p.id}" ${String(s)===String(p.id)?'selected':''}>${p.name} ${p.code? '('+p.code+')':''}</option>`).join('')}
function vo(pid,s=''){if(!pid)return '<option value="">ابتدا محصول...</option>';const rows=variants.filter(v=>String(v.product_id)===String(pid));return '<option value="">انتخاب تنوع...</option>'+rows.map(v=>`<option value="${v.id}" data-stock="${v.stock}" data-model="${v.model_name||''}" data-variety="${v.variety_code||''}" ${String(s)===String(v.id)?'selected':''}>${v.name||'بدون نام'} ${v.variety_code? '(طرح '+v.variety_code+')':''} ${v.code? '['+v.code+']':''}</option>`).join('')}
function row(i){return `<tr><td><input type="text" class="form-control form-control-sm mb-1 ps" placeholder="جستجو با نام/کد کالا"><select name="items[${i}][product_id]" class="form-select p" required>${po()}</select><input type="hidden" name="items[${i}][category_id]" class="cat-hidden"></td><td><input class="form-control m" readonly placeholder="بعد از انتخاب تنوع"></td><td><select name="items[${i}][variant_id]" class="form-select v" required><option value="">ابتدا محصول...</option></select></td><td><span class="badge text-bg-light st">—</span></td><td><input name="items[${i}][quantity]" type="number" min="1" class="form-control q" required></td><td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()">حذف</button></td></tr>`}
function filterProducts(sel,term){const t=String(term||'').trim().toLowerCase();[...sel.options].forEach((o,idx)=>{if(idx===0)return;o.hidden=t!=='' && !o.textContent.toLowerCase().includes(t);});}
function bind(tr){const p=tr.querySelector('.p'),v=tr.querySelector('.v'),q=tr.querySelector('.q'),st=tr.querySelector('.st'),cat=tr.querySelector('.cat-hidden'),m=tr.querySelector('.m'),ps=tr.querySelector('.ps');const sync=()=>{const opt=v.selectedOptions[0];const s=Number(opt?.dataset.stock||0);m.value=opt?.value?`${opt.dataset.model||'بدون مدل'} / طرح ${opt.dataset.variety||'0000'}`:'';if(s>0){q.max=String(s);if(Number(q.value||0)>s)q.value=String(s);st.textContent=s.toLocaleString('fa-IR');}else{q.removeAttribute('max');st.textContent='—';}const prod=products.find(x=>String(x.id)===String(p.value));cat.value=prod?String(prod.category_id||''):'';};ps.addEventListener('input',()=>filterProducts(p,ps.value));p.addEventListener('change',()=>{v.innerHTML=vo(p.value);q.value='';sync();});v.addEventListener('change',sync);q.addEventListener('input',sync);}
addBtn.addEventListener('click',()=>{tbody.insertAdjacentHTML('beforeend',row(tbody.querySelectorAll('tr').length));bind(tbody.querySelector('tr:last-child'));});addBtn.click();
</script>
@endsection
