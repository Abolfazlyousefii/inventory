@extends('layouts.app')
@php
 $categoriesJson=$categories->map(fn($c)=>['id'=>$c->id,'name'=>$c->name.($c->parent?' — '.$c->parent->name:'')])->values();
 $oldItemsJson=old('items',[]);
 $oldProductsJson=\App\Models\Product::whereIn('id',collect($oldItemsJson)->pluck('product_id'))->get(['id','name'])->keyBy('id');
 $variantLookupUrl=route('vouchers.personnel.products.variants',['product'=>'__PRODUCT__']);
@endphp
@section('title','ثبت حواله شوروم')
@section('content')
<div class="showroom-editor" dir="rtl">
 <header class="sr-card"><div><small>حواله‌های انبار / شوروم</small><h1>ثبت حواله شوروم</h1><p>کالا از موجودی قابل فروش انبار مرکزی کم و به موجودی مستقل شوروم اضافه می‌شود.</p></div><a class="btn btn-outline-secondary" href="{{route('vouchers.section.index','showroom')}}">سوابق حواله شوروم</a></header>
 <form method="POST" action="{{route('vouchers.section.store','showroom')}}" id="showroom-form">@csrf
  <input type="hidden" name="request_token" value="{{old('request_token',(string)\Illuminate\Support\Str::uuid())}}">
  <input type="hidden" name="from_warehouse_id" value="{{$centralWarehouse->id}}"><input type="hidden" name="to_warehouse_id" value="{{$showroomWarehouse->id}}">
  <section class="sr-card sr-route"><div><small>مبدا</small><strong>{{$centralWarehouse->name}}</strong></div><b aria-hidden="true">←</b><div><small>مقصد</small><strong>{{$showroomWarehouse->name}}</strong></div></section>
  <section class="sr-card"><div class="sr-head"><h2>کالاهای حواله</h2><button type="button" class="btn btn-outline-primary" id="sr-add">+ افزودن کالا</button></div><p>دسته را انتخاب کنید؛ کالا را با نام یا کد پیدا کنید و سپس مدل و تعداد را مشخص کنید.</p><div id="sr-rows"></div></section>
  <section class="sr-card sr-meta"><label>شماره حواله (اختیاری)<input name="reference" class="form-control" maxlength="100" value="{{old('reference')}}"></label><label>تاریخ شمسی<input name="transferred_at_jalali" class="form-control" data-jdp data-jdp-only-date autocomplete="off" value="{{old('transferred_at_jalali',\App\Support\JalaliDate::date(now(),''))}}"></label><label>توضیحات (اختیاری)<input name="note" class="form-control" maxlength="255" value="{{old('note')}}"></label></section>
  <section class="sr-card sr-head"><span id="sr-summary" aria-live="polite"></span><button class="btn btn-primary" id="sr-submit">ثبت انتقال به شوروم</button></section>
 </form>
