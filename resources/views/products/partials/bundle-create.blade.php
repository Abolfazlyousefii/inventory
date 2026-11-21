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
<link rel="stylesheet" href="{{ asset('css/bundle-management.css') }}?v=20261121d">
<div class="col-12" dir="rtl">
  <div class="card-soft bundle-shell">
    <div class="section-head bundle-topbar">
      <div>
        <div class="section-title">نوع کالا</div>
        <div class="muted">همین‌جا مشخص کنید این کالا عادی است یا یک بسته چندمحصولی.</div>
      </div>
    </div>
    <div class="p-3 pt-0">
      <div class="bundle-helper-grid bundle-helper-grid-2">
        <label class="kit-choice bundle-choice-card">
          <input name="product_type" type="radio" value="regular" @checked(old('product_type','regular') === 'regular')>
          <span class="bundle-choice-copy">
            <strong>کالای عادی</strong>
            <small>بدون مراحل بسته‌سازی</small>
          </span>
        </label>
        <label class="kit-choice bundle-choice-card">
          <input name="product_type" type="radio" value="bundle" @checked(old('product_type') === 'bundle')>
          <span class="bundle-choice-copy">
            <strong>بسته ترکیبی</strong>
            <small>چند قطعه با یک قیمت نهایی</small>
          </span>
        </label>
      </div>
    </div>
  </div>
