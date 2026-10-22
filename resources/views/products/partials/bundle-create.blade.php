@php
    $oldBundleRows = collect(old('components', []));
    $oldIds = $oldBundleRows->pluck('variant_id')->filter()->map(fn ($v) => (int) $v)->unique();
    $oldVariants = $oldIds->isNotEmpty() ? \App\Models\ProductVariant::with('product')->whereIn('id', $oldIds)->get()->keyBy('id') : collect();
    $initialItems = $oldBundleRows->map(function ($row) use ($oldVariants) {
        $variant = $oldVariants->get((int) ($row['variant_id'] ?? 0));
        return $variant ? [
            'id' => (int) $variant->id,
            'product_id' => (int) $variant->product_id,
            'name' => (string) ($variant->product?->name ?? ''),
            'variant' => (string) ($variant->variant_name ?: $variant->variety_name ?: 'تنوع اصلی'),
            'code' => (string) $variant->variant_code,
            'sell_price' => \App\Services\BundleDiscountService::effectiveComponentPrice($variant),
            'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
            'discount_type' => in_array(($row['discount_type'] ?? 'percent'), ['percent', 'fixed'], true) ? $row['discount_type'] : 'percent',
            'discount_value' => max(0, (int) ($row['discount_value'] ?? 0)),
            'stock' => null,
        ] : null;
    })->filter()->values();
