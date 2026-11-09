(() => {
    'use strict';
    const configNode = document.getElementById('sr-editor-config');
    if (!configNode) return;
    const config = JSON.parse(configNode.textContent);
    const byId = id => document.getElementById(id);
    const form = byId('showroom-form'), body = byId('sr-rows'), resultsBody = byId('sr-results');
    const selected = new Map(), pending = new Map();
    const fa = value => Number(value || 0).toLocaleString('fa-IR');
    const latin = value => String(value ?? '').replace(/[۰-۹]/g, c => '۰۱۲۳۴۵۶۷۸۹'.indexOf(c)).replace(/[٠-٩]/g, c => '٠١٢٣٤٥٦٧٨٩'.indexOf(c));
    const norm = value => latin(value).replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').trim().toLowerCase();
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let results = [], cursor = null, hasMore = false, loading = false, sequence = 0, controller, timer;
    let refreshing = false, submitting = false, currentProduct = null;
    form.noValidate = true;
    const notice = text => { byId('sr-notice').textContent = text; byId('sr-notice').hidden = !text; };
    const itemText = row => `${row.product_name} ${row.variant_title} ${row.code} ${row.product_code || ''}`;
    const quantityNumber = row => /^\d{1,7}$/.test(latin(row.quantity).trim()) ? Number(latin(row.quantity).trim()) : 0;

    function validation(row) {
        const raw = latin(row.quantity).trim(), qty = quantityNumber(row);
        if (!/^\d{1,7}$/.test(raw) || qty < 1 || qty > 1000000) return 'تعداد صحیح و بیشتر از صفر وارد کنید.';
        if (!row.selectable) return 'این مدل اکنون قابل انتقال نیست؛ موجودی و وضعیت آن را بررسی کنید.';
        if (qty > Number(row.available)) return `حداکثر موجودی آزاد ${fa(row.available)} عدد است.`;
        return row.serverError || '';
    }

    function updateSummary() {
        let total = 0, invalid = 0;
        selected.forEach(row => {
            total += quantityNumber(row);
            const message = validation(row), tr = byId(`sr-line-${row.id}`);
            if (message) invalid++;
            if (tr) {
                tr.classList.toggle('sr-line-error', !!message);
                tr.querySelector('[data-quantity]').setCustomValidity(message);
                tr.querySelector('.sr-line-message').textContent = message;
            }
        });
        byId('sr-summary').textContent = selected.size ? `${fa(selected.size)} ردیف · ${fa(total)} عدد برای انتقال` : 'هنوز مدلی انتخاب نشده است';
        byId('sr-validation').textContent = invalid ? `${fa(invalid)} ردیف نیاز به بررسی دارد.` : 'موجودی هنگام ثبت دوباره کنترل می‌شود.';
        byId('sr-submit').disabled = !selected.size || refreshing || submitting;
        byId('sr-refresh').disabled = !selected.size || refreshing || submitting;
        byId('sr-apply-quantity').disabled = !selected.size || submitting;
    }

    function filterSelected() {
        const term = norm(byId('sr-selected-search').value);
        selected.forEach(row => { const tr = byId(`sr-line-${row.id}`); if (tr) tr.hidden = !norm(itemText(row)).includes(term); });
    }

    function renderSelected() {
        body.innerHTML = [...selected.values()].map((row, i) => `<tr id="sr-line-${Number(row.id)}" data-selected-id="${Number(row.id)}">
            <td>${fa(i + 1)}</td><td><strong>${esc(row.product_name)}</strong><small>${esc(row.variant_title)}</small><small class="sr-code">${esc(row.code)}</small>
            <input type="hidden" name="items[${i}][category_id]" value="${Number(row.category_id)}"><input type="hidden" name="items[${i}][product_id]" value="${Number(row.product_id)}"><input type="hidden" name="items[${i}][variant_id]" value="${Number(row.id)}"></td>
            <td data-label="آزاد مرکزی">${fa(row.available)}</td><td data-label="موجودی شوروم">${fa(row.showroom_quantity)}</td>
            <td data-label="تعداد انتقال"><input data-quantity class="form-control sr-quantity" type="text" inputmode="numeric" autocomplete="off" maxlength="10" required name="items[${i}][quantity]" value="${esc(row.quantity)}" aria-label="تعداد انتقال ${esc(row.product_name)} ${esc(row.variant_title)}" aria-describedby="sr-error-${Number(row.id)}"><small class="sr-line-message" id="sr-error-${Number(row.id)}"></small></td>
            <td><button type="button" class="sr-remove" data-remove="${Number(row.id)}" aria-label="حذف ${esc(row.product_name)} ${esc(row.variant_title)}">×</button></td></tr>`).join('');
        byId('sr-empty').hidden = selected.size > 0;
        byId('sr-selected-search').disabled = selected.size === 0;
        updateSummary(); filterSelected();
    }

    function updatePending() {
        byId('sr-add-selected').disabled = !pending.size || submitting;
        byId('sr-add-selected').textContent = pending.size ? `افزودن ${fa(pending.size)} انتخاب` : 'افزودن انتخاب‌ها';
        const eligible = results.filter(row => row.selectable && !selected.has(Number(row.id)));
        const checked = eligible.filter(row => pending.has(Number(row.id))).length;
        byId('sr-check-visible').checked = eligible.length > 0 && checked === eligible.length;
        byId('sr-check-visible').indeterminate = checked > 0 && checked < eligible.length;
        byId('sr-check-visible').disabled = !eligible.length;
    }

    function renderResults() {
        byId('sr-product-list').hidden = !!currentProduct;
        byId('sr-variant-pane').hidden = !currentProduct;
        if (!currentProduct) {
            byId('sr-product-list').innerHTML = results.map(row => `<button type="button" class="sr-product-option" data-product="${Number(row.id)}"><strong>${esc(row.name)}</strong><span>انتخاب تنوع‌ها ←</span></button>`).join('');
            resultsBody.innerHTML = ''; return;
        }
        const groups = new Map();
        results.forEach(row => { const key = row.group_key || row.variant_title; if (!groups.has(key)) groups.set(key, []); groups.get(key).push(row); });
        const openGroups = new Set([...resultsBody.querySelectorAll('details[open]')].map(el => el.dataset.groupKey));
        const hadGroups = !!resultsBody.querySelector('details');
        resultsBody.innerHTML = [...groups.values()].map((rows, index) => `<details class="sr-variant-group" data-group-key="${esc(rows[0].group_key || rows[0].variant_title)}" ${openGroups.has(String(rows[0].group_key || rows[0].variant_title)) || (!hadGroups && index === 0) ? 'open' : ''}><summary>${esc(rows[0].group_title || rows[0].variant_title)} <small>${fa(rows.length)} مورد</small></summary>
            <label class="sr-check sr-group-check"><input type="checkbox" data-group="${index}" ${rows.every(row => selected.has(Number(row.id)) || pending.has(Number(row.id)) || !row.selectable) ? 'checked' : ''}> انتخاب جمعی این تنوع</label>
            ${rows.map(row => { const picked = selected.has(Number(row.id)); return `<div class="sr-subvariant ${!row.selectable ? 'sr-result-unavailable' : ''}"><label class="sr-check"><input type="checkbox" data-pick="${Number(row.id)}" aria-label="انتخاب ${esc(row.product_name)} ${esc(row.variant_title)}" ${picked || pending.has(Number(row.id)) ? 'checked' : ''} ${picked || !row.selectable ? 'disabled' : ''}><span><strong>${esc(row.subvariant_title || row.variant_title || 'بدون زیرتنوع')}</strong><small class="sr-code">${esc(row.code)}</small></span></label><span class="sr-stock-pair">مرکزی <b>${fa(row.available)}</b> · شوروم <b>${fa(row.showroom_quantity)}</b>${picked ? `<button type="button" data-focus-row="${Number(row.id)}">در حواله</button>` : ''}</span></div>`; }).join('')}</details>`).join('');
        resultsBody.querySelectorAll('[data-group]').forEach(input => {
            const eligible = [...input.closest('details').querySelectorAll('[data-pick]')].filter(el => !el.disabled);
            const checked = eligible.filter(el => el.checked).length;
            input.disabled = !eligible.length; input.checked = eligible.length > 0 && checked === eligible.length; input.indeterminate = checked > 0 && checked < eligible.length;
        });
        updatePending();
    }

    function setProduct(product) {
        currentProduct = product;
        byId('sr-product-name').textContent = product?.name || '';
        byId('sr-search').value = '';
        byId('sr-search').placeholder = product ? 'مدل، رنگ، طرح یا کد زیرتنوع' : 'نام یا کد کالا';
        byId('sr-search-label').textContent = product ? 'جستجو در تنوع‌های همین کالا' : 'جستجوی کالا';
        loadCatalog(true);
    }

    function categoryOptions() {
        const categories = config.categories || [], root = byId('sr-category').value;
        const options = [], seen = new Set();
        function visit(parent, depth) {
            categories.filter(c => Number(c.parent_id || 0) === Number(parent)).forEach(c => {
                if (seen.has(Number(c.id))) return; seen.add(Number(c.id));
                options.push(`<option value="${Number(c.id)}">${esc('— '.repeat(depth) + c.name)}</option>`); visit(c.id, depth + 1);
            });
        }
        if (root) visit(root, 0);
        byId('sr-subcategory').innerHTML = '<option value="">همه زیردسته‌ها</option>' + options.join('');
        byId('sr-subcategory').disabled = !options.length;
    }
    byId('sr-category').innerHTML += (config.categories || []).filter(c => !c.parent_id).map(c => `<option value="${Number(c.id)}">${esc(c.name)}</option>`).join('');
    categoryOptions();
    byId('sr-product-list').addEventListener('click', event => {
        const button = event.target.closest('[data-product]'); if (button) setProduct(results.find(row => Number(row.id) === Number(button.dataset.product)));
    });
    byId('sr-back-products').addEventListener('click', () => setProduct(null));

    async function fetchJSON(url, signal) {
        const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal });
        if (!response.ok) throw new Error(response.status === 403 ? 'مجوز دریافت مدل‌ها را ندارید.' : 'دریافت اطلاعات انجام نشد. دوباره تلاش کنید.');
        return response.json();
    }

    async function loadCatalog(reset = true) {
        if (!reset && (loading || !hasMore)) return;
        if (reset) { controller?.abort(); cursor = null; results = []; renderResults(); }
        controller = new AbortController(); const seq = ++sequence;
        loading = true; byId('sr-retry').hidden = true; byId('sr-more').hidden = true;
        byId('sr-search-state').textContent = 'در حال دریافت…';
        const url = new URL(config.catalogUrl, location.origin);
        url.searchParams.set('q', byId('sr-search').value.trim());
        if (currentProduct) url.searchParams.set('product_id', currentProduct.id); else url.searchParams.set('view', 'products');
        const category = byId('sr-subcategory').value || byId('sr-category').value; if (category) url.searchParams.set('category_id', category);
        url.searchParams.set('available_only', byId('sr-available-only').checked ? '1' : '0');
        if (cursor !== null) url.searchParams.set('cursor', cursor);
        try {
            const data = await fetchJSON(url, controller.signal);
            if (seq !== sequence) return;
            const existing = new Set(results.map(row => Number(row.id)));
            (data.data || []).forEach(row => { if (!existing.has(Number(row.id))) results.push(row); });
            cursor = data.meta?.next_cursor ?? null; hasMore = !!data.meta?.has_more;
            renderResults();
            byId('sr-search-state').textContent = results.length ? `${fa(results.length)} ${currentProduct ? 'تنوع / زیرتنوع' : 'کالا'} نمایش داده شد${hasMore ? '؛ موارد بیشتری وجود دارد.' : '.'}` : 'موردی پیدا نشد؛ جستجو یا فیلتر موجودی را تغییر دهید.';
            byId('sr-more').hidden = !hasMore;
        } catch (error) {
            if (seq === sequence && error.name !== 'AbortError') {
                byId('sr-search-state').textContent = error.message; byId('sr-retry').hidden = false;
            }
        } finally { if (seq === sequence) loading = false; }
    }

    function focusRow(id) {
        byId('sr-selected-search').value = ''; filterSelected();
        document.querySelectorAll('.sr-line-highlight').forEach(row => row.classList.remove('sr-line-highlight'));
        const row = byId(`sr-line-${id}`);
        if (row) { row.classList.add('sr-line-highlight'); row.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); row.querySelector('[data-quantity]').focus({ preventScroll: true }); }
    }

    function pick(row, checked) {
        const id = Number(row.id);
        if (!checked) { pending.delete(id); return; }
        if (!row.selectable || selected.has(id)) return;
        if (selected.size + pending.size >= config.maxRows && !pending.has(id)) { notice('هر حواله تا ۱۰۰ ردیف دارد. برای اقلام بیشتر حوالهٔ دیگری ثبت کنید.'); return; }
        pending.set(id, row);
    }

    async function refreshSelected() {
        if (refreshing || !selected.size) return false;
        refreshing = true; updateSummary(); byId('sr-refresh').textContent = 'در حال بررسی…';
        const url = new URL(config.catalogUrl, location.origin);
        selected.forEach(row => url.searchParams.append('ids[]', Number(row.id)));
        try {
            const data = await fetchJSON(url); const fresh = new Map((data.data || []).map(row => [Number(row.id), row]));
            selected.forEach((row, id) => {
                const updated = fresh.get(id);
                selected.set(id, { ...row, ...(updated || { available: 0, selectable: false }), quantity: row.quantity, serverError: '' });
            });
            renderSelected(); return true;
        } catch (error) { notice(`${error.message} اقلام و تعدادهای واردشده حفظ شده‌اند.`); return false; }
        finally { refreshing = false; byId('sr-refresh').textContent = 'به‌روزرسانی موجودی'; updateSummary(); }
    }

    resultsBody.addEventListener('change', event => {
        const group = event.target.closest('[data-group]');
        if (group) { group.closest('details').querySelectorAll('[data-pick]').forEach(input => { const row = results.find(row => Number(row.id) === Number(input.dataset.pick)); if (row) pick(row, group.checked); }); renderResults(); return; }
        const input = event.target.closest('[data-pick]'); if (!input) return;
        const row = results.find(row => Number(row.id) === Number(input.dataset.pick)); if (row) pick(row, input.checked); renderResults();
    });
    resultsBody.addEventListener('click', event => { const button = event.target.closest('[data-focus-row]'); if (button) focusRow(button.dataset.focusRow); });
    byId('sr-check-visible').addEventListener('change', event => { results.forEach(row => pick(row, event.target.checked)); renderResults(); });
    byId('sr-add-selected').addEventListener('click', () => {
        let first;
        pending.forEach((row, id) => { if (!selected.has(id) && selected.size < config.maxRows) { selected.set(id, { ...row, quantity: '1', serverError: '' }); first ??= id; } });
        pending.clear(); renderSelected(); renderResults(); notice('مدل‌ها به حواله اضافه شدند؛ تعداد هر ردیف را بررسی کنید.'); if (first) focusRow(first);
    });
    body.addEventListener('input', event => {
        const input = event.target.closest('[data-quantity]'); if (!input) return;
        const row = selected.get(Number(input.closest('[data-selected-id]').dataset.selectedId));
        if (row) { row.quantity = input.value; row.serverError = ''; updateSummary(); }
    });
    body.addEventListener('focusin', event => { if (event.target.matches('[data-quantity]')) event.target.select(); });
    body.addEventListener('keydown', event => {
        if (event.key !== 'Enter' || !event.target.matches('[data-quantity]')) return;
        event.preventDefault();
        const inputs = [...body.querySelectorAll('tr:not([hidden]) [data-quantity]')];
        inputs[inputs.indexOf(event.target) + 1]?.focus();
    });
    body.addEventListener('click', event => {
        const button = event.target.closest('[data-remove]'); if (!button) return;
        selected.delete(Number(button.dataset.remove)); renderSelected(); renderResults();
    });
    byId('sr-selected-search').addEventListener('input', filterSelected);
    byId('sr-apply-quantity').addEventListener('click', () => {
        const raw = latin(byId('sr-bulk-quantity').value).trim();
        if (!/^\d{1,7}$/.test(raw) || Number(raw) < 1 || Number(raw) > 1000000) { notice('برای اعمال تعداد یکسان، عدد صحیح بیشتر از صفر وارد کنید.'); byId('sr-bulk-quantity').focus(); return; }
        selected.forEach(row => { row.quantity = raw; row.serverError = ''; }); renderSelected(); notice('تعداد برای همهٔ ردیف‌های حواله اعمال شد؛ ردیف‌های دارای کسری موجودی را اصلاح کنید.');
    });
    byId('sr-search').addEventListener('input', () => { clearTimeout(timer); controller?.abort(); sequence++; timer = setTimeout(() => loadCatalog(true), 300); });
    byId('sr-category').addEventListener('change', () => { clearTimeout(timer); categoryOptions(); setProduct(null); });
    byId('sr-subcategory').addEventListener('change', () => { clearTimeout(timer); setProduct(null); });
    byId('sr-available-only').addEventListener('change', () => { clearTimeout(timer); loadCatalog(true); });
    byId('sr-more').addEventListener('click', () => loadCatalog(false));
    byId('sr-retry').addEventListener('click', () => loadCatalog(results.length === 0));
    byId('sr-refresh').addEventListener('click', async () => { if (await refreshSelected()) notice('موجودی‌ها به‌روز شدند؛ تعدادهای شما حفظ شده‌اند.'); });
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (submitting || refreshing || !selected.size) return;
        submitting = true; updateSummary(); byId('sr-submit').textContent = 'بررسی موجودی…';
        const refreshed = await refreshSelected();
        byId('sr-selected-search').value = ''; filterSelected();
        if (!refreshed || [...selected.values()].some(row => validation(row))) {
            submitting = false; updateSummary(); byId('sr-submit').textContent = 'ثبت انتقال به شوروم';
            if (refreshed) { notice('تعداد ردیف‌های مشخص‌شده را بررسی کنید؛ حواله هنوز ثبت نشده است.'); const first = [...selected.values()].find(row => validation(row)); if (first) { focusRow(first.id); form.reportValidity(); } }
            return;
        }
        if (!byId('sr-date').validity.valid) document.querySelector('.sr-meta').open = true;
        if (!form.reportValidity()) { submitting = false; updateSummary(); byId('sr-submit').textContent = 'ثبت انتقال به شوروم'; return; }
        body.querySelectorAll('[data-quantity]').forEach(input => { input.value = latin(input.value).trim(); });
        byId('sr-submit').textContent = 'در حال ثبت انتقال…';
        HTMLFormElement.prototype.submit.call(form);
    });

    (config.selected || []).forEach((row, i) => {
        const messages = Object.entries(config.errors || {}).filter(([key]) => key.startsWith(`items.${i}.`)).flatMap(([, value]) => value).join(' ');
        if (!selected.has(Number(row.id))) selected.set(Number(row.id), { ...row, quantity: String(row.quantity ?? ''), serverError: messages });
    });
    renderSelected(); loadCatalog(true);
})();