</div>
<div class="col-12" id="bundleCreateSection" dir="rtl" style="display:none">
  <div class="card-soft bundle-shell">
    <div class="section-head bundle-topbar">
      <div>
        <div class="section-title">ساخت بسته ترکیبی</div>
        <div class="muted">۴ مرحله کوتاه برای ساخت، قیمت‌گذاری و تعیین سهمیه.</div>
      </div>
      <span class="chip">یک بسته = یک کالا در سبد خرید</span>
    </div>
    <div class="p-3 pt-0">
      <input type="hidden" name="bundle_stock_mode" value="automatic">
      <input type="hidden" name="bundle_quantity_mode" value="shared">

      <nav class="kit-steps bundle-step-grid mb-3" aria-label="مراحل ایجاد بسته">
        <button type="button" class="btn btn-outline-primary btn-sm" data-kit-go="0"><span>۱</span> اطلاعات</button>
        <button type="button" class="btn btn-outline-primary btn-sm" data-kit-go="1"><span>۲</span> قطعات</button>
        <button type="button" class="btn btn-outline-primary btn-sm" data-kit-go="2"><span>۳</span> قیمت</button>
        <button type="button" class="btn btn-outline-primary btn-sm" data-kit-go="3"><span>۴</span> تأیید</button>
      </nav>

      <div id="bundleStickySummary" class="kit-preview kit-sticky mb-3 small" role="status" aria-live="polite"></div>

      <div data-kit-step="0" class="kit-step bundle-stage-card">
        <div class="bundle-stage-head mb-2">
          <h5 class="mb-0">اطلاعات بسته</h5>
        </div>
        <div id="bundleBaseFieldsMount"></div>
      </div>

      <div data-kit-step="1" class="kit-step bundle-stage-card" hidden>
        <div class="bundle-stage-head d-flex justify-content-between flex-wrap gap-2 align-items-start">
          <div>
            <h5 class="mb-1">انتخاب قطعات بسته</h5>
            <p class="small text-muted mb-0">محصول را جستجو کنید، تنوع‌ها را انتخاب کنید و در صورت نیاز تعداد گروهی بدهید.</p>
          </div>
          <div class="kit-chip-row">
            <span class="chip chip-soft">حداقل ۱ تنوع</span>
            <span class="chip chip-soft">پشتیبانی از انتخاب گروهی</span>
          </div>
        </div>
        <div class="kit-search-panel mb-3 position-relative">
          <label class="form-label fw-bold" for="bundleCreateSearch">جستجو و افزودن محصول</label>
          <input id="bundleCreateSearch" class="form-control" type="search" autocomplete="off" placeholder="نام محصول، کد یا SKU" aria-expanded="false" aria-controls="bundleCreateResults">
          <div id="bundleCreateResults" class="kit-result" role="group" aria-label="انتخاب محصولات و تنوع‌ها" hidden></div>
          <div class="d-flex gap-2 align-items-center mt-3 flex-wrap">
            <label for="bundleStockFilter" class="small text-muted mb-0">فیلتر جستجو</label>
            <select id="bundleStockFilter" class="form-select form-select-sm" style="max-width:230px">
              <option value="all">تمام تنوع‌های فعال</option>
              <option value="available">فقط دارای موجودی آزاد</option>
            </select>
          </div>
        </div>
        <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
          <strong>اقلام بسته</strong>
          <small id="bundleCreateCount" class="text-muted"></small>
        </div>
        <div class="kit-selection-tools bundle-toolbar d-flex flex-wrap align-items-end gap-2 mb-2">
          <label class="small">جستجو در انتخاب‌شده‌ها<input id="bundleSelectedSearch" type="search" class="form-control form-control-sm" placeholder="نام، مدل یا کد"></label>
          <label class="small">تعداد گروهی<input id="bundleBulkQuantity" class="form-control form-control-sm" type="number" min="1" max="100000" value="10" style="max-width:110px"></label>
          <button type="button" class="btn btn-sm btn-outline-primary" id="bundleBulkApply">اعمال</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="bundleBulkUndo" disabled>برگشت</button>
          <button type="button" class="btn btn-sm btn-outline-danger" id="bundleBulkRemove">حذف</button>
        </div>
        <div id="bundleCreateItems" aria-live="polite"></div>
        <div class="d-flex justify-content-between align-items-center mb-2 bundle-pagination"><button type="button" class="btn btn-sm btn-outline-secondary" id="bundleSelectedPrev">قبلی</button><span id="bundleSelectedPage" class="small text-muted"></span><button type="button" class="btn btn-sm btn-outline-secondary" id="bundleSelectedNext">بعدی</button></div>
        <div id="bundleCreateEmpty" class="bundle-empty-state">هنوز محصولی انتخاب نشده است.</div>
      </div>

      <div data-kit-step="2" class="kit-step bundle-stage-card" hidden>
        <div class="bundle-stage-head d-flex justify-content-between flex-wrap gap-2 align-items-start">
          <div>
            <h5 class="mb-1">قیمت‌گذاری و تخفیف بسته</h5>
            <p class="small text-muted mb-0">قیمت‌ها به‌صورت زنده محاسبه می‌شوند و قیمت نهایی برای سایت هم استفاده می‌شود.</p>
          </div>
        </div>
        <div class="bundle-summary-grid mb-3">
          <div class="bundle-mini-card accent"><small>قیمت عادی مجموع اقلام</small><strong id="bundleOriginalPrice" class="fs-5">۰</strong><span class="text-muted small">ریال</span></div>
          <div class="bundle-mini-card accent"><small>پس از تخفیف قطعات</small><strong id="bundleComponentNetPrice" class="fs-5">۰</strong><span class="text-muted small">ریال</span></div>
          <div class="bundle-mini-card accent success"><small>قیمت نهایی بسته</small><strong id="bundleFinalPrice" class="fs-4 text-success">۰</strong><span class="text-muted small">ریال</span><div class="text-muted small mt-1">صرفه‌جویی: <strong id="bundleSavings">۰</strong> <span id="bundlePercentPreview"></span></div></div>
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-bold" for="bundleDiscountType">روش تعیین تخفیف کلی بسته</label>
            <select id="bundleDiscountType" class="form-select" name="bundle_discount_type">
              <option value="percent" @selected(old('bundle_discount_type','percent') === 'percent')>درصد تخفیف</option>
              <option value="final" @selected(old('bundle_discount_type') === 'final')>قیمت نهایی بسته</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" for="bundleDiscountValue" id="bundleDiscountValueLabel">درصد تخفیف (۰ تا ۹۹)</label>
            <div class="input-group" dir="ltr"><input data-rial data-rial-kind-source="#bundleDiscountType" id="bundleDiscountValue" type="text" class="form-control" inputmode="numeric" autocomplete="off" value="{{ old('bundle_discount_value', 0) }}" aria-describedby="bundleDiscountHelp bundleDiscountCheck"><span class="input-group-text" id="bundleDiscountUnit">٪</span></div>
            <input type="hidden" name="bundle_discount_value" id="bundleDiscountValueRaw" value="{{ old('bundle_discount_value', 0) }}">
            <div id="bundleDiscountCheck" class="small mt-1" role="status" aria-live="polite"></div>
            <div id="bundleDiscountHelp" class="form-text">مقدار معتبر را وارد کنید؛ پیش‌نمایش بالا فوراً به‌روزرسانی می‌شود.</div>
          </div>
        </div>
        <input id="bundleSellPrice" type="hidden" name="sell_price" value="{{ old('sell_price') }}">
        <div class="mt-3"><label for="bundleBuyPrice" class="form-label">هزینه خرید بسته (اختیاری)</label><input data-rial id="bundleBuyPrice" class="form-control" style="max-width:230px" type="text" inputmode="numeric" autocomplete="off" name="buy_price" value="{{ old('buy_price') }}"></div>
      </div>

      <div data-kit-step="3" class="kit-step bundle-stage-card" hidden>
        <div class="bundle-stage-head d-flex justify-content-between flex-wrap gap-2 align-items-start">
          <div>
            <h5 class="mb-1">موجودی و تأیید نهایی</h5>
            <p class="small text-muted mb-0">تعداد بسته قابل فروش را مشخص کنید و خلاصه نهایی را قبل از ثبت بررسی کنید.</p>
          </div>
        </div>
        <div class="bundle-helper-grid bundle-helper-grid-2 align-items-start">
          <div class="rounded border p-3" style="background:#f8fafc">
            <label for="bundleSharedQuantity" class="form-label fw-bold">تعداد بسته قابل عرضه</label>
            <input id="bundleSharedQuantity" class="form-control" style="max-width:220px" type="text" inputmode="numeric" pattern="[0-9۰-۹٠-٩]+" name="bundle_shared_quantity" value="{{ old('bundle_shared_quantity',0) }}">
            <div class="form-text">همه قطعات با همین تعداد تخصیص داده می‌شوند.</div>
            <div id="bundleShortage" class="small mt-2" role="status"></div>
          </div>
          <div class="kit-preview h-100">
            <strong>خلاصه نهایی</strong>
            <div id="bundleCreateReview" class="small text-muted mt-2"></div>
          </div>
        </div>
        <div id="bundleCreateActionsMount" class="mt-3"></div>
      </div>

      <div class="d-flex justify-content-between gap-2 mt-3 kit-step-actions">
        <button type="button" class="btn btn-outline-secondary" id="bundleStepBack">مرحله قبل</button>
        <button type="button" class="btn btn-primary" id="bundleStepNext">مرحله بعد</button>
      </div>
    </div>
  </div>