@endphp
<style>
    .kit-choice {border:1px solid #dbe4ef;border-radius:14px;padding:14px;background:#fff;cursor:pointer}
    .kit-choice:has(input:checked){border-color:#2563eb;background:#eff6ff}
    .kit-result {position:absolute;inset-inline:0;top:100%;z-index:40;background:#fff;border:1px solid #dbe4ef;border-radius:12px;max-height:350px;overflow:auto;box-shadow:0 10px 24px #0f172a20}
    .kit-selected {display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:12px;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:8px}
    .kit-preview {background:#f8fafc;border:1px solid #dbeafe;border-radius:14px;padding:16px}
    .kit-component-discount {display:flex;align-items:center;gap:6px;flex-wrap:wrap}
    .kit-component-discount select {width:92px}
    .kit-component-discount input {width:108px}
</style>
<div class="col-12" dir="rtl">
  <div class="card-soft">
    <div class="section-head"><div><div class="section-title">نوع کالا</div><div class="muted">محصول عادی یا بسته دارای چند محصول با یک قیمت نهایی</div></div></div>
    <div class="p-3 d-flex flex-wrap gap-3">
      <label class="kit-choice flex-grow-1"><input name="product_type" type="radio" value="regular" @checked(old('product_type','regular') === 'regular')> کالای عادی</label>
      <label class="kit-choice flex-grow-1"><input name="product_type" type="radio" value="bundle" @checked(old('product_type') === 'bundle')> بسته ترکیبی</label>
    </div>
  </div>
</div>
<div class="col-12" id="bundleCreateSection" dir="rtl" style="display:none">
  <div class="card-soft">
    <div class="section-head"><div><div class="section-title">ساخت بسته ترکیبی</div><div class="muted">ابتدا محصول را پیدا کنید، سپس تنوع دقیق آن را انتخاب کنید.</div></div><span class="chip">یک بسته = یک کالا در سبد خرید</span></div>
    <div class="p-3">
      <input type="hidden" name="bundle_stock_mode" value="automatic">
      <input type="hidden" name="bundle_quantity_mode" value="shared">
      <div class="rounded border p-3 mb-3" style="background:#f8fafc">
        <label for="bundleSharedQuantity" class="form-label fw-bold">تعداد بسته قابل عرضه (موجودی یکسان برای تمام قطعات)</label>
        <input id="bundleSharedQuantity" class="form-control" style="max-width:220px" type="number" min="0" max="1000000" step="1" name="bundle_shared_quantity" value="{{ old('bundle_shared_quantity',0) }}">
        <div class="form-text">برای مثال ۵ بسته، اگر در هر بسته ۳ عدد از یک تنوع باشد، ۱۵ عدد از همان تنوع برای این بسته کنار گذاشته می‌شود.</div>
      </div>
      <div class="mb-3 position-relative">
        <label class="form-label fw-bold" for="bundleCreateSearch">افزودن محصول</label>
        <input id="bundleCreateSearch" class="form-control" type="search" autocomplete="off" placeholder="جستجو با نام محصول، کد یا SKU (حداقل ۲ حرف)" aria-expanded="false" aria-controls="bundleCreateResults">
        <div id="bundleCreateResults" class="kit-result" role="listbox" hidden></div>
        <div class="form-text">مرحله اول: محصول را انتخاب کنید. مرحله دوم: تنوع/رنگ دقیق را انتخاب کنید. اگر یک محصول تنها یک تنوع دارد، همان را اضافه کنید.</div>
      </div>
      <div class="d-flex align-items-center justify-content-between mb-2"><strong>اقلام بسته</strong><small id="bundleCreateCount" class="text-muted"></small></div>
      <div id="bundleCreateItems" aria-live="polite"></div>
      <div id="bundleCreateEmpty" class="border rounded p-3 text-muted small">هنوز محصولی انتخاب نشده است.</div>
      <div class="border rounded p-3 mt-4">
        <h5 class="mb-2">قیمت‌گذاری و تخفیف بسته</h5>
        <div class="form-text mb-3">ابتدا تخفیف هر قطعه از قیمت یک عدد آن کم می‌شود؛ سپس تخفیف کلی بسته اعمال می‌شود. قیمت روی سایت همگام‌سازی خواهد شد.</div>
        <div class="row g-3">
          <div class="col-md-6"><div class="text-muted small">قیمت عادی مجموع اقلام</div><strong id="bundleOriginalPrice" class="fs-5">۰</strong><span class="text-muted small"> ریال</span></div>
          <div class="col-md-6"><div class="text-muted small">قیمت پس از تخفیف قطعات</div><strong id="bundleComponentNetPrice" class="fs-5">۰</strong><span class="text-muted small"> ریال</span></div>
          <div class="col-md-6"><label class="form-label fw-bold" for="bundleDiscountType">روش تعیین تخفیف کلی بسته</label>
            <select id="bundleDiscountType" class="form-select" name="bundle_discount_type">
              <option value="percent" @selected(old('bundle_discount_type','percent') === 'percent')>درصد تخفیف</option>
              <option value="final" @selected(old('bundle_discount_type') === 'final')>قیمت نهایی بسته</option>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label fw-semibold" for="bundleDiscountValue" id="bundleDiscountValueLabel">درصد تخفیف (۰ تا ۹۹)</label>
            <div class="input-group" dir="ltr"><input id="bundleDiscountValue" type="text" class="form-control" inputmode="numeric" autocomplete="off" value="{{ old('bundle_discount_value', 0) }}" aria-describedby="bundleDiscountHelp bundleDiscountCheck"><span class="input-group-text" id="bundleDiscountUnit">٪</span></div>
            <input type="hidden" name="bundle_discount_value" id="bundleDiscountValueRaw" value="{{ old('bundle_discount_value', 0) }}">
            <div id="bundleDiscountCheck" class="small mt-1" role="status" aria-live="polite"></div>
            <div id="bundleDiscountHelp" class="form-text">مثال: ۲۰ یعنی ۲۰٪ کمتر از مجموع قیمت اقلام.</div>
          </div>
          <div class="col-md-6"><div class="text-muted small">قیمت نهایی بسته</div><strong id="bundleFinalPrice" class="fs-4 text-success">۰</strong><span class="text-muted small"> ریال</span>
            <div class="text-muted small">صرفه‌جویی: <strong id="bundleSavings">۰</strong> ریال <span id="bundlePercentPreview"></span></div>
          </div>
        </div>
        <input id="bundleSellPrice" type="hidden" name="sell_price" value="{{ old('sell_price') }}">
        <div class="mt-3"><label for="bundleBuyPrice" class="form-label">هزینه خرید بسته (اختیاری)</label><input id="bundleBuyPrice" class="form-control" style="max-width:230px" type="text" inputmode="numeric" autocomplete="off" name="buy_price" value="{{ old('buy_price') }}"></div>
      </div>
      <div class="kit-preview mt-3"><strong>خلاصه نهایی</strong><div id="bundleCreateReview" class="small text-muted mt-2"></div></div>
      <p class="text-muted small mt-3 mb-0">این فرم موجودی جدید نمی‌سازد. هنگام تأیید، مقدار مشخص‌شده از موجودی آزاد هر تنوع برای بسته رزرو و از فروش تکی کنار گذاشته می‌شود.</p>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const form=document.getElementById('productCreateForm'), section=document.getElementById('bundleCreateSection');
  if(!form || !section) return;
  const structure=document.getElementById('variantStructureCard'), search=document.getElementById('bundleCreateSearch');
  const results=document.getElementById('bundleCreateResults'),list=document.getElementById('bundleCreateItems'),empty=document.getElementById('bundleCreateEmpty');
  const count=document.getElementById('bundleCreateCount'),review=document.getElementById('bundleCreateReview');
  const shared=document.getElementById('bundleSharedQuantity'),type=document.getElementById('bundleDiscountType');
  const value=document.getElementById('bundleDiscountValue'),rawValue=document.getElementById('bundleDiscountValueRaw'),sell=document.getElementById('bundleSellPrice'),buy=document.getElementById('bundleBuyPrice');
  const checker=document.getElementById('bundleDiscountCheck'),unit=document.getElementById('bundleDiscountUnit');
  const submit=form.querySelector('button[type="submit"]'),nameInput=form.querySelector('input[name="name"]');
  const selected=new Map();
  const initial={{ \Illuminate\Support\Js::from($initialItems) }};
  initial.forEach(i=>selected.set(Number(i.id),{...i,quantity:Math.max(1,Number(i.quantity)||1)}));
  const money=n=>Math.round(n).toLocaleString('fa-IR');
  const on=()=>form.querySelector('input[name="product_type"]:checked')?.value==='bundle';
  const original=()=>[...selected.values()].reduce((sum,i)=>sum+Math.max(0,Number(i.sell_price)||0)*Math.max(0,Number(i.quantity)||0),0);
  const perUnitDiscount=i=>{
    const price=Number(i.sell_price)||0,v=Number(i.discount_value)||0;
    if(!Number.isSafeInteger(v)||v<0||!['percent','fixed'].includes(i.discount_type||'percent'))return NaN;
    if(i.discount_type==='fixed')return v<price?v:NaN;
    if(v>99)return NaN;
    return Math.min(price-1,Math.round(price*v/100));
  };
  const discountedBase=()=>[...selected.values()].reduce((sum,i)=>sum+(Number(i.sell_price)-perUnitDiscount(i))*Number(i.quantity),0);
  const digits=s=>String(s||'').replace(/[۰-۹]/g,c=>String(c.charCodeAt(0)-1776)).replace(/[٠-٩]/g,c=>String(c.charCodeAt(0)-1632)).replace(/[^0-9]/g,'');
  const discountNumber=()=>Number(rawValue.value);
  function normalizeDiscount(){
    const d=digits(value.value);rawValue.value=d;
    value.value=type.value==='final'&&d?Number(d).toLocaleString('en-US'):d;
  }
  const final=()=>{
    const total=discountedBase(),v=discountNumber();
    if(rawValue.value===''||!Number.isFinite(v)||v<0)return NaN;
    if(type.value==='percent')return v>99 ? NaN : Math.max(1,total-Math.round(total*v/100));
    return v>total ? NaN : v;
  };
  function quote(){
    const regular=original(),base=discountedBase(),finalPrice=final(),ok=Number.isSafeInteger(base)&&base>0&&Number.isSafeInteger(finalPrice)&&finalPrice>0&&finalPrice<=base;
    document.getElementById('bundleOriginalPrice').textContent=money(regular);
    document.getElementById('bundleComponentNetPrice').textContent=Number.isSafeInteger(base)?money(base):'—';
    document.getElementById('bundleFinalPrice').textContent=ok?money(finalPrice):'—';
    document.getElementById('bundleSavings').textContent=ok?money(regular-finalPrice):'—';
    document.getElementById('bundlePercentPreview').textContent=ok?'('+Math.round((regular-finalPrice)/regular*100)+'٪)':'';
    checker.textContent=ok?'✓ مقدار معتبر است.':'تخفیف هر قطعه را بررسی کنید؛ قیمت نهایی باید بین ۱ و '+money(Number.isFinite(base)?base:0)+' ریال باشد.';
    checker.className=ok?'small mt-1 text-success':'small mt-1 text-danger';
    value.setAttribute('aria-invalid',ok?'false':'true');
    sell.value=ok?finalPrice:'';
    return ok;
  }
  function updateDiscountMode(reset=true){
    const pct=type.value==='percent';
    document.getElementById('bundleDiscountValueLabel').textContent=pct?'درصد تخفیف (۰ تا ۹۹)':'قیمت نهایی بسته (ریال)';
    document.getElementById('bundleDiscountHelp').textContent=pct?'قیمت بسته به صورت خودکار با این درصد محاسبه می‌شود.':'قیمت نهایی را وارد کنید؛ میزان صرفه‌جویی به صورت خودکار نمایش داده می‌شود.';
    unit.textContent=pct?'٪':'ریال';
    if(reset)value.value=pct?'0':String(discountedBase());
    normalizeDiscount();updateTotals();
  }
  function refresh(){
    list.replaceChildren();
    [...selected.values()].forEach((i,idx)=>{
      const row=document.createElement('div');row.className='kit-selected';
      const details=document.createElement('div');details.className='flex-grow-1';
      const title=document.createElement('strong');title.textContent=i.name;
      const sub=document.createElement('div');sub.className='small text-muted';sub.textContent=`تنوع: ${i.variant||'اصلی'} · ${i.code||''} · قیمت عادی: ${money(Number(i.sell_price)||0)} ریال · موجودی آزاد: ${i.stock??'نامشخص'}`;
      details.append(title,sub);
      const hidden=document.createElement('input');hidden.type='hidden';hidden.name=`components[${idx}][variant_id]`;hidden.value=i.id;
      const qWrap=document.createElement('div');qWrap.className='d-flex align-items-center gap-2';
      const qLabel=document.createElement('label');qLabel.className='small text-muted';qLabel.textContent='تعداد در هر بسته';
      const q=document.createElement('input');q.type='number';q.inputMode='numeric';q.min='1';q.max='100000';q.step='1';q.required=true;
      q.className='form-control';q.style.width='100px';q.dir='ltr';q.name=`components[${idx}][quantity]`;q.value=i.quantity;
      q.setAttribute('aria-label',`تعداد ${i.name} در یک بسته`);
      q.addEventListener('input',()=>{i.quantity=q.value;updateTotals();});
      qWrap.append(qLabel,q);
      const discount=document.createElement('div');discount.className='kit-component-discount';
      const dLabel=document.createElement('label');dLabel.className='small text-muted';dLabel.textContent='تخفیف هر عدد';
      const dType=document.createElement('select');dType.className='form-select form-select-sm';dType.name=`components[${idx}][discount_type]`;
      for(const [v,t] of [['percent','درصد ٪'],['fixed','ریال']]){const option=document.createElement('option');option.value=v;option.textContent=t;dType.append(option);}
      dType.value=i.discount_type||'percent';
      const dValue=document.createElement('input');dValue.className='form-control form-control-sm';dValue.type='number';dValue.inputMode='numeric';dValue.min='0';dValue.step='1';dValue.required=true;
      dValue.name=`components[${idx}][discount_value]`;dValue.value=i.discount_value??0;dValue.dir='ltr';
      dValue.setAttribute('aria-label',`تخفیف هر عدد ${i.name}`);
      const setLimit=()=>{dValue.max=dType.value==='percent'?'99':String(Math.max(0,Number(i.sell_price)-1));};
      dType.addEventListener('change',()=>{i.discount_type=dType.value;i.discount_value=0;dValue.value='0';setLimit();updateTotals();});
      dValue.addEventListener('input',()=>{i.discount_value=dValue.value;updateTotals();});
      setLimit();discount.append(dLabel,dType,dValue);
      const rm=document.createElement('button');rm.className='btn btn-sm btn-outline-danger';rm.type='button';rm.textContent='حذف';rm.addEventListener('click',()=>{selected.delete(Number(i.id));refresh();});
      row.append(details,hidden,qWrap,discount,rm);list.append(row);
    });
    empty.hidden=selected.size>0;
    updateTotals();
  }
  function updateTotals(){
    const good=quote(),qty=Number(shared.value),pieces=[...selected.values()].reduce((s,i)=>s+(Number(i.quantity)||0),0);
    count.textContent=`${selected.size} تنوع · ${pieces} قطعه در هر بسته`;
    review.textContent=`${nameInput?.value?.trim()||'نام بسته هنوز وارد نشده'} · ${pieces} قطعه در هر بسته · ${Number.isInteger(qty)&&qty>=0?money(qty):'—'} بسته قابل اختصاص · قیمت نهایی: ${good?money(Number(sell.value)):'نامعتبر'} ریال`;
  }
  let timer,controller,revision=0;
  function hide(){results.hidden=true;search.setAttribute('aria-expanded','false');}
  function info(s){results.replaceChildren();const e=document.createElement('div');e.className='p-3 small text-muted';e.textContent=s;results.append(e);results.hidden=false;}
  function choose(i){if(!selected.has(Number(i.id))){selected.set(Number(i.id),{...i,quantity:1,discount_type:'percent',discount_value:0});refresh();}hide();search.value='';search.focus();}
  async function lookup(term){
    const rev=++revision;if(controller)controller.abort();if(term.length<2){hide();return;}
    controller=new AbortController();info('در حال جستجو...');
    try{
      const url=new URL(@json(route('products.bundle.search')),location.origin);url.searchParams.set('q',term);
      const res=await fetch(url,{credentials:'same-origin',headers:{'Accept':'application/json'},signal:controller.signal});
      if(!res.ok)throw Error('search unavailable');const data=await res.json();if(rev!==revision)return;
      results.replaceChildren();const groups=new Map();
      for(const i of data.results||[]){const id=Number(i.product_id);if(!groups.has(id))groups.set(id,{name:i.name,items:[]});groups.get(id).items.push(i);}
      if(!groups.size){info('کالایی پیدا نشد.');return;}
      for(const group of groups.values()){
        const wrap=document.createElement('details');wrap.className='border-bottom p-2';
        const title=document.createElement('summary');title.className='fw-semibold';title.style.cursor='pointer';title.textContent=`${group.name} — انتخاب تنوع (${group.items.length})`;
        wrap.append(title);
        for(const i of group.items){
          const button=document.createElement('button');button.type='button';button.className='btn btn-outline-primary btn-sm d-block w-100 text-end my-1';
          button.disabled=selected.has(Number(i.id));button.textContent=`${i.variant||'تنوع اصلی'} · ${i.code||''} · آزاد: ${i.stock} · ${money(Number(i.sell_price)||0)} ریال`;
          button.addEventListener('click',()=>choose(i));wrap.append(button);
        }
        if(groups.size===1)wrap.open=true;
        results.append(wrap);
      }
      results.hidden=false;search.setAttribute('aria-expanded','true');
    }catch(e){if(e.name!=='AbortError'&&rev===revision)info('جستجو انجام نشد. دسترسی یا اتصال را بررسی کنید.');}
  }
  function syncType(){
    const active=on();section.style.display=active?'':'none';if(structure)structure.style.display=active?'none':'';
    for(const el of [shared,value,type,buy,sell])el.disabled=!active;
    if(submit)submit.textContent=active?'تأیید و ایجاد بسته':'ثبت کالا';
    if(active){for(const id of ['useModels','useDesigns']){const el=document.getElementById(id);if(el?.checked){el.checked=false;el.dispatchEvent(new Event('change'));}}}
    refresh();
  }
  for(const el of form.querySelectorAll('input[name="product_type"]'))el.addEventListener('change',syncType);
  type.addEventListener('change',()=>updateDiscountMode(true));value.addEventListener('input',()=>{normalizeDiscount();updateTotals();});
  shared.addEventListener('input',updateTotals);nameInput?.addEventListener('input',updateTotals);
  buy.addEventListener('input',()=>{buy.value=digits(buy.value);});
  search.addEventListener('input',()=>{clearTimeout(timer);const q=search.value.trim();if(q.length<2){revision++;controller?.abort();hide();return;}timer=setTimeout(()=>lookup(q),250);});
  search.addEventListener('keydown',e=>{if(e.key==='Escape')hide();});
  document.addEventListener('click',e=>{if(!section.contains(e.target))hide();});
  form.addEventListener('submit',e=>{
    if(!on())return;
    if(!selected.size){e.preventDefault();alert('حداقل یک محصول و تنوع انتخاب کنید.');search.focus();return;}
    if(!Number.isSafeInteger(Number(shared.value))||Number(shared.value)<0){e.preventDefault();alert('موجودی اختصاصی باید یک عدد صحیح غیرمنفی باشد.');return;}
    if([...selected.values()].some(i=>!Number.isInteger(Number(i.quantity))||Number(i.quantity)<1||Number(i.quantity)>100000)){e.preventDefault();alert('تعداد هر قطعه در بسته باید یک عدد صحیح بین ۱ و ۱۰۰۰۰۰ باشد.');return;}
    if([...selected.values()].some(i=>Number(i.quantity)*Number(shared.value)>1000000)){e.preventDefault();alert('تعداد تخصیصی یکی از قطعات از حد مجاز بیشتر است.');return;}
    if([...selected.values()].some(i=>!Number.isFinite(perUnitDiscount(i)))){e.preventDefault();alert('تخفیف هر قطعه باید بین ۰ تا ۹۹ درصد یا کمتر از قیمت یک عدد آن به ریال باشد.');return;}
    if(!quote()){e.preventDefault();alert('تخفیف یا قیمت نهایی معتبر نیست.');value.focus();return;}
    if([...selected.values()].some(i=>!Number(i.sell_price)||Number(i.sell_price)<=0)){e.preventDefault();alert('یکی از تنوع‌ها قیمت عادی معتبر ندارد.');return;}
    if(!confirm('بسته با ترکیب و تخفیف نشان داده‌شده ثبت شود؟ موجودی تخصیص‌یافته از موجودی آزاد قطعات کم خواهد شد.'))e.preventDefault();
  });
  updateDiscountMode(false);refresh();syncType();
});
</script>
