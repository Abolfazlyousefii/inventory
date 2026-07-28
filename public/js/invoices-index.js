(() => {
    'use strict';
    const app = document.getElementById('invoiceLiveApp');
    if (!app) return;

    const stateNode = document.getElementById('invoiceInitialState');
    const initial = JSON.parse(stateNode?.textContent || '{}');
    const el = {
        code: document.getElementById('invoiceOrderCode'), customerSearch: document.getElementById('invoiceCustomerSearch'),
        customerId: document.getElementById('invoiceCustomerId'), customerResults: app.querySelector('.customer-picker__results'),
        from: document.getElementById('invoiceDateFrom'), to: document.getElementById('invoiceDateTo'), summary: document.getElementById('invoiceSummary'),
        desktop: document.getElementById('invoiceDesktopRows'), mobile: document.getElementById('invoiceMobileCards'),
        skeleton: document.getElementById('invoiceSkeleton'), empty: document.getElementById('invoiceEmpty'), error: document.getElementById('invoiceLiveError'),
        retry: document.getElementById('invoiceRetry'), clear: document.getElementById('invoiceClearFilters'), sentinel: document.getElementById('invoiceLoadSentinel'),
        more: document.getElementById('invoiceLoadMore'), results: app.querySelector('.invoice-results'), customerClear: document.getElementById('invoiceCustomerClear'), loadStatus: document.getElementById('invoiceLoadStatus')
    };
    let filters = Object.assign({order_code:'', customer_id:'', date_from:'', date_to:'', quick_range:''}, initial.filters || {});
    let cursor = null, hasMore = false, loading = false, requestId = 0, controller = null, customerController = null;
    let debounceTimer = null, customerTimer = null;
    const digits = value => String(value || '').replace(/[۰-۹٠-٩]/g, char => '۰۱۲۳۴۵۶۷۸۹'.includes(char) ? '۰۱۲۳۴۵۶۷۸۹'.indexOf(char) : '٠١٢٣٤٥٦٧٨٩'.indexOf(char));
    const debounceLoad = () => { clearTimeout(debounceTimer); debounceTimer = setTimeout(() => load(false), 320); };

    function hydrate() {
        el.code.value = filters.order_code || ''; el.from.value = filters.date_from || ''; el.to.value = filters.date_to || '';
        el.customerId.value = filters.customer_id || '';
        if (initial.customer) el.customerSearch.value = customerLabel(initial.customer);
        el.customerClear.hidden = !el.customerId.value;
        setActiveRange();
    }
    const customerLabel = c => `${c.name}${c.mobile ? ` · ${c.mobile}` : ''}${c.code ? ` · ${c.code}` : ''}`;
    function currentFilters() {
        return {order_code: digits(el.code.value.trim()), customer_id: el.customerId.value, date_from: digits(el.from.value.trim()), date_to: digits(el.to.value.trim()), quick_range: filters.quick_range || ''};
    }
    function setActiveRange() { app.querySelectorAll('[data-range]').forEach(button => button.classList.toggle('is-active', button.dataset.range === filters.quick_range)); }
    function syncUrl(serverFilters) {
        const url = new URL(app.dataset.indexUrl, location.origin);
        Object.entries(serverFilters || currentFilters()).forEach(([key,value]) => { if (value !== '' && value != null) url.searchParams.set(key, value); });
        history.replaceState({}, '', url.pathname + url.search);
    }
    function showErrors(errors = {}) {
        app.querySelectorAll('.field-error').forEach(node => node.textContent = '');
        Object.entries(errors).forEach(([key, messages]) => { const node = app.querySelector(`[data-error-for="${key}"]`); if (node) node.textContent = messages[0] || ''; });
    }
    async function load(append) {
        if (loading && append) return;
        if (controller) controller.abort();
        controller = new AbortController(); const id = ++requestId; loading = true;
        if (!append) { cursor = null; el.skeleton.hidden = false; el.empty.hidden = true; showErrors(); }
        else el.loadStatus.textContent = 'در حال دریافت فاکتورهای بیشتر...';
        el.error.hidden = true; el.results.setAttribute('aria-busy', 'true');
        const params = new URLSearchParams(currentFilters()); params.set('limit', '40');
        if (append && cursor) params.set('cursor', cursor); else params.set('include_summary', '1');
        try {
            const response = await fetch(`${app.dataset.endpoint}?${params}`, {headers:{Accept:'application/json'}, signal:controller.signal});
            const payload = await response.json();
            if (id !== requestId) return;
            if (!response.ok) { if (response.status === 422) showErrors(payload.errors); throw new Error('request_failed'); }
            if (append) { el.desktop.insertAdjacentHTML('beforeend', payload.desktop_html); el.mobile.insertAdjacentHTML('beforeend', payload.mobile_html); }
            else { el.desktop.innerHTML = payload.desktop_html; el.mobile.innerHTML = payload.mobile_html; el.summary.innerHTML = payload.summary_html || ''; }
            cursor = payload.next_cursor; hasMore = Boolean(payload.has_more); filters = Object.assign(filters, payload.filters || {});
            if (payload.filters?.date_from) el.from.value = payload.filters.date_from;
            if (payload.filters?.date_to) el.to.value = payload.filters.date_to;
            el.empty.hidden = append || Boolean(payload.desktop_html.trim()); el.loadStatus.textContent = hasMore ? '' : 'همه فاکتورها نمایش داده شدند.'; syncUrl(payload.filters); bindCancelButtons();
        } catch (error) {
            if (error.name !== 'AbortError') { el.error.hidden = false; if (append) el.loadStatus.textContent = 'دریافت فاکتورهای بیشتر با خطا روبه‌رو شد.'; }
        } finally {
            if (id === requestId) { loading = false; el.skeleton.hidden = true; el.results.setAttribute('aria-busy', 'false'); el.more.hidden = !hasMore || 'IntersectionObserver' in window; }
        }
    }
    [el.code, el.from, el.to].forEach(input => input.addEventListener('input', () => { filters.quick_range = ''; setActiveRange(); debounceLoad(); }));
    app.querySelectorAll('[data-range]').forEach(button => button.addEventListener('click', () => { filters.quick_range = button.dataset.range; el.from.value = ''; el.to.value = ''; setActiveRange(); load(false); }));
    el.clear.addEventListener('click', () => { filters = {order_code:'',customer_id:'',date_from:'',date_to:'',quick_range:''}; el.code.value='';el.customerId.value='';el.customerSearch.value='';el.customerClear.hidden=true;el.from.value='';el.to.value='';setActiveRange();load(false);el.code.focus(); });
    app.querySelector('[data-clear-filters]').addEventListener('click', () => el.clear.click());
    el.retry.addEventListener('click', () => load(false)); el.more.addEventListener('click', () => hasMore && load(true));

    el.customerSearch.addEventListener('input', () => {
        if (el.customerId.value) { el.customerId.value = ''; filters.customer_id = ''; debounceLoad(); }
        clearTimeout(customerTimer); const q = el.customerSearch.value.trim(); if (q.length < 2) return closeCustomers();
        customerTimer = setTimeout(() => searchCustomers(q), 250);
    });
    async function searchCustomers(q) {
        if (customerController) customerController.abort(); customerController = new AbortController();
        try { const response = await fetch(`${app.dataset.customersEndpoint}?q=${encodeURIComponent(q)}`, {headers:{Accept:'application/json'},signal:customerController.signal}); const payload = await response.json(); if (!response.ok) return; el.customerResults.innerHTML = payload.items.map(c => `<button type="button" class="customer-picker__option" data-customer='${escapeJson(c)}'>${escapeHtml(c.name)}<small>${escapeHtml([c.mobile,c.code].filter(Boolean).join(' · '))}</small></button>`).join('') || '<div class="p-3 text-muted">مشتری یافت نشد.</div>'; el.customerResults.hidden=false; el.customerSearch.setAttribute('aria-expanded','true'); el.customerResults.querySelectorAll('button').forEach(button => button.addEventListener('click', () => selectCustomer(JSON.parse(button.dataset.customer)))); } catch(error) { if(error.name !== 'AbortError') closeCustomers(); }
    }
    function selectCustomer(customer) { el.customerId.value=customer.id; el.customerSearch.value=customerLabel(customer); el.customerClear.hidden=false; filters.customer_id=customer.id; closeCustomers(); load(false); }
    el.customerClear.addEventListener('click', () => { el.customerId.value='';el.customerSearch.value='';el.customerClear.hidden=true;filters.customer_id='';load(false);el.customerSearch.focus(); });
    function closeCustomers(){ el.customerResults.hidden=true; el.customerSearch.setAttribute('aria-expanded','false'); }
    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
    const escapeJson = value => escapeHtml(JSON.stringify(value));
    document.addEventListener('click', event => { if (!event.target.closest('#invoiceCustomerPicker')) closeCustomers(); });

    let cancelModal;
    function bindCancelButtons() { app.querySelectorAll('.js-invoice-cancel:not([data-bound])').forEach(button => { button.dataset.bound='1'; button.addEventListener('click', () => { const modal=document.getElementById('invoiceCancelModal'), form=modal.querySelector('#invoiceCancelForm'), confirm=modal.querySelector('#invoiceCancelConfirmation'), reason=modal.querySelector('#invoiceCancellationReason'), physical=modal.querySelector('#invoicePhysicalReturn'), submit=modal.querySelector('#invoiceCancelSubmit'), shipped=button.dataset.shipped==='1'; form.reset();form.action=button.dataset.url;modal.querySelector('[data-cancel-number]').textContent=button.dataset.number;modal.querySelector('[data-shipped-warning]').hidden=!shipped;confirm.placeholder=button.dataset.number; const validate=()=>{submit.disabled=!(reason.value.trim() && confirm.value.trim()===button.dataset.number && (!shipped || physical.checked));};[reason,confirm,physical].forEach(input=>{input.oninput=validate;input.onchange=validate;});validate();cancelModal=bootstrap.Modal.getOrCreateInstance(modal);cancelModal.show();setTimeout(()=>reason.focus(),150); }); }); }
    window.addEventListener('popstate', () => { const params=new URLSearchParams(location.search); filters={order_code:params.get('order_code')||'',customer_id:params.get('customer_id')||'',date_from:params.get('date_from')||'',date_to:params.get('date_to')||'',quick_range:params.get('quick_range')||''};el.code.value=filters.order_code;el.customerId.value=filters.customer_id;el.from.value=filters.date_from;el.to.value=filters.date_to;if(!filters.customer_id){el.customerSearch.value='';el.customerClear.hidden=true;}setActiveRange();load(false); });
    if ('IntersectionObserver' in window) new IntersectionObserver(entries => { if(entries[0].isIntersecting && hasMore && !loading) load(true); }, {rootMargin:'300px'}).observe(el.sentinel);
    hydrate(); load(false);
})();
