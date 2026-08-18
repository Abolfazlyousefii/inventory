@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">مدیریت وضعیت فروش</h4>
    <div class="d-flex gap-2"><a href="{{ route('product-deactivation-documents.bulk.create') }}" class="btn btn-outline-primary">عملیات گروهی</a><a href="{{ route('product-deactivation-documents.index') }}" class="btn btn-outline-secondary">تاریخچه وضعیت</a></div>
</div>

@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<form method="POST" action="{{ route('product-deactivation-documents.store') }}" class="card">
    @csrf
    <div class="card-body">
        <label class="form-label fw-bold">جستجوی نام یا کد کالا</label>
        <input id="productSearch" class="form-control" autocomplete="off" placeholder="حداقل دو نویسه وارد کنید">
        <div id="searchResults" class="list-group mt-1"></div>
        <input type="hidden" name="product_id" id="productId" value="{{ old('product_id', $selectedProduct?->id) }}">
        @if(request()->filled('return_to'))<input type="hidden" name="return_to_edit" value="1">@endif

        <div id="selectedProduct" class="border rounded p-3 my-3 {{ $selectedProduct ? '' : 'd-none' }}">
            <div class="fw-bold" id="selectedName">{{ $selectedProduct?->name }}</div>
            <div class="small text-muted mt-1" id="selectedMeta">
                @if($selectedProduct)کد: {{ $selectedProduct->code ?: $selectedProduct->sku ?: '—' }} | دسته‌بندی: {{ $selectedProduct->category?->name ?? '—' }}@endif
            </div>
            @php
                $selectedStructural = (int) ($selectedProduct?->structural_variants_count ?? 0);
                $selectedSellable = (int) ($selectedProduct?->sellable_variants_count ?? 0);
                $selectedComputedStatus = $selectedSellable === 0 ? 'inactive' : (($selectedStructural > 0 && $selectedSellable >= $selectedStructural) ? 'active' : 'partial');
                $selectedStatusLabel = ['active' => 'فعال', 'partial' => 'نیمه‌فعال', 'inactive' => 'غیرفعال'][$selectedComputedStatus];
                $selectedStatusClass = ['active' => 'text-bg-success', 'partial' => 'text-bg-warning', 'inactive' => 'text-bg-secondary'][$selectedComputedStatus];
                $selectedInconsistent = $selectedProduct && ((bool) $selectedProduct->is_sellable !== ($selectedSellable > 0));
            @endphp
            <div class="mt-2">وضعیت واقعی فروش: <span id="statusBadge" class="badge {{ $selectedStatusClass }}">{{ $selectedStatusLabel }}</span> <span id="variantSummary" class="small text-muted">@if($selectedProduct){{ $selectedSellable }} از {{ $selectedStructural }} تنوع قابل فروش@endif</span> <span id="statusWarning" class="badge text-bg-danger {{ $selectedInconsistent ? '' : 'd-none' }}">وضعیت تجمیعی ناسازگار</span></div>
        </div>

        <div id="operationFields" class="{{ $selectedProduct ? '' : 'd-none' }}">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold">عملیات</label>
                    <div><label class="me-3"><input type="radio" name="action_type" value="deactivate" @checked(old('action_type', $selectedProduct?->is_sellable ? 'deactivate' : 'activate') === 'deactivate')> غیرفعال‌سازی</label><label><input type="radio" name="action_type" value="activate" @checked(old('action_type', $selectedProduct?->is_sellable ? 'deactivate' : 'activate') === 'activate')> فعال‌سازی مجدد</label></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">محدوده</label>
                    <div><label class="me-3"><input type="radio" name="scope_type" value="product" @checked(old('scope_type', 'product') === 'product')> کل کالا</label><label><input type="radio" name="scope_type" value="variants" @checked(old('scope_type') === 'variants')> تنوع‌های مشخص</label></div>
                </div>
            </div>

            <div id="variantsPanel" class="border rounded mt-3 d-none">
                <div class="p-2 border-bottom fw-bold">انتخاب تنوع‌ها</div>
                <div id="variantsList" class="p-2"><span class="text-muted">در حال دریافت…</span></div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-md-6"><label class="form-label fw-bold">دلیل تغییر وضعیت</label><select name="reason_type" id="reasonType" class="form-select" required></select></div>
                <div class="col-md-6"><label class="form-label">توضیح تکمیلی</label><textarea name="reason_text" id="reasonText" class="form-control" rows="2">{{ old('reason_text') }}</textarea><div class="form-text">برای «دلیل سفارشی» الزامی است.</div></div>
            </div>
        </div>
    </div>
    <div class="card-footer bg-white"><button id="submitButton" class="btn btn-primary" {{ $selectedProduct ? '' : 'disabled' }}>ثبت تغییر وضعیت</button></div>
</form>

