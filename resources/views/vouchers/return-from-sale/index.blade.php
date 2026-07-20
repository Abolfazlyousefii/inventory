@extends('layouts.app')
@php
    use App\Models\SalesReturnDocument;
    use App\Models\SalesReturnDocumentItem;
    $sourceLabels = SalesReturnDocument::sourceTypeLabels();
    $reasonLabels = SalesReturnDocument::returnReasonLabels();
@endphp
@section('content')
<style>
.sr-index{font-size:.84rem}.sr-index .form-control,.sr-index .form-select{height:39px;font-size:12.5px}.sr-filter-box{border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:10px}.sr-filter-results{position:absolute;z-index:1050;top:100%;right:0;left:0;max-height:260px;overflow:auto;background:#fff;border:1px solid #dbe3ea;border-radius:10px;box-shadow:0 16px 32px rgba(15,23,42,.14)}.sr-filter-result{padding:.45rem .6rem;border-bottom:1px solid #edf2f7;cursor:pointer}.sr-filter-result.active,.sr-filter-result:hover{background:#f8fafc}.sr-items-summary{max-width:260px;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}.sr-destination-detail{max-width:170px;white-space:normal}.table td,.table th{padding:.42rem .5rem;vertical-align:middle}@media(max-width:768px){.sr-actions{width:100%}.sr-actions .btn{flex:1}}
</style>
<div class="container-fluid sr-index" dir="rtl" data-module="new-sales-return">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div><h4 class="mb-1">برگشت از فروش</h4><div class="text-muted small">فهرست اسناد جدید و حواله‌های قدیمی برگشت از فروش</div></div>
        <div class="d-flex gap-2 flex-wrap">@canPermission('sales_returns.create')<a class="btn btn-sm btn-primary" href="{{ route('vouchers.return-from-sale.create') }}">ثبت برگشت جدید</a>@endcanPermission @canPermission('sales_returns.export')<a class="btn btn-sm btn-outline-success" href="{{ route('vouchers.return-from-sale.export.excel', request()->query()) }}">Excel</a><a class="btn btn-sm btn-outline-danger" href="{{ route('vouchers.return-from-sale.export.pdf', request()->query()) }}">PDF</a>@endcanPermission @canPermission('sales_returns.print')<a class="btn btn-sm btn-outline-secondary" href="{{ route('vouchers.return-from-sale.print-report', request()->query()) }}">چاپ گزارش</a>@endcanPermission <a class="btn btn-sm btn-light" href="{{ route('vouchers.index') }}">بازگشت</a></div>
    </div>

    <form class="sr-filter-box mb-3" method="GET">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-2"><label class="form-label small">شماره سند یا حواله</label><input class="form-control form-control-sm" name="document_number" value="{{ $filters['document_number'] ?? '' }}"></div>
            <div class="col-12 col-md-3"><label class="form-label small">انتخاب مشتری</label>@include('vouchers.return-from-sale.partials.customer-filter-picker')</div>
            <div class="col-6 col-md-2"><label class="form-label small">از تاریخ</label><input type="text" id="salesReturnDateFrom" class="form-control form-control-sm" name="date_from" data-jdp inputmode="numeric" autocomplete="off" placeholder="۱۴۰۵/۰۴/۲۸" value="{{ $filters['date_from'] ?? '' }}"></div>
            <div class="col-6 col-md-2"><label class="form-label small">تا تاریخ</label><input type="text" id="salesReturnDateTo" class="form-control form-control-sm" name="date_to" data-jdp inputmode="numeric" autocomplete="off" placeholder="۱۴۰۵/۰۴/۲۸" value="{{ $filters['date_to'] ?? '' }}"></div>
            <div class="col-12 col-md-3 d-flex gap-1 sr-actions"><button class="btn btn-sm btn-dark" type="submit">اعمال</button><button class="btn btn-sm btn-light" type="button" id="salesReturnClearFilters">پاک‌کردن</button><button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#moreFilters">بیشتر</button></div>
        </div>
        <div class="collapse mt-2 {{ collect($filters)->except(['document_number','customer_id','date_from','date_to'])->filter()->isNotEmpty() ? 'show' : '' }}" id="moreFilters"><div class="row g-2 align-items-end">
            <div class="col-6 col-lg-2"><label class="form-label small">نوع برگشت</label><select class="form-select form-select-sm" name="source_type"><option value="all">همه</option>@foreach($sourceLabels as $key=>$label)<option value="{{ $key }}" @selected(($filters['source_type'] ?? 'all') === $key)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-6 col-lg-2"><label class="form-label small">علت برگشت</label><select class="form-select form-select-sm" name="return_reason"><option value="">همه</option>@foreach($reasonLabels as $key=>$label)<option value="{{ $key }}" @selected(($filters['return_reason'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-6 col-lg-2"><label class="form-label small">انبار مقصد</label><select class="form-select form-select-sm" name="destination_warehouse_id"><option value="">همه</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected(($filters['destination_warehouse_id'] ?? null) == $warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></div>
            <div class="col-6 col-lg-2"><label class="form-label small">وضعیت کالا</label><select class="form-select form-select-sm" name="item_condition"><option value="all">همه</option><option value="healthy" @selected(($filters['item_condition'] ?? 'all')==='healthy')>سالم</option><option value="damaged" @selected(($filters['item_condition'] ?? 'all')==='damaged')>معیوب</option></select></div>
            <div class="col-6 col-lg-2"><label class="form-label small">شناسه کالا</label><input inputmode="numeric" class="form-control form-control-sm" name="product_id" value="{{ $filters['product_id'] ?? '' }}"></div>
            <div class="col-6 col-lg-2"><label class="form-label small">تنوع</label><input inputmode="numeric" class="form-control form-control-sm" name="product_variant_id" value="{{ $filters['product_variant_id'] ?? '' }}"></div>
            <div class="col-6 col-lg-2"><label class="form-label small">حداقل مبلغ</label><input inputmode="numeric" class="form-control form-control-sm" name="min_amount" value="{{ $filters['min_amount'] ?? '' }}"></div>
            <div class="col-6 col-lg-2"><label class="form-label small">حداکثر مبلغ</label><input inputmode="numeric" class="form-control form-control-sm" name="max_amount" value="{{ $filters['max_amount'] ?? '' }}"></div>
            <div class="col-12 col-lg-2"><label class="form-label small">مرتب‌سازی</label><select class="form-select form-select-sm" name="sort"><option value="newest">جدیدترین</option><option value="oldest" @selected(($filters['sort'] ?? '')==='oldest')>قدیمی‌ترین</option><option value="amount_desc" @selected(($filters['sort'] ?? '')==='amount_desc')>مبلغ نزولی</option><option value="amount_asc" @selected(($filters['sort'] ?? '')==='amount_asc')>مبلغ صعودی</option><option value="customer" @selected(($filters['sort'] ?? '')==='customer')>مشتری</option></select></div>
        </div></div>
    </form>

    <div id="salesReturnResults">
        @include('vouchers.return-from-sale.partials.index-results')
    </div>
</div>
<script>
(()=>{
const form=document.querySelector('.sr-filter-box');const results=document.getElementById('salesReturnResults');if(!form||!results)return;
if(window.jalaliDatepicker){window.jalaliDatepicker.startWatch({selector:'#salesReturnDateFrom, #salesReturnDateTo',persianDigits:true,zIndex:3000});}
let timer=null, controller=null;const indexUrl=@json(route('vouchers.return-from-sale.index'));
const setLoading=v=>results.classList.toggle('opacity-50',v);
const liveFetch=(url=null)=>{controller?.abort();controller=new AbortController();const params=new URLSearchParams(new FormData(form));for(const [k,v] of [...params.entries()]){if(v===''||v==='all')params.delete(k)}const target=url||`${indexUrl}${params.toString()?`?${params.toString()}`:''}`;setLoading(true);fetch(target,{headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},signal:controller.signal}).then(r=>r.json()).then(j=>{results.innerHTML=j.html;history.replaceState({},'',j.url);}).catch(e=>{if(e.name!=='AbortError')console.error(e)}).finally(()=>setLoading(false));};
const schedule=()=>{clearTimeout(timer);timer=setTimeout(()=>liveFetch(),350)};
['document_number','date_from','date_to'].forEach(n=>form.querySelector(`[name="${n}"]`)?.addEventListener('input',schedule));
['customer_id','source_type','return_reason','destination_warehouse_id','item_condition','product_id','product_variant_id','sort'].forEach(n=>form.querySelector(`[name="${n}"]`)?.addEventListener('change',schedule));
['salesReturnDateFrom','salesReturnDateTo'].forEach(id=>{const el=document.getElementById(id);el?.addEventListener('jdp:change',schedule);el?.addEventListener('change',schedule);});
results.addEventListener('click',e=>{const a=e.target.closest('.pagination a');if(!a)return;e.preventDefault();liveFetch(a.href)});
document.getElementById('salesReturnClearFilters')?.addEventListener('click',()=>{form.reset();form.querySelectorAll('input').forEach(i=>{if(i.type!=='hidden'||i.name==='customer_id')i.value=''});const hidden=form.querySelector('[name="customer_id"]');if(hidden)hidden.value='';form.querySelectorAll('select').forEach(s=>s.selectedIndex=0);document.querySelector('[data-customer-selected] span')&&(document.querySelector('[data-customer-selected] span').textContent='مشتری انتخاب نشده است.');document.querySelector('[data-customer-clear]')?.classList.add('d-none');const collapse=document.getElementById('moreFilters');if(collapse&&window.bootstrap)bootstrap.Collapse.getOrCreateInstance(collapse,{toggle:false}).hide();history.replaceState({},'',indexUrl);liveFetch(indexUrl)});
})();

(()=>{document.querySelectorAll('[data-customer-picker]').forEach(root=>{const url=root.dataset.searchUrl,input=root.querySelector('[data-customer-search]'),hidden=root.querySelector('[data-customer-id]'),box=root.querySelector('[data-customer-results]'),label=root.querySelector('[data-customer-selected] span'),clear=root.querySelector('[data-customer-clear]');let timer,abort,active=-1,items=[];const close=()=>box.classList.add('d-none');const render=()=>{box.innerHTML=items.map((c,i)=>`<div class="sr-filter-result ${i===active?'active':''}" data-i="${i}"><strong>${c.name||c.text||'—'}</strong><div class="small text-muted">${c.mobile||'—'} | کد: ${c.customer_code||c.id}</div></div>`).join('')||'<div class="p-2 small text-muted">نتیجه‌ای یافت نشد.</div>';box.classList.remove('d-none')};const pick=c=>{hidden.value=c?.id||'';input.value=c?(c.name||c.text||''):'';label.textContent=c?`${c.name||c.text||'—'} | ${c.mobile||'—'} | کد: ${c.customer_code||c.id}`:'مشتری انتخاب نشده است.';clear.classList.toggle('d-none',!c);close()};const search=q=>{if((q||'').trim().length<2){items=[];close();return}abort?.abort();abort=new AbortController();fetch(url+'?q='+encodeURIComponent(q),{headers:{Accept:'application/json'},signal:abort.signal}).then(r=>r.json()).then(j=>{items=j.data||j.results||[];active=items.length?0:-1;render()}).catch(e=>{if(e.name!=='AbortError')console.error(e)})};input.addEventListener('input',e=>{hidden.value='';clearTimeout(timer);timer=setTimeout(()=>search(e.target.value),280)});input.addEventListener('keydown',e=>{if(e.key==='Escape'){close();return}if(!items.length)return;if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();active=(active+(e.key==='ArrowDown'?1:-1)+items.length)%items.length;render()}if(e.key==='Enter'){e.preventDefault();pick(items[active])}});box.addEventListener('click',e=>{const r=e.target.closest('[data-i]');if(r)pick(items[Number(r.dataset.i)])});clear.addEventListener('click',()=>pick(null));document.addEventListener('click',e=>{if(!root.contains(e.target))close()})})})();
</script>
@endsection
