document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('productBuilder');
    if (!root) return;
    const $ = id => document.getElementById(id);
    const selected = new Map();
    let shown = new Map(), page = 1, lastPage = 1, timer, controller;
    const fa = n => new Intl.NumberFormat('fa-IR').format(n);
    const money = n => Number.isFinite(n) && n > 0 ? fa(n) + ' ریال' : 'قیمت ثبت نشده';
    const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const modelKey = model => model.name;
    const chosenModels = entry => entry.product.models.filter(model => entry.keys.has(modelKey(model)));
    const selectedProducts = () => [...selected.values()].filter(entry => entry.keys.size);
    const photo = product => product.image
        ? `<span class="builder-photo"><img src="${escape(product.image)}" alt="${escape(product.name)}" loading="lazy"></span>`
        : '<span class="builder-photo builder-photo--empty">بدون تصویر</span>';

    function toast(message) {
        $('builderToast').textContent = message;
        $('builderToast').classList.add('show');
        clearTimeout(toast.timer);
        toast.timer = setTimeout(() => $('builderToast').classList.remove('show'), 2700);
    }
    async function copy(text) {
        try {
            if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(text);
            else {
                const area = document.createElement('textarea');
                area.value = text; area.style.position = 'fixed'; area.style.opacity = '0';
                document.body.append(area); area.select();
                if (!document.execCommand('copy')) throw new Error('copy');
                area.remove();
            }
            toast('کپی شد؛ آمادهٔ ارسال برای مشتری است.');
        } catch { toast('کپی خودکار ممکن نشد.'); }
    }
    function card(product) {
        const keys = selected.get(product.id)?.keys || new Set();
        const chosen = product.models.filter(model => keys.has(modelKey(model))).length;
        return `<article class="builder-card" data-product="${product.id}">${photo(product)}<div class="card-main"><div class="card-top"><div><span class="card-kind">${escape(product.category)}</span><h2>${escape(product.name)}</h2><span class="card-sku">${escape(product.code)}</span></div><div class="card-price"><small>قیمت از</small>${money(product.price)}</div></div><div class="card-meta"><span class="card-stock ${product.stock > 0 ? '' : 'empty'}">${product.stock > 0 ? 'موجود' : 'ناموجود'}</span><span>${fa(product.models.length)} مدل سازگار</span></div><div class="models-label"><span>مدل‌های این کالا</span><button class="text-action" type="button" data-copy="${product.id}">کپی مدل‌های همین کالا ↗</button></div><div class="model-list">${product.models.map((model, i) => `<button class="model-chip ${keys.has(modelKey(model)) ? 'active' : ''}" type="button" data-model="${product.id}:${i}" aria-pressed="${keys.has(modelKey(model))}">${escape(model.name)}</button>`).join('')}</div><div class="card-actions"><button class="select-all" type="button" data-all="${product.id}">${chosen === product.models.length ? 'حذف همه مدل‌ها' : 'افزودن همه مدل‌ها'}</button><span class="selected-note">${chosen ? fa(chosen) + ' مدل انتخاب شده' : 'مدلی انتخاب نشده'}</span></div></div></article>`;
    }
    function renderBasket() {
        const entries = selectedProducts();
        $('builderBasketCount').textContent = fa(entries.length) + ' کالا';
        $('builderMobileBasket').textContent = 'مشاهده لیست خروجی · ' + fa(entries.length) + ' کالا';
        $('builderBasketItems').innerHTML = entries.length ? entries.map(entry => `<div class="basket-item"><span><strong>${escape(entry.product.name)}</strong><small>${fa(entry.keys.size)} مدل انتخابی</small></span><button type="button" data-remove="${entry.product.id}" aria-label="حذف ${escape(entry.product.name)}">×</button></div>`).join('') : '<div class="basket-empty"><b>هنوز کالایی انتخاب نشده</b>مدل‌های موردنیاز مشتری را از کارت‌ها انتخاب کنید.</div>';
    }
    function refreshCards() {
        $('builderCards').innerHTML = [...shown.values()].map(card).join('') || '<div class="builder-panel basket-empty"><b>کالایی پیدا نشد</b>فیلترها یا عبارت جست‌وجو را تغییر دهید.</div>';
        renderBasket();
    }
    function params(nextPage) {
        const search = new URLSearchParams({page: nextPage, sort: $('builderSort').value});
        if ($('builderQuery').value.trim()) search.set('q', $('builderQuery').value.trim());
        if ($('builderCategory').value) search.set('category_id', $('builderCategory').value);
        if ($('builderBrand').value) search.set('brand', $('builderBrand').value);
        search.set('in_stock', $('builderInStock').checked ? '1' : '0');
        return search;
    }
    async function load(reset = true) {
        if (reset) { page = 1; shown = new Map(); $('builderCards').innerHTML = '<div class="builder-panel basket-empty">در حال دریافت کالاها...</div>'; }
        controller?.abort(); controller = new AbortController();
        $('builderLoadMore').hidden = true;
        try {
            const response = await fetch(root.dataset.productsUrl + '?' + params(page), {headers:{Accept:'application/json'}, signal:controller.signal});
            if (!response.ok) throw new Error('load');
            const data = await response.json();
            for (const product of data.items) {
                shown.set(product.id, product);
                if (selected.has(product.id)) {
                    const entry = selected.get(product.id);
                    entry.product = product;
                    if ($('builderInStock').checked) {
                        const available = new Set(product.models.map(modelKey));
                        entry.keys = new Set([...entry.keys].filter(key => available.has(key)));
                        if (!entry.keys.size) selected.delete(product.id);
                    }
                }
            }
            lastPage = data.last_page;
            $('builderResultCount').textContent = '· ' + fa(data.total) + ' نتیجه';
            $('builderLoadMore').hidden = page >= lastPage;
            refreshCards();
        } catch (error) {
            if (error.name !== 'AbortError') {
                $('builderCards').innerHTML = '<div class="builder-panel basket-empty"><b>بارگیری ناموفق بود</b>لطفاً دوباره تلاش کنید.</div>';
                toast('دریافت کالاها انجام نشد.');
            }
        }
    }
    function select(product, index) {
        const entry = selected.get(product.id) || {product, keys:new Set()};
        const key = modelKey(product.models[index]);
        if (entry.keys.has(key)) entry.keys.delete(key);
        else {
            if (!selected.has(product.id) && selected.size >= 200) return toast('حداکثر ۲۰۰ کالا را می‌توان انتخاب کرد.');
            entry.keys.add(key);
        }
        entry.keys.size ? selected.set(product.id, entry) : selected.delete(product.id);
        refreshCards();
    }
    $('builderCards').addEventListener('click', event => {
        const copyButton = event.target.closest('[data-copy]');
        if (copyButton) {
            const product = shown.get(Number(copyButton.dataset.copy));
            copySelected([{product, keys:new Set(product.models.map(modelKey))}]);
            return;
        }
        const modelButton = event.target.closest('[data-model]');
        if (modelButton) {
            const [id, index] = modelButton.dataset.model.split(':').map(Number);
            select(shown.get(id), index);
            return;
        }
        const allButton = event.target.closest('[data-all]');
        if (allButton) {
            const product = shown.get(Number(allButton.dataset.all));
            const entry = selected.get(product.id);
            if (entry?.keys.size === product.models.length) selected.delete(product.id);
            else {
                if (!entry && selected.size >= 200) return toast('حداکثر ۲۰۰ کالا را می‌توان انتخاب کرد.');
                selected.set(product.id, {product, keys:new Set(product.models.map(modelKey))});
            }
            refreshCards();
        }
    });
    $('builderBasketItems').addEventListener('click', event => {
        const button = event.target.closest('[data-remove]');
        if (button) { selected.delete(Number(button.dataset.remove)); refreshCards(); }
    });
    $('builderQuery').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => load(), 280); });
    for (const id of ['builderCategory','builderBrand','builderSort','builderInStock']) $(id).addEventListener('change', () => load());
    $('builderLoadMore').addEventListener('click', () => { if (page < lastPage) { page++; load(false); } });
    $('builderFilterToggle').addEventListener('click', () => {
        $('builderFilters').hidden = !$('builderFilters').hidden;
        $('builderFilterToggle').setAttribute('aria-expanded', String(!$('builderFilters').hidden));
    });
    $('builderMobileBasket').addEventListener('click', () => $('builderBasket').scrollIntoView({behavior:'smooth', block:'start'}));
    $('builderCopyAll').addEventListener('click', () => selected.size ? copySelected() : toast('ابتدا یک مدل انتخاب کنید.'));
    function selectionFields(entries = selectedProducts()) {
        const fields = new URLSearchParams();
        for (const entry of entries) for (const model of chosenModels(entry)) fields.append(`selection[${entry.product.id}][]`, model.id);
        fields.set('show_price', $('builderShowPrice').checked ? 1 : 0);
        fields.set('show_stock', $('builderShowStock').checked ? 1 : 0);
        fields.set('show_code', $('builderShowCode').checked ? 1 : 0);
        fields.set('in_stock', $('builderInStock').checked ? 1 : 0);
        return fields;
    }
    async function copySelected(entries = selectedProducts()) {
        const fields = selectionFields(entries);
        fields.set('copy_text', '1');
        try {
            const response = await fetch(root.dataset.previewUrl, {
                method:'POST', headers:{Accept:'application/json','Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},
                body:fields,
            });
            const data = await response.json();
            if (!response.ok || response.redirected) throw new Error(data.errors?.selection?.[0] || 'دریافت مدل‌های موجود انجام نشد.');
            await copy(data.text);
        } catch (error) { toast(error.message); }
    }
    async function showPreview() {
        if (!selectedProducts().length) return toast('ابتدا یک کالا یا مدل انتخاب کنید.');
        $('builderDialog').hidden = false;
        document.body.style.overflow = 'hidden';
        $('builderSheet').textContent = 'در حال آماده‌سازی پیش‌نمایش...';
        $('builderPrint').disabled = true;
        try {
            const response = await fetch(root.dataset.previewUrl, {
                method: 'POST',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
                body: selectionFields(),
            });
            if (!response.ok || response.redirected) {
                const detail = await response.json().catch(() => null);
                throw new Error(detail?.errors?.selection?.[0] || 'پیش‌نمایش آماده نشد. لطفاً دوباره تلاش کنید.');
            }
            $('builderSheet').innerHTML = await response.text();
            $('builderPrint').disabled = false;
        } catch (error) {
            $('builderSheet').textContent = error.message;
            toast(error.message);
        }
    }
    $('builderPreview').addEventListener('click', showPreview);
    function closePreview() { $('builderDialog').hidden = true; document.body.style.overflow = ''; }
    $('builderClosePreview').addEventListener('click', closePreview);
    $('builderDialog').addEventListener('click', event => { if (event.target === $('builderDialog')) closePreview(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && !$('builderDialog').hidden) closePreview(); });
    $('builderCopySummary').addEventListener('click', () => copySelected());
    $('builderPrint').addEventListener('click', () => {
        const form = document.createElement('form');
        form.method = 'POST'; form.action = root.dataset.printUrl; form.target = '_blank'; form.hidden = true;
        const field = (name, value) => { const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.append(input); };
        field('_token', document.querySelector('meta[name="csrf-token"]').content);
        for (const [name, value] of selectionFields()) field(name, value);
        document.body.append(form); form.submit(); form.remove();
    });
    if (window.matchMedia('(max-width:550px)').matches) { $('builderFilters').hidden = true; $('builderFilterToggle').setAttribute('aria-expanded', 'false'); }
    renderBasket(); load();
});