@php
    $selectedPayload = $selectedProduct ? [
        'id' => $selectedProduct->id,
        'name' => $selectedProduct->name,
        'code' => $selectedProduct->code ?: $selectedProduct->sku,
        'category' => $selectedProduct->category?->name,
        'is_sellable' => (bool) $selectedProduct->is_sellable,
        'computed_sellable' => (int) $selectedProduct->sellable_variants_count > 0,
        'computed_status' => (int) $selectedProduct->sellable_variants_count === 0 ? 'inactive' : (((int) $selectedProduct->structural_variants_count > 0 && (int) $selectedProduct->sellable_variants_count >= (int) $selectedProduct->structural_variants_count) ? 'active' : 'partial'),
        'status_inconsistent' => (bool) $selectedProduct->is_sellable !== ((int) $selectedProduct->sellable_variants_count > 0),
        'structural_variants_count' => (int) $selectedProduct->structural_variants_count,
        'sellable_variants_count' => (int) $selectedProduct->sellable_variants_count,
    ] : null;
@endphp
<script>
const routes={search:@json(route('product-deactivation-documents.products.search')),variants:@json(url('/product-deactivation-documents/products'))};
const reasons={deactivate:@json(\App\Models\ProductDeactivationDocument::reasonLabels()),activate:@json(\App\Models\ProductDeactivationDocument::activationReasonLabels())};
let current=@json($selectedPayload), timer, aborter;
const $=id=>document.getElementById(id), escapeHtml=s=>String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
function renderReasons(){const action=document.querySelector('[name=action_type]:checked')?.value||'deactivate',old=@json(old('reason_type'));$('reasonType').innerHTML=Object.entries(reasons[action]).map(([v,l])=>`<option value="${v}" ${old===v?'selected':''}>${escapeHtml(l)}</option>`).join('')}
function effectiveStatus(product){const structural=Number(product.structural_variants_count||0),sellable=Number(product.sellable_variants_count||0);if(sellable===0)return'inactive';if(structural>0&&sellable>=structural)return'active';return'partial'}
async function selectProduct(product){current=product;$('productId').value=product.id;$('selectedName').textContent=product.name;$('selectedMeta').textContent=`کد: ${product.code||'—'} | دسته‌بندی: ${product.category||'—'}`;const status=product.computed_status||effectiveStatus(product),labels={active:'فعال',partial:'نیمه‌فعال',inactive:'غیرفعال'},classes={active:'text-bg-success',partial:'text-bg-warning',inactive:'text-bg-secondary'};$('statusBadge').textContent=labels[status]||'غیرفعال';$('statusBadge').className='badge '+(classes[status]||'text-bg-secondary');$('variantSummary').textContent=`${product.sellable_variants_count} از ${product.structural_variants_count} تنوع قابل فروش`;$('statusWarning').classList.toggle('d-none',!product.status_inconsistent);$('selectedProduct').classList.remove('d-none');$('operationFields').classList.remove('d-none');$('submitButton').disabled=false;$('searchResults').innerHTML='';const preferred=Number(product.sellable_variants_count||0)>0?'deactivate':'activate';document.querySelector(`[name=action_type][value=${preferred}]`).checked=true;renderReasons();await loadVariants()}
async function loadVariants(){if(!current)return;const response=await fetch(`${routes.variants}/${current.id}/variants`,{headers:{Accept:'application/json'}});const data=await response.json();$('variantsList').innerHTML=data.variants.length?data.variants.map(v=>`<label class="d-flex gap-2 align-items-center border-bottom py-2"><input type="checkbox" name="variant_ids[]" value="${v.id}"><span><b>${escapeHtml(v.variant_name||'تنوع اصلی')}</b> <small class="text-muted">${escapeHtml(v.variant_code||'—')}</small><br><small>${v.is_active?(v.sales_enabled?'قابل فروش':'فروش غیرفعال'):'از نظر ساختاری غیرفعال'}</small></span></label>`).join(''):'<span class="text-muted">تنوعی ثبت نشده است.</span>'}
$('productSearch').addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(async()=>{const q=$('productSearch').value.trim();if(q.length<2){$('searchResults').innerHTML='';return}aborter?.abort();aborter=new AbortController();try{const response=await fetch(`${routes.search}?q=${encodeURIComponent(q)}`,{signal:aborter.signal,headers:{Accept:'application/json'}});const data=await response.json();$('searchResults').innerHTML=data.data.map(p=>`<button type="button" class="list-group-item list-group-item-action" data-id="${p.id}"><b>${escapeHtml(p.name)}</b><br><small>${escapeHtml(p.code||'—')} | ${escapeHtml(p.category||'—')}</small></button>`).join('')||'<div class="list-group-item text-muted">نتیجه‌ای یافت نشد.</div>';$('searchResults').querySelectorAll('[data-id]').forEach(button=>button.onclick=()=>selectProduct(data.data.find(p=>String(p.id)===button.dataset.id))) }catch(e){if(e.name!=='AbortError')$('searchResults').innerHTML='<div class="list-group-item text-danger">خطا در جستجو</div>'}},300)});
document.querySelectorAll('[name=action_type]').forEach(el=>el.addEventListener('change',renderReasons));document.querySelectorAll('[name=scope_type]').forEach(el=>el.addEventListener('change',()=>{$('variantsPanel').classList.toggle('d-none',document.querySelector('[name=scope_type]:checked').value!=='variants')}));
renderReasons();if(current)loadVariants();if(document.querySelector('[name=scope_type]:checked')?.value==='variants')$('variantsPanel').classList.remove('d-none');
</script>
@endsection