</div>
@once
<script src="{{ asset('js/bundle-form-payload.js') }}?v=20261024"></script>
@endonce
<script>
document.addEventListener('DOMContentLoaded', () => {
  const form=document.getElementById('productCreateForm'), section=document.getElementById('bundleCreateSection');
  if(!form || !section) return;
  const structure=document.getElementById('variantStructureCard'), search=document.getElementById('bundleCreateSearch');
  const baseFields=document.getElementById('productBaseInfoCard'), actions=document.getElementById('productCreateActions');
  const baseMount=document.getElementById('bundleBaseFieldsMount'), actionsMount=document.getElementById('bundleCreateActionsMount');
  const regularHint=document.getElementById('productCreateRegularHint');
  const results=document.getElementById('bundleCreateResults'),list=document.getElementById('bundleCreateItems'),empty=document.getElementById('bundleCreateEmpty');
  const count=document.getElementById('bundleCreateCount'),review=document.getElementById('bundleCreateReview');
  const shared=document.getElementById('bundleSharedQuantity'),type=document.getElementById('bundleDiscountType');
  const value=document.getElementById('bundleDiscountValue'),rawValue=document.getElementById('bundleDiscountValueRaw'),sell=document.getElementById('bundleSellPrice'),buy=document.getElementById('bundleBuyPrice');
  const checker=document.getElementById('bundleDiscountCheck'),unit=document.getElementById('bundleDiscountUnit');
  const submit=form.querySelector('button[type="submit"]'),nameInput=form.querySelector('input[name="name"]');
  const selected=new Map(), checked=new Set();
  const selectedSearch=document.getElementById('bundleSelectedSearch'),stockFilter=document.getElementById('bundleStockFilter');
  let page=0,bulkBefore=null,step=0;const pageSize=25;
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
  const normalizeInteger=s=>String(s??'').replace(/[۰-۹]/g,c=>String(c.charCodeAt(0)-1776)).replace(/[٠-٩]/g,c=>String(c.charCodeAt(0)-1632));
  const digits=s=>normalizeInteger(s).replace(/[^0-9]/g,'');
  const discountNumber=()=>Number(rawValue.value);
  function normalizeDiscount(){
    const d=digits(value.value);rawValue.value=d;
    value.value=type.value==='final'&&d?Number(d).toLocaleString('en-US'):d;
  }
  const final=()=>{
    const total=discountedBase(),v=discountNumber();
    if(rawValue.value===''||!Number.isFinite(v)||v<0)return NaN;
    if(type.value==='percent')return v>99 ? NaN : total-Math.round(total*v/100);
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
    const all=[...selected.values()],query=selectedSearch.value.trim().toLowerCase();
    const filtered=all.map((item,idx)=>({item,idx})).filter(({item})=>[item.name,item.variant,item.code].join(' ').toLowerCase().includes(query));
    const pages=Math.max(1,Math.ceil(filtered.length/pageSize));page=Math.min(page,pages-1);
    document.getElementById('bundleSelectedPage').textContent=`صفحه ${page+1} از ${pages} · ${filtered.length} مورد`;
    document.getElementById('bundleSelectedPrev').disabled=page===0;
    document.getElementById('bundleSelectedNext').disabled=page>=pages-1;
    const shown=new Set(filtered.slice(page*pageSize,(page+1)*pageSize).map(x=>x.idx));
    all.forEach((i,idx)=>{
      if(!shown.has(idx)) {
        for(const [key,val] of Object.entries({variant_id:i.id,quantity:i.quantity,discount_type:i.discount_type||'percent',discount_value:i.discount_value??0})) {
          const hidden=document.createElement('input');hidden.type='hidden';hidden.name=`components[${idx}][${key}]`;hidden.value=val;list.append(hidden);
        }
        return;
      }
      const row=document.createElement('div');row.className='kit-selected';
      const details=document.createElement('div');details.className='flex-grow-1';
      const title=document.createElement('strong');title.textContent=i.name;
      const sub=document.createElement('div');sub.className='small text-muted';sub.textContent=`تنوع: ${i.variant||'اصلی'} · ${i.code||''} · قیمت عادی: ${money(Number(i.sell_price)||0)} ریال · موجودی آزاد: ${i.stock??'نامشخص'}`;
      const pick=document.createElement('input');pick.type='checkbox';pick.checked=checked.has(Number(i.id));
      pick.setAttribute('aria-label',`انتخاب ${i.name} برای عملیات گروهی`);
      pick.addEventListener('change',()=>{if(pick.checked)checked.add(Number(i.id));else checked.delete(Number(i.id));});
      details.append(title,sub);
      const hidden=document.createElement('input');hidden.type='hidden';hidden.name=`components[${idx}][variant_id]`;hidden.value=i.id;
      const qWrap=document.createElement('div');qWrap.className='d-flex align-items-center gap-2';
      const qLabel=document.createElement('label');qLabel.className='small text-muted';qLabel.textContent='تعداد در هر بسته';
      const q=document.createElement('input');q.type='text';q.inputMode='numeric';q.pattern='[0-9]+';q.required=true;
      q.className='form-control';q.style.width='100px';q.dir='ltr';q.name=`components[${idx}][quantity]`;q.value=i.quantity;
      q.setAttribute('aria-label',`تعداد ${i.name} در یک بسته`);
      q.addEventListener('input',()=>{const clean=normalizeInteger(q.value);if(/^[0-9]*$/.test(clean))q.value=clean;i.quantity=q.value;updateTotals();});
      qWrap.append(qLabel,q);
      const discount=document.createElement('div');discount.className='kit-component-discount';
      const dLabel=document.createElement('label');dLabel.className='small text-muted';dLabel.textContent='تخفیف هر عدد';
      const dType=document.createElement('select');dType.className='form-select form-select-sm';dType.name=`components[${idx}][discount_type]`;
      for(const [v,t] of [['percent','درصد ٪'],['fixed','ریال']]){const option=document.createElement('option');option.value=v;option.textContent=t;dType.append(option);}
      dType.value=i.discount_type||'percent';
      const dValue=document.createElement('input');dValue.className='form-control form-control-sm';dValue.type='text';dValue.dataset.rial='';discount.dataset.rialDiscount='';dValue.inputMode='numeric';dValue.min='0';dValue.step='1';dValue.required=true;
      dValue.name=`components[${idx}][discount_value]`;dValue.value=i.discount_value??0;dValue.dir='ltr';
      dValue.setAttribute('aria-label',`تخفیف هر عدد ${i.name}`);
      const setLimit=()=>{dValue.max=dType.value==='percent'?'99':String(Math.max(0,Number(i.sell_price)-1));};
      dType.addEventListener('change',()=>{i.discount_type=dType.value;i.discount_value=0;dValue.value='0';setLimit();updateTotals();});
      dValue.addEventListener('input',()=>{i.discount_value=RialInput.raw(dValue.value);updateTotals();});
      setLimit();discount.append(dLabel,dType,dValue);
      const rm=document.createElement('button');rm.className='btn btn-sm btn-outline-danger';rm.type='button';rm.textContent='حذف';rm.addEventListener('click',()=>{selected.delete(Number(i.id));refresh();});
      const rowPrice=document.createElement('span');rowPrice.className='small text-muted';
      const updateRow=()=>{const unit=Number(i.sell_price)-perUnitDiscount(i);rowPrice.textContent=Number.isFinite(unit)?`ردیف: ${money(unit*Number(i.quantity))} ریال`:'قیمت نامعتبر';};
      q.addEventListener('input',updateRow);dValue.addEventListener('input',updateRow);dType.addEventListener('change',updateRow);updateRow();
      row.append(pick,details,hidden,qWrap,discount,rowPrice,rm);list.append(row);
    });
    empty.hidden=selected.size>0;
    syncSelection();
    updateTotals();
  }
  function updateTotals(){
    const good=quote(),qty=Number(shared.value),pieces=[...selected.values()].reduce((s,i)=>s+(Number(i.quantity)||0),0);
    count.textContent=`${selected.size} تنوع · ${pieces} قطعه در هر بسته`;
    const summary=`${nameInput?.value?.trim()||'نام بسته هنوز وارد نشده'} · ${selected.size} مدل / ${pieces} قطعه در بسته · ${Number.isInteger(qty)&&qty>=0?money(qty):'—'} بسته قابل فروش · قیمت نهایی: ${good?money(Number(sell.value)):'نامعتبر'} ریال`;
    review.textContent=summary;document.getElementById('bundleStickySummary').textContent=summary;
    const shortages=[...selected.values()].filter(i=>qty>0&&i.stock!==null&&Number(i.stock)<qty*Number(i.quantity));
    const warn=document.getElementById('bundleShortage');warn.textContent=shortages.length?'کسری موجودی: '+shortages.slice(0,10).map(i=>`${i.name} / ${i.variant}: نیاز ${money(qty*Number(i.quantity))}، آزاد ${money(i.stock)}، کمبود ${money(qty*Number(i.quantity)-Number(i.stock))}`).join(' | '):'برای موجودی صفر هیچ قطعه‌ای از انبار کسر نمی‌شود.';
    warn.className=shortages.length?'small mb-2 text-danger':'small mb-2 text-muted';
  }
  let timer,controller,revision=0;
  let selectionControls=[];
  function hide(){results.hidden=true;search.setAttribute('aria-expanded','false');}

	function info(s) {
		selectionControls = [];
		results.replaceChildren();
		search.setAttribute('aria-expanded', 'true');

		const e = document.createElement('div');
		e.className = 'p-3 small text-muted';
		e.textContent = s;

		results.append(e);
		results.hidden = false;
	}

	function syncSelection() {
		for (const { box, items, status } of selectionControls) {
			const n = items.filter(i => selected.has(Number(i.id))).length;

			box.checked = n === items.length && n > 0;
			box.indeterminate = n > 0 && n < items.length;

			if (status) {
				status.textContent = `${money(n)} از ${money(items.length)} انتخاب شده`;
			}
		}
	}

	function selectItems(items, checked) {
		for (const i of items) {
			const id = Number(i.id);

			if (checked) {
				if (!selected.has(id)) {
					selected.set(id, {
						...i,
						quantity: 1,
						discount_type: 'percent',
						discount_value: 0
					});
				}
			} else {
				selected.delete(id);
			}
		}

		refresh();
	}

	function choose(i) {
		const id = Number(i.id);

		if (!selected.has(id)) {
			selected.set(id, {
				...i,
				quantity: 1,
				discount_type: 'percent',
				discount_value: 0
			});

			refresh();
		}

		hide();
		search.value = '';
		search.focus();
	}

	function selectionLabel(items, labelText) {
		const label = document.createElement('label');
		label.className = 'kit-select';

		const box = document.createElement('input');
		box.type = 'checkbox';
		box.setAttribute('aria-label', labelText);

		box.addEventListener('change', () => {
			selectItems(items, box.checked);
		});

		label.append(box);

		const control = { box, items };
		selectionControls.push(control);

		return { label, control };
	}

	async function lookup(term){
    const rev=++revision;if(controller)controller.abort();if(term.length<2){hide();return;}
    controller=new AbortController();info('در حال جستجو...');
    try{
      const url=new URL(@json(route('products.bundle.search')),location.origin);url.searchParams.set('q',term);url.searchParams.set('complete_products','1');url.searchParams.set('stock_filter',stockFilter.value);
      const res=await fetch(url,{credentials:'same-origin',headers:{'Accept':'application/json'},signal:controller.signal});
      if(!res.ok)throw Error('search unavailable');const data=await res.json();if(rev!==revision)return;
      results.replaceChildren();const groups=new Map();
      for(const i of data.results||[]){const id=Number(i.product_id);if(!groups.has(id))groups.set(id,{name:i.name,items:[]});groups.get(id).items.push(i);}
      if(!groups.size){info('کالایی پیدا نشد.');return;}
      selectionControls=[];
      for(const [productId,group] of groups){
        const wrap=document.createElement('div');wrap.className='kit-product';
        const head=document.createElement('div');head.className='kit-product-head';
        const {label,control}=selectionLabel(group.items,`انتخاب همه تنوع‌های ${group.name}`);
        const caption=document.createElement('span');caption.textContent='انتخاب همه';
        const status=document.createElement('span');status.className='kit-group-status';control.status=status;
        label.append(caption,status);
        const toggle=document.createElement('button');toggle.type='button';toggle.className='kit-product-toggle';
        toggle.textContent=`${group.name} · ${money(group.items.length)} تنوع فعال`;
        const variants=document.createElement('div');variants.id=`kit-variants-${productId}`;
        variants.hidden=groups.size!==1;toggle.setAttribute('aria-controls',variants.id);toggle.setAttribute('aria-expanded',String(!variants.hidden));
        toggle.addEventListener('click',()=>{variants.hidden=!variants.hidden;toggle.setAttribute('aria-expanded',String(!variants.hidden));});
        head.append(label,toggle);wrap.append(head,variants);
        for(const i of group.items){
          const {label}=selectionLabel([i],`انتخاب ${i.variant||'تنوع اصلی'}`);
          const copy=document.createElement('span');copy.className='kit-variant-copy';
          const title=document.createElement('strong');title.textContent=i.variant||'تنوع اصلی';
          const meta=document.createElement('span');meta.className='kit-variant-meta';
          meta.textContent=`${i.code||''} · موجودی آزاد: ${money(i.stock)} · ${money(Number(i.sell_price)||0)} ریال`;
          copy.append(title,meta);label.append(copy);variants.append(label);
        }
        results.append(wrap);
      }
      syncSelection();
      results.hidden=false;search.setAttribute('aria-expanded','true');
    }catch(e){if(e.name!=='AbortError'&&rev===revision)info('جستجو انجام نشد. دسترسی یا اتصال را بررسی کنید.');}
  }
  function showStep(next) {
    step=Math.min(3,Math.max(0,next));
    section.querySelectorAll('[data-kit-step]').forEach(el=>{el.hidden=Number(el.dataset.kitStep)!==step;});
    section.querySelectorAll('[data-kit-go]').forEach(el=>{el.classList.toggle('btn-primary',Number(el.dataset.kitGo)===step);el.setAttribute('aria-current',Number(el.dataset.kitGo)===step?'step':'false');});
    document.getElementById('bundleStepBack').disabled=step===0;
    document.getElementById('bundleStepNext').hidden=step===3;
    if(step===3)updateTotals();
  }
  section.querySelectorAll('[data-kit-go]').forEach(el=>el.addEventListener('click',()=>showStep(Number(el.dataset.kitGo))));
  document.getElementById('bundleStepBack').addEventListener('click',()=>showStep(step-1));
  document.getElementById('bundleStepNext').addEventListener('click',()=>showStep(step+1));
  selectedSearch.addEventListener('input',()=>{page=0;refresh();});
  document.getElementById('bundleSelectedPrev').addEventListener('click',()=>{page--;refresh();});
  document.getElementById('bundleSelectedNext').addEventListener('click',()=>{page++;refresh();});
  document.getElementById('bundleBulkApply').addEventListener('click',()=>{
    const qty=Number(document.getElementById('bundleBulkQuantity').value);
    if(!Number.isSafeInteger(qty)||qty<1||qty>100000){alert('تعداد گروهی باید بین ۱ تا ۱۰۰۰۰۰ باشد.');return;}
    bulkBefore=new Map([...selected.values()].map(i=>[Number(i.id),i.quantity]));
    for(const id of checked)if(selected.has(id))selected.get(id).quantity=qty;
    document.getElementById('bundleBulkUndo').disabled=false;refresh();
  });
  document.getElementById('bundleBulkUndo').addEventListener('click',()=>{
    if(bulkBefore){for(const [id,qty] of bulkBefore)if(selected.has(id))selected.get(id).quantity=qty;bulkBefore=null;refresh();}
    document.getElementById('bundleBulkUndo').disabled=true;
  });
  document.getElementById('bundleBulkRemove').addEventListener('click',()=>{for(const id of checked)selected.delete(id);checked.clear();refresh();});
  stockFilter.addEventListener('change',()=>{if(search.value.trim().length>=2)lookup(search.value.trim());});
  function syncType(){
    const active=on();
    // Move existing form elements, never clone them: IDs, values and listeners stay intact.
    if(active){
      if(baseFields && baseMount && baseFields.parentElement!==baseMount)baseMount.append(baseFields);
      if(actions && actionsMount && actions.parentElement!==actionsMount)actionsMount.append(actions);
    }else{
      if(baseFields && structure && baseFields.parentElement!==form)structure.before(baseFields);
      if(actions && structure && actions.parentElement!==form)structure.after(actions);
    }
    section.style.display=active?'':'none';if(structure)structure.style.display=active?'none':'';
    if(regularHint)regularHint.hidden=active;
    for(const el of [shared,value,type,buy,sell])el.disabled=!active;
    if(submit)submit.textContent=active?'تأیید و ایجاد بسته':'ثبت کالا';
    if(active){for(const id of ['useModels','useDesigns']){const el=document.getElementById(id);if(el?.checked){el.checked=false;el.dispatchEvent(new Event('change'));}}}
    refresh();
  }
  for(const el of form.querySelectorAll('input[name="product_type"]'))el.addEventListener('change',syncType);
  type.addEventListener('change',()=>updateDiscountMode(true));value.addEventListener('input',()=>{normalizeDiscount();updateTotals();});
  shared.addEventListener('input',()=>{const clean=normalizeInteger(shared.value);if(/^[0-9]*$/.test(clean))shared.value=clean;updateTotals();});nameInput?.addEventListener('input',updateTotals);
  buy.addEventListener('input',()=>{buy.value=digits(buy.value);});
  search.addEventListener('input',()=>{clearTimeout(timer);const q=search.value.trim();revision++;controller?.abort();hide();if(q.length<2){return;}timer=setTimeout(()=>lookup(q),250);});
  search.addEventListener('focus',()=>{if(search.value.trim().length>=2&&!results.hidden)return;if(search.value.trim().length>=2)lookup(search.value.trim());});
  results.addEventListener('keydown',e=>{if(e.key==='Escape'){search.focus();hide();}});
  search.addEventListener('keydown',e=>{if(e.key==='Escape')hide();});
  document.addEventListener('click',e=>{if(!section.contains(e.target))hide();});
  window.BundleFormPayload.attach(form, ['components']);
  form.addEventListener('invalid',e=>{if(on()&&section.contains(e.target)){const pane=e.target.closest('[data-kit-step]');if(pane)showStep(Number(pane.dataset.kitStep));}},true);
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
  updateDiscountMode(false);refresh();syncType();showStep(0);
});
</script>
