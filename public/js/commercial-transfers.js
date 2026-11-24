(() => {
    'use strict';
    const root = document.getElementById('transferWorkspace');
    if (!root) return;
    const editor = root.querySelector('#transferEditor');
    const detail = root.querySelector('#transferDetail');
    const form = root.querySelector('#transferDocumentForm');
    const results = root.querySelector('#transferResults');
    const selection = root.querySelector('[data-selection]');
    const selected = new Map();
    const fa = value => new Intl.NumberFormat('fa-IR', {useGrouping: false}).format(value);
    let editing = null, busy = false, searchRequest, listRequest, searchPage = 1, pages = 1, searchTimer, filterTimer, searchSequence = 0;
    const csrf = form.querySelector('[name=_token]').value;
    const field = name => form.elements.namedItem(name);
    const message = error => error.message || 'ارتباط برقرار نشد. دوباره تلاش کنید.';
    function toast(text) {
        const el = root.querySelector('[data-toast]');
        el.textContent = text; el.hidden = false;
        clearTimeout(toast.timer); toast.timer = setTimeout(() => el.hidden = true, 5000);
    }
    async function request(url, options = {}) {
        const response = await fetch(url, {credentials: 'same-origin', ...options,
            headers: {'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...options.headers}});
        if (response.redirected) throw new Error('نشست شما تغییر کرده است؛ صفحه را تازه‌سازی کنید.');
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            const messages = Object.values(data.errors || {}).flat();
            throw new Error(messages[0] || (response.status === 419 ? 'نشست منقضی شده است؛ صفحه را تازه‌سازی کنید.' : data.message) || 'دریافت اطلاعات ناموفق بود.');
        }
        return response;
    }
    function count() {
        const holder = root.querySelector('[data-selected-count]');
        holder.textContent = `${fa(selected.size)} مورد انتخاب‌شده`;
        selected.forEach(item => {
            const button = document.createElement('button'); button.type = 'button'; button.className = 'selected-chip';
            button.textContent = `${item.label} ×`; button.setAttribute('aria-label', `حذف انتخاب ${item.label}`);
            button.addEventListener('click', () => { if (busy) return; selected.delete(item.id); count(); selection.querySelectorAll('input').forEach(check => { if (Number(check.dataset.id) === item.id) check.checked = false; }); });
            holder.append(button);
        });
    }
    function setBusy(value) {
        busy = value;
        root.querySelectorAll('dialog button, dialog input, dialog select, dialog textarea').forEach(el => el.disabled = value);
        if (!value && editing) root.querySelectorAll('[name=kind]').forEach(el => el.disabled = el.value !== editing.kind);
    }
    async function loadList(url = location.href, push = false) {
        listRequest?.abort(); listRequest = new AbortController();
        results.setAttribute('aria-busy', 'true');
        try {
            const response = await request(url, {signal: listRequest.signal, headers: {Accept: 'text/html'}});
            results.innerHTML = await response.text();
            if (push) history.pushState({}, '', url);
        } catch (e) { if (e.name !== 'AbortError') toast(message(e)); }
        finally { results.removeAttribute('aria-busy'); }
    }
    function filter() {
        const query = new URLSearchParams(new FormData(root.querySelector('[data-filters]')));
        loadList(`${root.dataset.index}?${query}`, true);
    }
    function selectionRow(item) {
        const label = document.createElement('label'); label.className = 'selection-row';
        const check = document.createElement('input'); check.type = 'checkbox'; check.dataset.id = item.id; check.checked = selected.has(item.id);
        const text = document.createElement('span'); text.textContent = item.label;
        const small = document.createElement('small'); small.textContent = item.detail || ''; text.append(small);
        label.append(check, text);
        check.addEventListener('change', () => {
            if (check.checked && selected.size >= 100) { check.checked = false; toast('هر سند حداکثر ۱۰۰ مورد دارد.'); return; }
            if (check.checked) selected.set(item.id, item); else selected.delete(item.id);
            count();
        });
        return label;
    }
    async function search(append = false) {
        clearTimeout(searchTimer);
        searchRequest?.abort(); const controller = new AbortController(); searchRequest = controller;
        const sequence = ++searchSequence;
        const requestedPage = append ? searchPage + 1 : 1;
        if (!append) { searchPage = 0; pages = 0; root.querySelector('[data-more]').hidden = true; }
        root.querySelector('[data-form-error]').textContent = '';
        if (!append) { const loading = document.createElement('p'); loading.className = 'empty'; loading.textContent = 'در حال دریافت موارد…'; selection.replaceChildren(loading); }
        const query = new URLSearchParams({kind: field('kind').value, from_id: field('from_id').value,
            q: root.querySelector('[data-item-search]').value, page: requestedPage});
        selection.setAttribute('aria-busy', 'true');
        root.querySelector('[data-more]').disabled = true;
        try {
            const response = await request(`${root.dataset.search}?${query}`, {signal: controller.signal});
            const data = await response.json();
            if (sequence !== searchSequence) return;
            pages = data.pages; searchPage = data.page;
            if (!append) selection.replaceChildren();
            data.data.forEach(item => selection.append(selectionRow(item)));
            if (!data.total) {
                const empty = document.createElement('p'); empty.className = 'empty'; empty.textContent = data.empty_message || 'موردی با این مشخصات پیدا نشد.'; selection.append(empty);
                if (data.can_choose_unassigned) {
                    const button = document.createElement('button'); button.type = 'button'; button.className = 'load-button'; button.textContent = 'نمایش مشتریان بدون مسئول';
                    button.addEventListener('click', () => { if (busy) return; field('from_id').value = '0'; selected.clear(); count(); root.querySelector('[data-item-search]').value = ''; search(); });
                    selection.append(button);
                }
            }
            root.querySelector('[data-more]').hidden = searchPage >= pages;
        } catch (e) { if (sequence === searchSequence && e.name !== 'AbortError') { if (!append) selection.replaceChildren(); root.querySelector('[data-form-error]').textContent = message(e); } }
        finally { if (sequence === searchSequence) { selection.removeAttribute('aria-busy'); root.querySelector('[data-more]').disabled = false; root.querySelector('[data-more]').hidden = searchPage >= pages; } }
    }
    function effect() {
        root.querySelector('[data-effect]').textContent = field('kind').value === 'customer'
            ? 'فقط مسئول مشتری تغییر می‌کند. فروشندهٔ فاکتورهای قبلی و مانده حساب ثابت می‌ماند.'
            : 'فقط فروشندهٔ فاکتورهای انتخابی و پیش‌فاکتور متصل تغییر می‌کند؛ مسئول پروندهٔ مشتری ثابت می‌ماند. آثار پورسانت طبق روال مالی فعلی محاسبه می‌شود.';
    }
    function reset() {
        form.reset(); editing = null; selected.clear(); count();
        root.querySelectorAll('[name=kind]').forEach(el => el.disabled = false);
        const activeKind = root.querySelector('[data-filters] [name=kind]').value;
        if (activeKind && root.querySelector(`[name=kind][value="${activeKind}"]`)) field('kind').value = activeKind;
        field('submission_key').value = crypto.randomUUID();
        root.querySelector('[data-form-error]').textContent = '';
        root.querySelector('[data-item-search]').value = '';
        root.querySelector('#transferEditorTitle').textContent = 'ثبت انتقال جدید';
    }
    async function show(url) {
        detail.querySelector('[data-detail]').textContent = 'در حال دریافت…'; detail.showModal();
        try { const response = await request(url, {headers: {Accept: 'text/html'}}); detail.querySelector('[data-detail]').innerHTML = await response.text(); }
        catch(e) { detail.querySelector('[data-detail]').textContent = message(e); }
    }
    root.addEventListener('click', async event => {
        const button = event.target.closest('button,a'); if (!button || busy) return;
        if (button.matches('[data-new]')) { reset(); effect(); editor.showModal(); search(); }
        if (button.matches('[data-close]')) button.closest('dialog').close();
        if (button.matches('[data-kind]')) { root.querySelector('[data-filters] [name=kind]').value = button.dataset.kind; filter(); }
        if (button.matches('[data-page]')) { event.preventDefault(); loadList(button.href, true); }
        if (button.matches('[data-more]')) { if (searchPage < pages) search(true); }
        if (button.matches('[data-show]')) show(button.dataset.show);
        if (button.matches('[data-edit]')) {
            try {
                const response = await request(`${button.dataset.edit}?format=json`); const data = await response.json();
                if (data.document.from_id && ![...field('from_id').options].some(option => option.value === String(data.document.from_id))) throw new Error('همکار مبدأ این پیش‌نویس غیرفعال است؛ سند و تاریخچه حفظ شده‌اند، اما ثبت از حساب غیرفعال مجاز نیست.');
                reset(); editing = data.document;
                field('kind').value = editing.kind; field('from_id').value = editing.from_id || 0; field('to_id').value = editing.to_id;
                field('reason').value = editing.reason;
                editing.items.forEach(item => selected.set(item.target_id, {id: item.target_id, token: item.token, label: item.label})); count();
                root.querySelectorAll('[name=kind]').forEach(el => el.disabled = el.value !== editing.kind);
                root.querySelector('#transferEditorTitle').textContent = `ویرایش سند ${fa(editing.number)}`;
                detail.close(); effect(); editor.showModal(); search();
            } catch(e) { toast(message(e)); }
        }
    });
    root.addEventListener('change', event => {
        if (event.target.matches('[data-filters] select')) filter();
        if (event.target.matches('#transferDocumentForm [name=kind],#transferDocumentForm [name=from_id]')) {
            root.querySelector('[data-item-search]').value = '';
            selected.clear(); count(); effect(); search();
        }
    });
    root.addEventListener('input', event => {
        if (event.target.matches('[data-filters] [name=q]')) { clearTimeout(filterTimer); filterTimer = setTimeout(filter, 300); }
        if (event.target.matches('[data-item-search]')) { clearTimeout(searchTimer); searchTimer = setTimeout(() => search(), 300); }
    });
    root.addEventListener('submit', async event => {
        if (event.target.matches('[data-filters]')) { event.preventDefault(); filter(); return; }
        if (event.target === form) {
            event.preventDefault(); if (busy) return;
            const error = root.querySelector('[data-form-error]'); error.textContent = '';
            if (!selected.size) { error.textContent = 'حداقل یک مورد را انتخاب کنید.'; return; }
            const payload = {submission_key: field('submission_key').value, kind: field('kind').value,
                from_id: field('from_id').value, to_id: field('to_id').value, reason: field('reason').value,
                action: event.submitter?.value || 'register', items: [...selected.values()].map(({id,token}) => ({id,token}))};
            if (editing) payload.version = editing.version;
            setBusy(true);
            try {
                const response = await request(editing ? `${root.dataset.store}/${editing.id}` : root.dataset.store,
                    {method: editing ? 'PUT' : 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)});
                const data = await response.json(); editor.close(); toast(data.message); await loadList();
            } catch(e) { error.textContent = message(e); }
            finally { setBusy(false); }
        }
        if (event.target.matches('[data-document-action]')) {
            event.preventDefault(); if (busy) return;
            if (event.target.hasAttribute('data-confirm') && !confirm('این انتقال لغو و مسئول قبلی بازگردانده شود؟')) return;
            const payload = Object.fromEntries(new FormData(event.target));
            const method = event.target.dataset.method || 'POST'; setBusy(true);
            try {
                const response = await request(event.target.action, {method, headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload)});
                const data = await response.json(); detail.close(); toast(data.message); await loadList();
            } catch(e) { detail.querySelector('[data-detail-error]').textContent = message(e); }
            finally { setBusy(false); }
        }
    });
    [editor, detail].forEach(dialog => {
        dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
        dialog.addEventListener('click', event => { if (event.target === dialog && !busy) { const r = dialog.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) dialog.close(); } });
    });
    window.addEventListener('popstate', () => loadList());
})();
