(() => {
    'use strict';
    const modalEl = document.getElementById('productFinderModal');
    if (!modalEl || !window.bootstrap) return;
    const finderModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const el = id => document.getElementById(id);
    const query = el('productFinderQuery');
    const category = el('productFinderCategory');
    const subcategory = el('productFinderSubcategory');
    const inStock = el('productFinderInStock');
    const results = el('productFinderResults');
    const status = el('productFinderStatus');
    const pagination = el('productFinderPagination');
    let controller = null, debounceTimer = null, requestSequence = 0, pendingProduct = null;
    const productsById = new Map();

    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
    const digits = value => String(value ?? '').replace(/[۰-۹٠-٩]/g, digit => '۰۱۲۳۴۵۶۷۸۹'.includes(digit) ? '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit) : '٠١٢٣٤٥٦٧٨٩'.indexOf(digit));
    const normalizedQuery = () => digits(query.value).trim().replace(/\s+/g, ' ');
    const setStatus = (message, type = '') => { status.className = 'product-finder__status' + (type ? ` ${type}` : ''); status.innerHTML = message; status.hidden = false; };
    const clearResults = () => { results.innerHTML = ''; pagination.innerHTML = ''; };
    const responseMessage = statusCode => ({401:'نشست شما پایان یافته است. دوباره وارد شوید.',403:'اجازه دسترسی به یافتن کالا را ندارید.',419:'نشست شما منقضی شده است. صفحه را تازه کنید.',422:'فیلترهای جست‌وجو معتبر نیستند.',500:'خطایی در سرور رخ داد. دوباره تلاش کنید.'}[statusCode] || 'ارتباط با سرور برقرار نشد. دوباره تلاش کنید.');
    async function jsonResponse(response) {
        const type = response.headers.get('content-type') || '';
        if (!response.ok) throw new Error(responseMessage(response.status));
        if (!type.toLowerCase().includes('application/json')) throw new Error('پاسخ نامعتبر از سرور دریافت شد. لطفاً دوباره وارد شوید.');
        return response.json();
    }

    async function loadCategories(parentId = '') {
        const target = parentId ? subcategory : category;
        const firstLabel = parentId ? 'همه زیردسته‌ها' : 'همه دسته‌ها';
        target.innerHTML = `<option value="">${firstLabel}</option>`;
        try {
            const url = new URL(modalEl.dataset.categoriesUrl, window.location.origin);
            if (parentId) url.searchParams.set('parent_id', parentId);
            const response = await fetch(url.pathname + url.search, {headers:{Accept:'application/json'},credentials:'same-origin'});
            const json = await jsonResponse(response);
            (json.data || []).forEach(item => target.insertAdjacentHTML('beforeend', `<option value="${Number(item.id)}">${escapeHtml(item.name)}</option>`));
            if (parentId) target.disabled = false;
        } catch (_) {
            if (parentId) target.disabled = true;
        }
    }

    function renderProducts(items) {
        productsById.clear();
        items.forEach(product => productsById.set(Number(product.id), product));
        results.innerHTML = items.map(product => {
            const matched = product.matched_variants || [];
            const remaining = Math.max(Number(product.matched_variants_count || 0) - matched.length, 0);
            const matchText = matched.length ? `<div class="finder-product__matches"><strong>تطبیق در تنوع‌ها:</strong> ${matched.map(v => escapeHtml(v.name)).join('، ')}${remaining ? ` و ${remaining.toLocaleString('fa-IR')} تنوع دیگر` : ''}</div>` : '';
            const stock = Number(product.total_available_stock || 0);
            const image = product.image ? `<img class="finder-product__image" src="${escapeHtml(product.image)}" alt="">` : '<div class="finder-product__image finder-product__placeholder" aria-hidden="true">⌕</div>';
            return `<article class="finder-product" data-product-id="${Number(product.id)}">${image}<div><div class="finder-product__title">${escapeHtml(product.name)}</div><div class="finder-product__meta">کد: ${escapeHtml(product.short_code || product.code || '—')} · ${escapeHtml(product.category?.path || 'بدون دسته‌بندی')}</div>${matchText}<div class="finder-product__badges"><span class="finder-product__badge">${Number(product.sellable_variants_count || 0).toLocaleString('fa-IR')} تنوع قابل‌فروش</span><span class="finder-product__badge ${stock <= 0 ? 'is-empty' : ''}">${stock > 0 ? `موجودی آزاد: ${stock.toLocaleString('fa-IR')}` : 'ناموجود'}</span></div></div><button type="button" class="btn btn-primary finder-product__select" data-select-product="${Number(product.id)}">انتخاب کالا</button></article>`;
        }).join('');
    }

    function renderPagination(meta) {
        if (!meta || meta.last_page <= 1) { pagination.innerHTML = ''; return; }
        const buttons = [];
        for (let page = Math.max(1, meta.current_page - 2); page <= Math.min(meta.last_page, meta.current_page + 2); page++) buttons.push(`<button type="button" class="btn btn-sm ${page === meta.current_page ? 'btn-primary' : 'btn-outline-secondary'}" data-finder-page="${page}">${page.toLocaleString('fa-IR')}</button>`);
        pagination.innerHTML = buttons.join('');
    }

    async function search(page = 1) {
        const q = normalizedQuery(), categoryId = category.value, subcategoryId = subcategory.value;
        if (q.replace(/\s/g, '').length < 2 && !categoryId && !subcategoryId) { controller?.abort(); clearResults(); setStatus('برای شروع حداقل دو حرف وارد کنید یا یک دسته‌بندی انتخاب کنید.'); return; }
        controller?.abort(); controller = new AbortController(); const sequence = ++requestSequence;
        clearResults(); setStatus('<div class="product-finder__spinner"></div>در حال جست‌وجوی کالاها...');
        const url = new URL(modalEl.dataset.searchUrl, window.location.origin);
        Object.entries({q,category_id:categoryId,subcategory_id:subcategoryId,in_stock_only:inStock.checked ? '1' : '0',page,per_page:20}).forEach(([key,value]) => value !== '' && url.searchParams.set(key,value));
        try {
            const response = await fetch(url.pathname + url.search, {headers:{Accept:'application/json'},credentials:'same-origin',signal:controller.signal});
            const json = await jsonResponse(response); if (sequence !== requestSequence) return;
            if (!(json.data || []).length) { clearResults(); setStatus('کالایی با این فیلترها پیدا نشد.'); return; }
            status.hidden = true; renderProducts(json.data); renderPagination(json.meta);
        } catch (error) {
            if (error.name === 'AbortError') return;
            clearResults(); setStatus('ارتباط با سرور برقرار نشد. دوباره تلاش کنید.', 'is-error');
        }
    }

    const schedule = () => { clearTimeout(debounceTimer); debounceTimer = setTimeout(() => search(1), 350); };
    query.addEventListener('input', schedule);
    query.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); clearTimeout(debounceTimer); search(1); } });
    inStock.addEventListener('change', () => search(1));
    category.addEventListener('change', async () => { subcategory.value=''; subcategory.disabled=true; await loadCategories(category.value); search(1); });
    subcategory.addEventListener('change', () => search(1));
    el('productFinderReset').addEventListener('click', () => { query.value='';category.value='';subcategory.innerHTML='<option value="">همه زیردسته‌ها</option>';subcategory.disabled=true;inStock.checked=true;controller?.abort();clearResults();setStatus('برای شروع حداقل دو حرف وارد کنید یا یک دسته‌بندی انتخاب کنید.');query.focus(); });
    results.addEventListener('click', event => { const button=event.target.closest('[data-select-product]'); if(!button)return; const card=button.closest('[data-product-id]'); const id=Number(card?.dataset.productId); if(!id)return; pendingProduct=productsById.get(id) || {id}; finderModal.hide(); });
    pagination.addEventListener('click', event => { const button=event.target.closest('[data-finder-page]'); if(button)search(Number(button.dataset.finderPage)); });
    modalEl.addEventListener('shown.bs.modal', () => { if (!category.options.length || category.options.length === 1) loadCategories(); setTimeout(() => query.focus(), 50); });
    modalEl.addEventListener('hidden.bs.modal', () => { controller?.abort(); if (!pendingProduct) { el('openProductFinderBtn')?.focus(); window.PreinvoiceProductModalLifecycle?.scheduleCleanup(); return; } const product=pendingProduct; pendingProduct=null; document.dispatchEvent(new CustomEvent('preinvoice:product-selected',{detail:{productId:product.id,product}})); });
})();