</div>
@endsection
@push('styles')<style>
.showroom-editor{max-width:1100px;margin:22px auto;color:var(--app-text,#17364d)}.sr-card{background:var(--app-surface,#fff);border:1px solid var(--app-border,#cdddea);border-radius:15px;padding:22px;margin-bottom:16px}.showroom-editor header{display:flex;gap:16px;align-items:center;justify-content:space-between;border-right:5px solid #7352b0}.showroom-editor h1{font-size:23px;font-weight:800}.showroom-editor h2{font-size:17px;font-weight:700}.showroom-editor p{font-size:13px;line-height:1.9;color:var(--app-text-soft,#60788a)}.sr-route{display:flex;gap:30px;background:var(--app-surface-soft,#eef7fb)}.sr-route small,.sr-route strong{display:block}.sr-head{display:flex;justify-content:space-between;align-items:center;gap:12px}.sr-item{border:1px solid var(--app-border,#cadfec);border-radius:12px;background:var(--app-surface-soft,#f8fbfd);padding:16px;margin-top:14px}.sr-grid{display:grid;grid-template-columns:1fr 2fr;gap:14px}.sr-item label,.sr-meta label{display:block;font-size:13px;line-height:2}.sr-item select,.sr-item input{width:100%}.sr-item [hidden]{display:none}.sr-meta{display:grid;grid-template-columns:1fr 1fr 2fr;gap:14px}.sr-status{font-size:12px;color:var(--app-text-soft,#60788a);line-height:2}.sr-stock{border-radius:9px;background:var(--app-success-soft,#e9f7f0);color:var(--app-success,#14795a);padding:8px 12px;margin-top:12px}.sr-item .sr-bottom{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-top:12px}@media(max-width:640px){.showroom-editor header,.sr-head{align-items:stretch;flex-direction:column}.sr-grid,.sr-meta{grid-template-columns:1fr}.sr-card{padding:16px}.showroom-editor{margin:12px 0}.sr-route{gap:16px;justify-content:space-between}}
</style>@endpush
@push('scripts')
<script>
(()=>{
const form=document.getElementById('showroom-form'),rows=document.getElementById('sr-rows');let next=0;
const categories=@json($categoriesJson);
const oldItems=@json($oldItemsJson);
const oldProducts=@json($oldProductsJson);
const productUrl=@json(route('vouchers.personnel.products.search'));
const variantUrl=@json($variantLookupUrl);
const norm=s=>String(s||'').replace(/[يى]/g,'ی').replace(/ك/g,'ک').replace(/[۰-۹]/g,c=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(c)).replace(/[٠-٩]/g,c=>'٠١٢٣٤٥٦٧٨٩'.indexOf(c)).toLowerCase();
function options(select,items,title){select.replaceChildren(new Option(title,''));for(const item of items)select.add(new Option(item.text||item.name,String(item.id)));}
async function json(url,signal){const r=await fetch(url,{headers:{Accept:'application/json'},signal});if(!r.ok)throw new Error(r.status===403?'دسترسی دریافت کالا مجاز نیست.':'دریافت اطلاعات انجام نشد؛ دوباره انتخاب کنید.');return r.json();}
function summary(){const cards=[...rows.querySelectorAll('.sr-item')];const total=cards.reduce((s,r)=>s+Number(r.querySelector('.sr-quantity').value||0),0);document.getElementById('sr-summary').textContent=`${cards.length.toLocaleString('fa-IR')} ردیف • ${total.toLocaleString('fa-IR')} عدد برای انتقال`;document.getElementById('sr-submit').disabled=cards.length===0;}
async function add(saved={}){
 const i=next++,card=document.createElement('div');card.className='sr-item';card.innerHTML=`<div class="sr-grid"><label>دسته‌بندی<select class="form-select sr-category" name="items[${i}][category_id]" required></select></label><label>جست‌وجوی کالا<input class="form-control sr-search" placeholder="نام یا کد کالا در این دسته" type="search"><select class="form-select sr-product" name="items[${i}][product_id]" required><option value="">ابتدا دسته را انتخاب کنید</option></select></label><label>تعداد انتقال<input class="form-control sr-quantity" type="number" min="1" step="1" inputmode="numeric" name="items[${i}][quantity]" required></label><label>مدل / رنگ / تنوع<input class="form-control sr-filter" type="search" placeholder="جست‌وجوی مدل، رنگ یا کد تنوع"><select class="form-select sr-variant" name="items[${i}][variant_id]" required><option value="">ابتدا کالا را انتخاب کنید</option></select></label></div><div class="sr-stock">موجودی قابل انتقال: —</div><div class="sr-bottom"><span class="sr-status" aria-live="polite"></span><button type="button" class="btn btn-sm btn-outline-danger sr-remove">حذف ردیف</button></div>`;rows.append(card);
 const cat=card.querySelector('.sr-category'),search=card.querySelector('.sr-search'),product=card.querySelector('.sr-product'),variant=card.querySelector('.sr-variant'),filter=card.querySelector('.sr-filter'),quantity=card.querySelector('.sr-quantity'),stock=card.querySelector('.sr-stock'),status=card.querySelector('.sr-status');let pa,va,timer,variantRows=[];
 options(cat,categories,'انتخاب دسته…');
 const reset=()=>{va?.abort();variantRows=[];options(variant,[],'ابتدا کالا را انتخاب کنید');variant.setCustomValidity('');quantity.value='';quantity.removeAttribute('max');stock.textContent='موجودی قابل انتقال: —';summary();};
 async function loadProducts(){pa?.abort();pa=new AbortController();reset();options(product,[],'در حال دریافت…');if(!cat.value){options(product,[],'ابتدا دسته را انتخاب کنید');return;}status.textContent='در حال دریافت کالاها…';try{const data=await json(`${productUrl}?category_id=${encodeURIComponent(cat.value)}&q=${encodeURIComponent(search.value.trim())}`,pa.signal);options(product,data.results,'انتخاب کالا…');status.textContent=data.results.length?'':'کالایی پیدا نشد؛ نام یا کد دقیق‌تری بنویسید.';}catch(e){if(e.name!=='AbortError'){status.textContent=e.message;options(product,[],'دریافت انجام نشد');}}}
 async function loadVariants(selected=''){va?.abort();va=new AbortController();options(variant,[],'در حال دریافت مدل‌ها…');if(!product.value)return;status.textContent='در حال دریافت مدل‌ها…';try{const data=await json(variantUrl.replace('__PRODUCT__',encodeURIComponent(product.value)),va.signal);variantRows=data.results;options(variant,variantRows,'انتخاب مدل / رنگ…');if(selected)variant.value=String(selected);status.textContent=variantRows.length?'':'تنوع فعالی برای این کالا پیدا نشد.';updateStock();}catch(e){if(e.name!=='AbortError'){status.textContent=e.message;options(variant,[],'دریافت انجام نشد');}}}
 function updateStock(){const item=variantRows.find(v=>String(v.id)===variant.value);stock.textContent='موجودی قابل انتقال: '+(item?Number(item.central_stock).toLocaleString('fa-IR')+' عدد':'—');variant.setCustomValidity(item&&Number(item.central_stock)<=0?'موجودی این مدل در انبار مرکزی صفر است؛ مدل دیگری انتخاب کنید.':'');if(item)quantity.max=String(item.central_stock);else quantity.removeAttribute('max');summary();}
 cat.addEventListener('change',()=>{search.value='';loadProducts();});search.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(loadProducts,300);});product.addEventListener('change',()=>{reset();loadVariants();});variant.addEventListener('change',updateStock);quantity.addEventListener('input',summary);filter.addEventListener('input',()=>{const term=norm(filter.value);for(const opt of variant.options)opt.hidden=!!opt.value&&!norm(opt.textContent).includes(term);});card.querySelector('.sr-remove').addEventListener('click',()=>{clearTimeout(timer);pa?.abort();va?.abort();card.remove();summary();});
 if(saved.category_id){cat.value=String(saved.category_id);const old=oldProducts[String(saved.product_id)];if(old){options(product,[old],'انتخاب کالا…');product.value=String(saved.product_id);await loadVariants(saved.variant_id);quantity.value=saved.quantity||'';}else await loadProducts();}summary();
}
document.getElementById('sr-add').addEventListener('click',()=>add());if(Object.keys(oldItems).length)Object.values(oldItems).forEach(add);else add();form.addEventListener('submit',()=>{const button=document.getElementById('sr-submit');button.disabled=true;button.textContent='در حال ثبت انتقال…';});
})();
</script>
@endpush
