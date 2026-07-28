@extends('layouts.app')
@section('title', 'لیست قیمت محصولات آریا گستر')
@section('content')
@php
    $selectedModels = collect($filters['model_list_ids'] ?? [])->map(fn ($id) => (int) $id)->values()->all();
    $selectedProductsPayload = $selectedProducts->map(fn ($product) => [
        'id' => (int) $product->id,
        'name' => $product->name,
        'code' => $product->code,
        'sku' => $product->sku,
        'barcode' => $product->barcode,
        'short_barcode' => $product->short_barcode,
        'category' => $product->category?->name,
    ])->values();
@endphp
<style>
.product-export-page{--primary:#16354F;--blue:#2879A8;--soft:#EEF5F8;--border:#D8E3E9;--text:#16354F;--muted:#667784;direction:rtl;max-width:1500px;margin:0 auto;padding:1rem;color:var(--text);font-family:"Vazirmatn",system-ui,-apple-system,"Segoe UI",Tahoma,Arial,sans-serif}.catalog-hero,.catalog-filter,.catalog-result{background:#fff;border:1px solid var(--border);margin-top:.75rem}.catalog-hero{padding:.8rem 1rem;margin-top:0}.catalog-hero h1{font-size:1.1rem;margin:0;color:var(--primary)}.catalog-hero p{font-size:.78rem;margin:.2rem 0 0;color:var(--muted)}.catalog-filter{padding:.8rem}.form-label{font-size:.75rem;font-weight:700}.catalog-actions{display:flex;gap:.45rem;flex-wrap:wrap;align-items:center;justify-content:flex-end}.btn-catalog-primary{background:var(--primary);border-color:var(--primary);color:#fff}.model-panel{border:1px solid var(--border);background:#fff;max-height:220px;overflow:auto;padding:.45rem}.model-tools{display:flex;gap:.4rem;align-items:center;margin-bottom:.45rem}.model-tools input{font-size:11px}.model-tools button{border:1px solid var(--border);background:#fff;font-size:11px;padding:.25rem .45rem}.model-count{font-size:11px;color:var(--muted);margin-right:auto}.model-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.35rem}.model-check{display:flex;align-items:center;gap:.35rem;border:1px solid #e4edf5;background:#fff;padding:.25rem .35rem;font-size:11.5px;margin:0;cursor:pointer}.model-check:has(input:checked){background:var(--soft);border-color:#9bc7eb}.model-check input{width:13px;height:13px}.model-empty{font-size:12px;color:var(--muted);padding:.5rem}.product-picker{position:relative;border:1px solid var(--border);background:#fff;min-height:126px;padding:.45rem}.product-picker-tools{display:flex;align-items:center;gap:.4rem;margin-bottom:.4rem}.product-picker-tools .product-count{font-size:11px;color:var(--muted);margin-right:auto}.product-picker-tools button{border:1px solid var(--border);background:#fff;font-size:11px;padding:.25rem .45rem}.product-search-results{display:none;position:absolute;z-index:30;top:73px;right:.45rem;left:.45rem;max-height:270px;overflow:auto;background:#fff;border:1px solid #9fb6c4;box-shadow:0 8px 20px rgba(22,53,79,.14)}.product-search-results.is-open{display:block}.product-search-status{padding:.65rem;font-size:12px;color:var(--muted)}.product-search-result{display:block;width:100%;border:0;border-bottom:1px solid #E5EDF1;background:#fff;padding:.5rem .65rem;text-align:right;color:var(--text)}.product-search-result:hover,.product-search-result:focus{background:var(--soft)}.product-search-result:disabled{background:#F6F8F9;color:#82909A;cursor:default}.product-search-result-name{display:block;font-size:12px;font-weight:700}.product-search-result-meta{display:block;margin-top:.15rem;font-size:10.5px;color:var(--muted);direction:rtl;unicode-bidi:plaintext}.product-chips{display:flex;flex-wrap:wrap;gap:.35rem;max-height:118px;overflow:auto}.product-chip{display:inline-flex;align-items:center;gap:.35rem;max-width:100%;padding:.28rem .45rem;background:var(--soft);border:1px solid #B9D3E1;font-size:11px}.product-chip-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.product-chip button{border:0;background:transparent;color:#9B2C2C;font-size:15px;line-height:1;padding:0}.product-picker-empty{font-size:11px;color:var(--muted);padding:.3rem 0}.product-selection-notice{display:none;margin-top:.4rem;padding:.35rem .5rem;font-size:11px;border:1px solid #E9C46A;background:#FFF8E1;color:#6B4F00}.product-selection-notice.is-visible{display:block}.catalog-loading{display:none;position:absolute;inset:0;z-index:5;align-items:center;justify-content:center;background:rgba(255,255,255,.75);color:var(--primary);font-weight:800}.is-loading .catalog-loading{display:flex}.catalog-result{position:relative;overflow:hidden}.clean-price-list{padding:.75rem}.product-price-table{display:block;border:1px solid #C9D7DF;margin-top:1mm;margin-bottom:3mm;background:#fff}.product-header-row{display:grid;grid-template-columns:84% 16%;align-items:center;background:#EDF5F8;border-top:1.2px solid #173A53;border-bottom:.7px solid #C9D7DF}.product-header-main{display:flex;align-items:center;gap:.65rem;padding:7px 10px}.price-list-product-image{width:42px;height:42px;object-fit:contain}.price-list-product-info{min-width:0}.price-list-product-info h3{font-size:10px;font-weight:600;margin:0;color:#183747}.price-list-product-info p{font-size:7.2px;margin:.15rem 0 0;color:#71828D}.product-summary-price{height:100%;display:flex;align-items:center;justify-content:center;padding:7px 8px;color:var(--blue);font-size:9px;font-weight:600;text-align:center;white-space:nowrap;border-right:.6px solid #D7E2E8}.column-header-row,.price-list-detail-row{display:grid;grid-template-columns:46% 38% 16%}.column-header-row{background:#173A53;color:#fff;font-size:9px;font-weight:600;text-align:center;min-height:30px}.column-header-row span{padding:6px 9px;border-left:.6px solid rgba(255,255,255,.18)}.column-header-row span:last-child{border-left:0}.price-list-detail-row>div{background:#fff;border-left:.6px solid #D7E2E8;border-bottom:.5px solid #E1E9ED;line-height:1.65}.price-list-detail-row>div:last-child{border-left:0}.price-list-detail-row:nth-child(even)>div{background:#FAFCFD}.price-list-models{font-size:12px;padding:6px 10px 7px 12px;text-align:right;vertical-align:top;direction:rtl;unicode-bidi:plaintext}.price-list-colors{font-size:11.5px;padding:6px 10px 7px 10px;text-align:right;vertical-align:top;direction:rtl}.price-list-price{font-size:12px;font-weight:700;padding:6px 8px 7px;text-align:center;vertical-align:top;direction:rtl;white-space:nowrap;color:var(--primary)}.price-list-price--range{font-size:11px}.model-token{display:inline-block;white-space:nowrap;direction:ltr;unicode-bidi:isolate}.color-dot{display:inline-block;width:5px;height:5px;border:1px solid #94a3b8;margin-left:3px}.colors-grid{width:100%;table-layout:fixed;border-collapse:collapse;font-size:11px;line-height:1.55}.colors-grid--dense{font-size:10px}.colors-grid td{width:33.33%;border:0!important;padding:1px 3px!important;text-align:right;background:transparent!important}.catalog-empty{padding:2rem;text-align:center;color:var(--muted)}.catalog-pagination{padding:.75rem}.js .catalog-submit{display:none}@media(max-width:992px){.model-grid{grid-template-columns:repeat(2,1fr)}.column-header-row,.price-list-detail-row{grid-template-columns:44% 38% 18%}.product-header-row{grid-template-columns:82% 18%}}@media(max-width:576px){.model-grid{grid-template-columns:1fr}.product-export-page{padding:.5rem}.product-picker,.model-panel{max-height:260px}.product-search-results{position:fixed;top:auto;right:1rem;left:1rem;max-height:45vh}.catalog-actions{justify-content:stretch}.catalog-actions .btn{flex:1 1 100%}.product-header-row{display:block}.product-summary-price{border-right:0;border-top:.6px solid #D7E2E8}.column-header-row{display:none}.price-list-detail-row{display:block;border-bottom:1px solid var(--border)}.price-list-detail-row>div{display:grid;grid-template-columns:115px 1fr;border-left:0}.price-list-detail-row>div:before{content:attr(data-label);font-weight:700;color:var(--muted)}}
.product-search-result{position:relative;padding-left:88px}.product-search-result.is-active{background:#E4F1F7;outline:2px solid #73A9C4;outline-offset:-2px}.product-search-result mark{background:#FFE89A;color:inherit;padding:0 .08rem}.product-search-result-variant{display:block;margin-top:.2rem;font-size:10.5px;color:#35627A}.product-search-result-status{position:absolute;left:.55rem;top:50%;transform:translateY(-50%);border-radius:10px;padding:.14rem .4rem;font-size:10px;font-weight:700;white-space:nowrap}.product-search-result-status--in_stock{background:#E4F5EA;color:#17623A}.product-search-result-status--out_of_stock{background:#FDEAEA;color:#9B2C2C}.product-search-result-status--no_price{background:#FFF4D6;color:#795600}.product-chip-code{color:var(--muted);direction:ltr;unicode-bidi:isolate}.product-search-result:disabled .product-search-result-status{opacity:.65}@media(max-width:576px){.product-search-result{padding-left:.65rem;padding-bottom:1.8rem}.product-search-result-status{top:auto;bottom:.35rem;left:.55rem;transform:none}}
</style>
<main class="product-export-page" data-children-url-template="{{ route('admin.product-exports.categories.children', ['category' => '__ID__']) }}" data-model-lists-url="{{ route('admin.product-exports.model-lists') }}" data-products-search-url="{{ route('admin.product-exports.products.search') }}" data-data-url="{{ route('admin.product-exports.data') }}" data-download-url="{{ route('admin.product-exports.download') }}" data-selected-models='@json($selectedModels)'>
<header class="catalog-hero"><h1>لیست قیمت محصولات آریا گستر</h1><p>فیلترها را انتخاب کنید و خروجی PDF شرکتی و کم‌حجم دریافت کنید.</p></header>
<section class="catalog-filter"><form id="productExportForm" class="row g-2" method="GET" action="{{ route('admin.product-exports.index') }}">
<div class="col-lg-3 col-md-6"><label class="form-label" for="root-category">دسته اصلی</label><select id="root-category" name="root_category_id" class="form-select form-select-sm"><option value="">همه دسته‌ها</option>@foreach($rootCategories as $category)<option value="{{ $category->id }}" @selected(($filters['root_category_id']??'')==$category->id)>{{ $category->name }}</option>@endforeach</select></div>
<div class="col-lg-3 col-md-6"><label class="form-label" for="subcategory">زیردسته</label><select id="subcategory" name="subcategory_id" class="form-select form-select-sm"><option value="">همه زیردسته‌ها</option>@foreach($subcategories as $category)<option value="{{ $category->id }}" @selected(($filters['subcategory_id']??'')==$category->id)>{{ $category->name }}</option>@endforeach</select></div>
<div class="col-lg-3 col-md-6"><label class="form-label" for="model-brand">نوع مدل</label><select id="model-brand" name="model_brand" class="form-select form-select-sm"><option value="">همه انواع مدل</option>@foreach($modelBrands as $brand)<option value="{{ $brand }}" @selected(($filters['model_brand']??'')===$brand)>{{ $brand }}</option>@endforeach</select></div>
<div class="col-lg-3 col-md-6"><label class="form-label" for="stock-status">وضعیت موجودی</label><select id="stock-status" name="stock_status" class="form-select form-select-sm"><option value="all" @selected(($filters['stock_status']??'all')==='all')>همه</option><option value="in_stock" @selected(($filters['stock_status']??'')==='in_stock')>موجود</option><option value="out_of_stock" @selected(($filters['stock_status']??'')==='out_of_stock')>ناموجود</option></select></div>
<div class="w-100"></div>
<div class="col-lg-6"><label class="form-label">مدل‌های انتخابی</label><div class="model-panel" id="modelPanel"><div class="model-tools"><input class="form-control form-control-sm" id="modelSearch" placeholder="جست‌وجوی مدل" type="search"><button type="button" id="selectVisibleModels">انتخاب همه</button><button type="button" id="clearModels">پاک‌کردن</button><span class="model-count" id="modelCount">۰ انتخاب</span></div><div class="model-grid" id="modelGrid"><div class="model-empty">ابتدا نوع مدل را انتخاب کنید.</div></div></div></div>
<div class="col-lg-6"><label class="form-label" for="productSearch">محصولات انتخابی</label><div class="product-picker" id="productPicker"><div class="product-picker-tools"><span class="product-count" id="productCount">۰ انتخاب</span><button type="button" id="clearProducts">پاک‌کردن همه</button></div><input class="form-control form-control-sm" id="productSearch" type="search" autocomplete="off" placeholder="جست‌وجوی نام، کد یا بارکد محصول" aria-controls="productSearchResults" aria-expanded="false"><div class="product-search-results" id="productSearchResults" role="listbox"></div><div class="product-chips mt-2" id="productChips">@forelse($selectedProducts as $product)<span class="product-chip"><span class="product-chip-name">{{ $product->name }}</span></span>@empty<span class="product-picker-empty">محصولی انتخاب نشده است؛ در این حالت همه محصولات مطابق سایر فیلترها نمایش داده می‌شوند.</span>@endforelse</div><div id="productHiddenInputs">@foreach($selectedProducts as $product)<input type="hidden" name="product_ids[]" value="{{ $product->id }}">@endforeach</div><div class="product-selection-notice" id="productSelectionNotice" role="status"></div></div></div>
<div class="w-100"></div>
<div class="col-lg-5 col-md-6 d-flex align-items-center"><label class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="include_without_price" value="1" @checked($filters['include_without_price'] ?? false)> نمایش محصولات بدون قیمت</label></div>
<div class="col-lg-7 col-md-6 catalog-actions"><button class="btn btn-sm btn-catalog-primary catalog-submit" type="submit">اعمال فیلتر</button><a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.product-exports.index') }}">پاک‌کردن فیلترها</a><button class="btn btn-sm btn-catalog-primary" type="button" id="downloadProductsButton">دانلود لیست قیمت PDF</button></div>
</form></section>
<section id="productExportResult" class="catalog-result" aria-live="polite"><div class="catalog-loading">در حال دریافت اطلاعات...</div>@include('product-exports.partials.product-list', ['products'=>$products])</section>
</main>
<script type="application/json" id="selectedProductsData">@json($selectedProductsPayload)</script>
<script>document.documentElement.classList.add('js');document.addEventListener('DOMContentLoaded',()=>{const shell=document.querySelector('.product-export-page'),form=document.getElementById('productExportForm'),result=document.getElementById('productExportResult'),root=document.getElementById('root-category'),child=document.getElementById('subcategory'),brand=document.getElementById('model-brand'),grid=document.getElementById('modelGrid'),search=document.getElementById('modelSearch'),count=document.getElementById('modelCount'),downloadButton=document.getElementById('downloadProductsButton');let aborter,childAborter,modelAborter,timer;let selected=new Set(JSON.parse(shell.dataset.selectedModels||'[]').map(String));const fa=n=>new Intl.NumberFormat('fa-IR').format(n);const params=()=>new URLSearchParams(new FormData(form));const updateCount=()=>count.textContent=`${fa(selected.size)} انتخاب`;const refresh=()=>{clearTimeout(timer);timer=setTimeout(async()=>{aborter?.abort();aborter=new AbortController();result.classList.add('is-loading');try{const qs=params();const r=await fetch(`${shell.dataset.dataUrl}?${qs}`,{headers:{Accept:'text/html','X-Requested-With':'XMLHttpRequest'},signal:aborter.signal});if(!r.ok)throw new Error(r.status);const loading=result.querySelector('.catalog-loading')?.outerHTML||'';result.innerHTML=loading+await r.text();history.replaceState({},'',`${form.action}?${qs}`)}catch(e){if(e.name!=='AbortError')result.insertAdjacentHTML('afterbegin','<div class="alert alert-danger m-3">دریافت اطلاعات با خطا روبه‌رو شد.</div>')}finally{result.classList.remove('is-loading')}},250)};const loadModels=async(clear=true)=>{modelAborter?.abort();modelAborter=new AbortController();if(clear)selected.clear();updateCount();grid.innerHTML='<div class="model-empty">در حال بارگیری...</div>';if(!brand.value){grid.innerHTML='<div class="model-empty">ابتدا نوع مدل را انتخاب کنید.</div>';refresh();return}try{const qs=new URLSearchParams({brand:brand.value});const r=await fetch(`${shell.dataset.modelListsUrl}?${qs}`,{headers:{Accept:'application/json'},signal:modelAborter.signal});const data=await r.json();grid.innerHTML='';if(!data.items.length)grid.innerHTML='<div class="model-empty">مدلی برای این نوع پیدا نشد.</div>';data.items.forEach(item=>{const label=document.createElement('label');label.className='model-check';label.dataset.name=`${item.name||''} ${item.code||''}`.toLowerCase();label.innerHTML=`<input type="checkbox" name="model_list_ids[]" value="${item.id}"><span>${item.name||''}${item.code?' - '+item.code:''}</span>`;const input=label.querySelector('input');input.checked=selected.has(String(item.id));input.addEventListener('change',()=>{input.checked?selected.add(input.value):selected.delete(input.value);updateCount();refresh()});grid.appendChild(label)});updateCount()}catch(e){if(e.name!=='AbortError')grid.innerHTML='<div class="model-empty">خطا در دریافت مدل‌ها</div>'}refresh()};const loadChildren=async()=>{childAborter?.abort();childAborter=new AbortController();child.innerHTML='<option value="">در حال بارگیری...</option>';child.disabled=true;if(!root.value){child.innerHTML='<option value="">همه زیردسته‌ها</option>';child.disabled=false;refresh();return}try{const r=await fetch(shell.dataset.childrenUrlTemplate.replace('__ID__',root.value),{headers:{Accept:'application/json'},signal:childAborter.signal});const data=await r.json();child.innerHTML='<option value="">همه زیردسته‌ها</option>';data.items.forEach(item=>child.add(new Option(item.name,item.id)))}finally{child.disabled=false;refresh()}};root.addEventListener('change',loadChildren);brand.addEventListener('change',()=>loadModels(true));search.addEventListener('input',()=>{const q=search.value.trim().toLowerCase();grid.querySelectorAll('.model-check').forEach(el=>el.style.display=!q||el.dataset.name.includes(q)?'flex':'none')});document.getElementById('selectVisibleModels').addEventListener('click',()=>{grid.querySelectorAll('.model-check').forEach(el=>{if(el.style.display==='none')return;const input=el.querySelector('input');input.checked=true;selected.add(input.value)});updateCount();refresh()});document.getElementById('clearModels').addEventListener('click',()=>{selected.clear();grid.querySelectorAll('input[type=checkbox]').forEach(i=>i.checked=false);updateCount();refresh()});form.addEventListener('change',e=>{if(![root,brand].includes(e.target)&&e.target.type!=='checkbox')refresh()});form.addEventListener('submit',e=>{e.preventDefault();refresh()});downloadButton?.addEventListener('click',()=>location.assign(`${shell.dataset.downloadUrl}?${params()}`));if(brand.value)loadModels(false);else updateCount();});</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const shell = document.querySelector('.product-export-page');
    const form = document.getElementById('productExportForm');
    const picker = document.getElementById('productPicker');
    const searchInput = document.getElementById('productSearch');
    const results = document.getElementById('productSearchResults');
    const chips = document.getElementById('productChips');
    const hiddenInputs = document.getElementById('productHiddenInputs');
    const count = document.getElementById('productCount');
    const clearButton = document.getElementById('clearProducts');
    const notice = document.getElementById('productSelectionNotice');
    const selectedData = document.getElementById('selectedProductsData');
    const selected = new Map(
        JSON.parse(selectedData?.textContent || '[]').map(product => [String(product.id), product])
    );
    const parentFilterNames = new Set([
        'root_category_id',
        'subcategory_id',
        'model_brand',
        'model_list_ids[]',
        'stock_status',
        'include_without_price',
    ]);
    const fa = number => new Intl.NumberFormat('fa-IR').format(number);
    let searchTimer;
    let requestController;
    let requestSequence = 0;
    let noticeTimer;
    let activeResultIndex = -1;
    let resultButtons = [];

    const requestPreview = () => {
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    };

    const closeResults = () => {
        results.classList.remove('is-open');
        searchInput.setAttribute('aria-expanded', 'false');
        searchInput.removeAttribute('aria-activedescendant');
        activeResultIndex = -1;
        resultButtons = [];
    };

    const showStatus = (message, open = true) => {
        activeResultIndex = -1;
        resultButtons = [];
        const status = document.createElement('div');
        status.className = 'product-search-status';
        status.textContent = message;
        results.replaceChildren(status);
        if (open) {
            results.classList.add('is-open');
            searchInput.setAttribute('aria-expanded', 'true');
        }
    };

    const showNotice = message => {
        clearTimeout(noticeTimer);
        notice.textContent = message;
        notice.classList.add('is-visible');
        noticeTimer = setTimeout(() => notice.classList.remove('is-visible'), 7000);
    };

    const renderSelected = () => {
        chips.replaceChildren();
        hiddenInputs.replaceChildren();

        if (selected.size === 0) {
            const empty = document.createElement('span');
            empty.className = 'product-picker-empty';
            empty.textContent = 'محصولی انتخاب نشده است؛ در این حالت همه محصولات مطابق سایر فیلترها نمایش داده می‌شوند.';
            chips.appendChild(empty);
        }

        selected.forEach((product, id) => {
            const chip = document.createElement('span');
            chip.className = 'product-chip';

            const label = document.createElement('span');
            label.className = 'product-chip-name';
            label.textContent = product.name || `محصول ${id}`;
            chip.appendChild(label);

            if (product.code) {
                const code = document.createElement('span');
                code.className = 'product-chip-code';
                code.textContent = `(${product.code})`;
                chip.appendChild(code);
            }

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.setAttribute('aria-label', `حذف ${label.textContent}`);
            remove.textContent = '×';
            remove.addEventListener('click', () => {
                selected.delete(id);
                renderSelected();
                requestPreview();
            });
            chip.appendChild(remove);
            chips.appendChild(chip);

            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'product_ids[]';
            hidden.value = id;
            hiddenInputs.appendChild(hidden);
        });

        count.textContent = `${fa(selected.size)} انتخاب`;
        clearButton.disabled = selected.size === 0;
    };

    const removeInvalidSelections = ids => {
        let removed = 0;
        (ids || []).forEach(id => {
            if (selected.delete(String(id))) {
                removed++;
            }
        });

        if (removed > 0) {
            renderSelected();
            showNotice(`${fa(removed)} محصول انتخاب‌شده با فیلترهای جدید مطابقت نداشتند و حذف شدند.`);
            requestPreview();
        }
    };

    const queryParameters = term => {
        const parameters = new URLSearchParams(new FormData(form));
        parameters.delete('page');
        parameters.set('q', term);
        return parameters;
    };

    const requestProducts = async (term, displayResults) => {
        requestController?.abort();
        requestController = new AbortController();
        const sequence = ++requestSequence;

        if (displayResults) {
            showStatus('در حال جست‌وجو...');
        }

        try {
            const response = await fetch(`${shell.dataset.productsSearchUrl}?${queryParameters(term)}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: requestController.signal,
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const payload = await response.json();
            if (sequence !== requestSequence) {
                return;
            }

            removeInvalidSelections(payload.invalid_selected_ids);
            if (displayResults) {
                renderResults(payload.items || []);
            }
        } catch (error) {
            if (error.name !== 'AbortError' && displayResults) {
                showStatus('دریافت محصولات با خطا روبه‌رو شد. دوباره تلاش کنید.');
            }
        }
    };

    const appendHighlightedText = (element, value, query) => {
        const text = String(value || '');
        const tokens = query.trim().split(/\s+/u).filter(Boolean);
        if (!text || tokens.length === 0) {
            element.appendChild(document.createTextNode(text));
            return;
        }

        const escaped = tokens
            .map(token => token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
            .sort((first, second) => second.length - first.length);
        const expression = new RegExp(`(${escaped.join('|')})`, 'giu');

        text.split(expression).filter(part => part !== '').forEach(part => {
            if (tokens.some(token => token.localeCompare(part, undefined, { sensitivity: 'accent' }) === 0)) {
                const mark = document.createElement('mark');
                mark.textContent = part;
                element.appendChild(mark);
            } else {
                element.appendChild(document.createTextNode(part));
            }
        });
    };

    const appendMetadata = (element, product, query) => {
        const segments = [
            product.code ? `کد: ${product.code}` : null,
            product.barcode ? `بارکد: ${product.barcode}` : null,
            product.category ? `دسته: ${product.category}` : null,
            product.sku ? `SKU: ${product.sku}` : null,
            product.short_barcode ? `کد کوتاه: ${product.short_barcode}` : null,
        ].filter(Boolean);

        segments.forEach((segment, index) => {
            if (index > 0) {
                element.appendChild(document.createTextNode(' | '));
            }
            appendHighlightedText(element, segment, query);
        });
    };

    const selectProduct = product => {
        const id = String(product.id);
        if (selected.has(id)) {
            return;
        }

        if (selected.size >= 200) {
            showNotice('حداکثر ۲۰۰ محصول را می‌توان انتخاب کرد.');
            return;
        }

        selected.set(id, product);
        renderSelected();
        searchInput.value = '';
        closeResults();
        requestPreview();
        searchInput.focus();
    };

    const setActiveResult = index => {
        if (resultButtons.length === 0) {
            return;
        }

        activeResultIndex = (index + resultButtons.length) % resultButtons.length;
        resultButtons.forEach((button, buttonIndex) => {
            const active = buttonIndex === activeResultIndex;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        const activeButton = resultButtons[activeResultIndex];
        searchInput.setAttribute('aria-activedescendant', activeButton.id);
        activeButton.scrollIntoView({ block: 'nearest' });
    };

    const renderResults = products => {
        results.replaceChildren();
        resultButtons = [];
        activeResultIndex = -1;

        if (products.length === 0) {
            showStatus('محصولی پیدا نشد.');
            return;
        }

        products.forEach((product, resultIndex) => {
            const id = String(product.id);
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'product-search-result';
            button.setAttribute('role', 'option');
            button.disabled = selected.has(id);
            button.id = `product-search-result-${resultIndex}`;

            const name = document.createElement('span');
            name.className = 'product-search-result-name';
            appendHighlightedText(name, product.name || `محصول ${id}`, searchInput.value);
            if (selected.has(id)) {
                name.appendChild(document.createTextNode(' — انتخاب شده'));
            }
            button.appendChild(name);

            const metadata = document.createElement('span');
            metadata.className = 'product-search-result-meta';
            appendMetadata(metadata, product, searchInput.value);
            button.appendChild(metadata);

            if (product.matched_variant) {
                const variant = document.createElement('span');
                variant.className = 'product-search-result-variant';
                variant.appendChild(document.createTextNode('تنوع منطبق: '));
                appendHighlightedText(variant, product.matched_variant, searchInput.value);
                button.appendChild(variant);
            }

            const status = document.createElement('span');
            status.className = `product-search-result-status product-search-result-status--${product.availability || 'out_of_stock'}`;
            status.textContent = product.availability_label || 'ناموجود';
            button.appendChild(status);

            button.addEventListener('click', () => selectProduct(product));
            if (!button.disabled) {
                button.addEventListener('mouseenter', () => setActiveResult(resultButtons.indexOf(button)));
                resultButtons.push(button);
            }
            results.appendChild(button);
        });

        results.classList.add('is-open');
        searchInput.setAttribute('aria-expanded', 'true');
    };

    const validateSelections = () => {
        if (selected.size > 0) {
            requestProducts('', false);
        }
    };

    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        const term = searchInput.value.trim();

        if (term.length === 0) {
            requestController?.abort();
            showStatus('نام، کد یا بارکد محصول را وارد کنید.');
            return;
        }

        searchTimer = setTimeout(() => requestProducts(term, true), 275);
    });

    searchInput.addEventListener('keydown', event => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveResult(activeResultIndex + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveResult(activeResultIndex <= 0 ? resultButtons.length - 1 : activeResultIndex - 1);
        } else if (event.key === 'Enter' && activeResultIndex >= 0) {
            event.preventDefault();
            resultButtons[activeResultIndex]?.click();
        } else if (event.key === 'Escape') {
            closeResults();
        } else if (event.key === 'Backspace' && searchInput.value === '' && selected.size > 0) {
            const lastId = Array.from(selected.keys()).at(-1);
            const product = selected.get(lastId);
            selected.delete(lastId);
            renderSelected();
            showNotice(`${product?.name || 'آخرین محصول'} از انتخاب‌ها حذف شد.`);
            requestPreview();
        }
    });

    searchInput.addEventListener('focus', () => {
        if (searchInput.value.trim() === '') {
            showStatus('نام، کد یا بارکد محصول را وارد کنید.');
        }
    });

    clearButton.addEventListener('click', () => {
        if (selected.size === 0) {
            return;
        }

        selected.clear();
        renderSelected();
        closeResults();
        requestPreview();
    });

    form.addEventListener('change', event => {
        if (!parentFilterNames.has(event.target.name)) {
            return;
        }

        requestController?.abort();
        requestSequence++;
        closeResults();
        requestPreview();
        setTimeout(validateSelections, 0);
    });

    ['selectVisibleModels', 'clearModels'].forEach(id => {
        document.getElementById(id)?.addEventListener('click', () => {
            requestController?.abort();
            requestSequence++;
            closeResults();
            setTimeout(validateSelections, 0);
        });
    });

    const modelGrid = document.getElementById('modelGrid');
    let modelMutationTimer;
    new MutationObserver(() => {
        clearTimeout(modelMutationTimer);
        modelMutationTimer = setTimeout(validateSelections, 50);
    }).observe(modelGrid, { childList: true, subtree: true });

    document.addEventListener('click', event => {
        if (!picker.contains(event.target)) {
            closeResults();
        }
    });

    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            renderSelected();
            validateSelections();
        }
    });

    renderSelected();
    validateSelections();
});
</script>
@endsection
