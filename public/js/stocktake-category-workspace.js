document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    const app = document.getElementById('stocktakeCategoryApp');
    if (!app) return;

    const embedded = app.dataset.embedded === '1';
    const category = document.getElementById(embedded ? 'category' : 'scCategory');
    const subcategory = document.getElementById(embedded ? 'subcategory' : 'scSubcategory');
    const show = document.getElementById('scShow');
    const date = embedded ? document.querySelector('[name="document_date"]') : document.getElementById('scDate');
    const more = document.getElementById('scMore');
    const statusNode = document.getElementById('scStatus');
    const productsNode = document.getElementById('scProducts');
    const liveSearch = document.getElementById('scLiveSearch');

    const groups = new Map();
    // Retain unsaved counts and saved draft links between searches.
    const entered = new Map();
    const saved = new Map();
    const bulkSave = document.getElementById('scSaveAll');
    const bulkFinalize = document.getElementById('scFinalizeAll');
    const bulkStatus = document.getElementById('scBulkStatus');
    let bulkDocId = Number(app.dataset.bulkDocumentId || 0);
    let bulkDirty = false;
    let bulkBusy = false;
    const newRequestKey = () => globalThis.crypto?.randomUUID?.() || null;
    let requestKey = null;
    let activeQuery = '';
    let searchTimer = null;
    let selectionId = 0;
    let afterId = 0;
    let hasMore = false;
    let loading = false;
    let loadingController = null;
    let sequence = 0;

    const fa = n => Number(n || 0).toLocaleString('fa-IR');
    const make = (tag, className, value) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (value !== undefined && value !== null) node.textContent = String(value);
        return node;
    };
    const notice = (message, error = false) => {
        statusNode.textContent = message;
        statusNode.classList.toggle('is-error', error);
    };

    function containsUnsavedCounts() {
        if (embedded) return bulkDirty || (!bulkDocId && [...entered.values()].some(values =>
            [...values.values()].some(v => v !== '')));
        return [...entered].some(([productId, values]) => !saved.has(productId) &&
            [...values.values()].some(v => v !== ''));
    }

    function reset() {
        clearTimeout(searchTimer);
        entered.clear();
        saved.clear();
        bulkDocId = 0;
        bulkDirty = false;
        requestKey = null;
        if (liveSearch) liveSearch.value = '';
        activeQuery = '';
        clearListing();
        selectionId = 0;
    }

    function clearListing() {
        sequence++;
        loadingController?.abort();
        loadingController = null;
        loading = false;
        afterId = 0;
        hasMore = false;
        groups.clear();
        productsNode.replaceChildren();
        more.hidden = true;
        more.disabled = false;
    }

    function groupFor(row) {
        const id = Number(row.product_id);
        if (groups.has(id)) return groups.get(id);

        const card = make('section','sc-product');
        const header = make('header','sc-product-head');
        const headerTitle = make('div');
        headerTitle.append(make('strong','',row.product_name));
        const meta = make('div','sc-product-meta','محصول شماره ' + fa(id));
        headerTitle.append(meta);
        header.append(headerTitle);
        const flag = make('span','sc-muted','در انتظار شمارش');
        header.append(flag);
        card.append(header);

        const wrap = make('div','sc-table-wrap');
        const table = make('table');
        const head = make('thead');
        const trHead = make('tr');
        ['تنوع کالا','موجودی انبار مرکزی','رزرو فعال','موجودی در صف جمع‌آوری','موجودی واقعی','اختلاف'].forEach(label =>
            trHead.append(make('th','',label)));
        head.append(trHead);
        const tbody = make('tbody');
        table.append(head,tbody);
        wrap.append(table);
        card.append(wrap);

        const footer = make('div','sc-footer');
        const feedback = make('div','sc-feedback','هنوز پیش‌نویسی ذخیره نشده است.');
        const button = make('button','btn btn-sm btn-success','ذخیره پیش‌نویس این محصول');
        button.type = 'button';
        button.dataset.saveProduct = String(id);
        button.disabled = true;
        if (!embedded) {
            footer.append(feedback,button);
            card.append(footer);
        }
        productsNode.append(card);

        const stored = saved.get(id);
        const group = {id,name:row.product_name,rows:new Map(),quantities:entered.get(id) || new Map(),
            tbody,button,feedback,flag,saved:Boolean(stored),saving:false};
        if (!entered.has(id)) entered.set(id,group.quantities);
        if (stored) {
            button.disabled = true;
            button.textContent = 'ذخیره شده';
            flag.textContent = 'پیش‌نویس ذخیره شد';
            feedback.replaceChildren();
            const link = make('a','sc-success','بازکردن پیش‌نویس این محصول');
            link.href = stored;
            feedback.append(link);
        }
        groups.set(id,group);
        return group;
    }

    function addRow(row) {
        const group = groupFor(row);
        const id = Number(row.variant_id);
        if (group.rows.has(id)) return;
        group.rows.set(id,row);
        const tr = make('tr');
        const description = make('td','sc-variant',row.name || 'تنوع بدون نام');
        description.append(make('small','',row.sku || ''));
        tr.append(description);
        tr.append(make('td','sc-number',fa(row.system_available)));
        tr.append(make('td','sc-number',fa(row.active_reserved)));
        const queue = make('td','sc-number');
        queue.append(make('span',Number(row.queue_total)>0?'sc-badge':'sc-muted',fa(row.queue_total)));
        if (Number(row.queue_total) > 0) {
            queue.append(make('small','sc-flow-details','انتظار: '+fa(row.pending_collection)+
                ' · تحویل انبار: '+fa(row.warehouse_received)+' · جمع‌آوری: '+fa(row.collecting)));
        }
        tr.append(queue);
        const actualCell = make('td');
        const input = make('input','sc-actual');
        input.type = 'text';
        input.inputMode = 'numeric';
        input.autocomplete = 'off';
        input.placeholder = 'شمارش نشده';
        input.maxLength = 12;
        input.dataset.product = String(group.id);
        input.dataset.variant = String(id);
        input.setAttribute('aria-label','موجودی واقعی ' + group.name + ' - ' + (row.name || 'تنوع'));
        const remembered = group.quantities.get(id);
        if (remembered !== undefined) input.value = remembered;
        if (group.saved && !embedded) input.disabled = true;
        actualCell.append(input);
        tr.append(actualCell);
        const diff = make('td','sc-number sc-difference','—');
        diff.dataset.differenceFor = String(id);
        tr.append(diff);
        group.tbody.append(tr);
        if (!group.saved || embedded) group.flag.textContent = fa(group.rows.size) + ' تنوع';
        updateDifference(group,id);
    }

    function updateDifference(group, variantId) {
        const variant = group.rows.get(variantId);
        const actual = group.quantities.get(variantId);
        const cell = [...group.tbody.querySelectorAll('[data-difference-for]')]
            .find(x => x.dataset.differenceFor === String(variantId));
        if (!cell || !variant) return;
        if (actual === undefined || actual === '') {
            cell.textContent = '—';
            cell.classList.remove('sc-diff-up','sc-diff-down');
            return;
        }
        const difference = Number(actual) - Number(variant.expected_physical);
        cell.textContent = (difference > 0 ? '+' : '') + fa(difference);
        cell.classList.toggle('sc-diff-up',difference > 0);
        cell.classList.toggle('sc-diff-down',difference < 0);
    }

    function updateButtons() {
        if (embedded) {
            const total = [...groups.values()].reduce((n, g) => n + g.rows.size, 0);
            const counted = [...entered.values()].reduce((n, vals) =>
                n + [...vals.values()].filter(v => v !== '').length, 0);
            const ready = selectionId > 0 && !loading && !hasMore && !bulkBusy;
            bulkSave.disabled = !ready;
            // The server always validates the whole saved category snapshot,
            // including variants hidden by a search.
            bulkFinalize.disabled = !ready || !bulkDocId || counted < total || Boolean(activeQuery);
            bulkStatus.textContent = bulkBusy ? 'در حال ثبت عملیات؛ صفحه را نبندید.' :
                ('شمارش ' + fa(counted) + ' تنوع' +
                 (bulkDocId ? ' · سند شماره ' + fa(bulkDocId) + ' ذخیره شده' : ' · هنوز پیش‌نویس کلی ذخیره نشده') +
                 (bulkDirty ? ' · تغییرات ذخیره‌نشده' : ''));
            more.hidden = !hasMore;
            more.disabled = loading;
            return;
        }
        for (const group of groups.values()) {
            const counted = [...group.quantities.values()].filter(v => v !== '').length;
            group.button.disabled = group.saved || group.saving || loading || hasMore;
            if (!group.saved) group.feedback.textContent = fa(counted) + ' از ' + fa(group.rows.size)
                + ' تنوع شمارش شده · ' + (hasMore || loading ? 'ابتدا همه ردیف‌های دسته را دریافت کنید.' :
                'مقادیر خالی فقط در مرحله تأیید نهایی سند تعیین تکلیف می‌شوند.');
        }
        more.hidden = !hasMore;
        more.disabled = loading;
    }

    async function requestPage() {
        if (loading || !hasMore || !selectionId) return;
        loading = true;
        const current = sequence;
        const controller = new AbortController();
        loadingController = controller;
        show.disabled = true;
        updateButtons();
        notice('در حال دریافت تنوع‌های دسته... (هر درخواست حداکثر ۶۰ ردیف)');
        try {
            const url = new URL(app.dataset.rowsUrl, location.origin);
            url.searchParams.set('category_id',String(selectionId));
            url.searchParams.set('after_id',String(afterId));
            url.searchParams.set('limit','60');
        if (activeQuery) url.searchParams.set('q',activeQuery);
            const response = await fetch(url, {
                method:'GET',credentials:'same-origin',
                headers:{'Accept':'application/json'},signal:controller.signal,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.message || 'دریافت محصولات دسته ناموفق بود.');
            if (!Array.isArray(payload.rows) || (payload.has_more && payload.rows.length === 0)) {
                throw new Error('صفحه‌بندی دسته معتبر نیست.');
            }
            const next = Number(payload.next_cursor);
            if (payload.has_more && (!Number.isSafeInteger(next) || next <= afterId)) {
                throw new Error('شناسه ادامه دریافت کالاها نامعتبر است.');
            }
            if (current !== sequence) return;

            payload.rows.forEach(addRow);
            afterId = next;
            hasMore = Boolean(payload.has_more);
            notice(hasMore
                ? fa([...groups.values()].reduce((n,g)=>n+g.rows.size,0)) + ' تنوع دریافت شد. برای ادامه «نمایش تنوع‌های بیشتر» را بزنید.'
                : (activeQuery ? 'نتایج جستجو کامل شد. مقادیر شمارش در جستجوهای بعدی حفظ می‌شوند.' :
                    'همه تنوع‌های دسته دریافت شدند. از نوار پایین صفحه پیش‌نویس کلی را ذخیره کنید.'));
        } catch(error) {
            if (current===sequence && error.name !== 'AbortError') {
                notice(error.message || 'دریافت ناموفق بود؛ برای تلاش مجدد روی نمایش بیشتر کلیک کنید.',true);
                // Preserve cursor and already loaded inputs.
                hasMore = true;
            }
        } finally {
            if (current===sequence) {
                loading = false;
                loadingController = null;
                show.disabled = false;
                updateButtons();
            }
        }
    }

    async function begin() {
        const selected = Number(subcategory.value || category.value);
        if (!Number.isSafeInteger(selected) || selected < 1) {
            notice('ابتدا دسته‌بندی یا زیردسته‌بندی را انتخاب کنید.',true);
            return;
        }
        if ((containsUnsavedCounts() || (embedded && bulkDocId)) &&
            !window.confirm('با تغییر دسته، اطلاعات واردشده در این صفحه کنار گذاشته می‌شود. ادامه می‌دهید؟')) return;
        reset();
        if (embedded) {
            app.hidden = false;
            document.querySelectorAll('[data-stocktake-single]').forEach(node => { node.hidden = true; });
            document.getElementById('stocktakeForm').dataset.categoryMode = '1';
        }
        selectionId = selected;
        hasMore = true;
        await requestPage();
    }

    if (liveSearch) liveSearch.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            if (!selectionId) return;
            const next = liveSearch.value.trim();
            if (next === activeQuery) return;
            activeQuery = next;
            clearListing();
            hasMore = true;
            requestPage();
        }, 320);
    });

    if (!embedded) category.addEventListener('change',async () => {
        subcategory.replaceChildren(new Option('همه زیردسته‌ها',''));
        subcategory.disabled = !category.value;
        if (!category.value) return;
        const id = category.value;
        try {
            const url = new URL(app.dataset.subcategoriesUrl,location.origin);
            url.searchParams.set('category_id',id);
            const response = await fetch(url,{credentials:'same-origin',headers:{'Accept':'application/json'}});
            if (!response.ok) throw new Error('بارگذاری زیردسته‌ها ممکن نشد.');
            const entries = await response.json();
            if (category.value !== id) return;
            for(const child of entries) subcategory.add(new Option(child.name,child.id));
        } catch(error) { notice(error.message,true); }
    });

    productsNode.addEventListener('input', event => {
        const field = event.target.closest('.sc-actual');
        if (!field) return;
        const group = groups.get(Number(field.dataset.product));
        if (!group || (group.saved && !embedded)) return;
        const normalized = field.value.replace(/[۰-۹]/g, d => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
            .replace(/[٠-٩]/g,d => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))).trim();
        const valid = normalized === '' || (/^\d+$/.test(normalized) &&
            Number.isSafeInteger(Number(normalized)) && Number(normalized) <= 2147483647);
        field.setCustomValidity(valid?'':'فقط عدد صحیح نامنفی مجاز است.');
        if (!valid) {
            if (embedded) { bulkSave.disabled = true; bulkFinalize.disabled = true; notice('مقدار شمارش معتبر نیست.', true); }
            else { group.button.disabled = true; group.feedback.textContent = 'مقدار شمارش معتبر نیست.'; }
            return;
        }
        field.value = normalized;
        group.quantities.set(Number(field.dataset.variant),normalized);
        entered.set(group.id,group.quantities);
        if (embedded) bulkDirty = true;
        updateDifference(group,Number(field.dataset.variant));
        updateButtons();
    });

    async function saveProduct(id) {
        const group = groups.get(id);
        if (!group || group.saved || group.saving || loading || hasMore) return;
        if (!date.value) { notice('تاریخ سند را مشخص کنید.',true); date.focus(); return; }
        const counted = [...group.quantities.values()].filter(v=>v!=='').length;
        // A completely blank draft is also valid; no stock changes on draft save.
        const missing = group.rows.size - counted;
        if (!window.confirm('برای «' + group.name + '» پیش‌نویس جداگانه ساخته شود؟ ' +
            (missing || activeQuery ? 'مقادیر واردنشده در سند پیش‌نویس «شمارش‌نشده» می‌مانند و برای ثبت نهایی باید تعیین تکلیف شوند. ':'') +
            'این کار موجودی انبار را تغییر نمی‌دهد.')) return;

        group.saving = true;
        updateButtons();
        group.feedback.textContent = 'در حال ذخیره پیش‌نویس...';
        const payload = {
            product_id:id,document_date:date.value,
            description:'انبارگردانی از نمای دسته‌ای',
            actual_quantities:Object.fromEntries([...group.quantities].filter(([,v])=>v!=='')),
        };
        try {
            const response = await fetch(app.dataset.storeUrl,{
                method:'POST',credentials:'same-origin',
                headers:{'Accept':'application/json','Content-Type':'application/json',
                    'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]')?.content||''},
                body:JSON.stringify(payload),
            });
            const result = await response.json().catch(()=>({}));
            if (!response.ok) {
                const validation = result.errors && Object.values(result.errors).flat().join('، ');
                throw new Error(validation || result.message || 'ذخیره پیش‌نویس ناموفق بود.');
            }
            if (!result.document_id || !result.edit_url) throw new Error('پاسخ ثبت سند معتبر نبود.');
            group.saved = true;
            saved.set(id,result.edit_url);
            group.tbody.querySelectorAll('.sc-actual').forEach(input=>input.disabled=true);
            group.flag.textContent = 'پیش‌نویس ذخیره شد';
            group.feedback.replaceChildren();
            const link = make('a','sc-success','مشاهده و بررسی سند شماره ' + fa(result.document_id));
            link.href=result.edit_url;
            group.feedback.append(link);
            group.button.textContent = 'ذخیره شد';
        } catch(error) {
            group.feedback.textContent = error.message || 'ذخیره پیش‌نویس ناموفق بود؛ دوباره تلاش کنید.';
            group.feedback.classList.add('is-error');
        } finally {
            group.saving = false;
            updateButtons();
        }
    }

    function categoryActuals() {
        const quantities = {};
        for (const values of entered.values()) {
            for (const [id, raw] of values.entries()) {
                // Send explicit null when a saved count was cleared; never
                // silently preserve an old number that the operator removed.
                quantities[id] = raw === '' ? null : Number(raw);
            }
        }
        return quantities;
    }

    async function categoryRequest(url, method, payload) {
        const response = await fetch(url,{
            method,credentials:'same-origin',
            headers:{
                'Accept':'application/json','Content-Type':'application/json',
                'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||'',
            },
            body:JSON.stringify(payload),
        });
        const json = await response.json().catch(()=>({}));
        if (!response.ok) {
            const details = json.errors && Object.values(json.errors).flat().join('، ');
            const conflict = json.conflicts?.[0];
            throw new Error((details || json.message || 'عملیات ناموفق بود.') +
                (conflict ? ' · ' + (conflict.variant_name || '') + ': ' + conflict.reason : ''));
        }
        return json;
    }

    function docUrl(template) {
        return template.replace('__DOC__',String(bulkDocId));
    }

    async function saveAll() {
        if (!embedded || bulkBusy || !selectionId || hasMore || loading) return;
        if (!date?.value) { notice('تاریخ سند را مشخص کنید.',true); date?.focus(); return; }
        if ([...productsNode.querySelectorAll('.sc-actual')].some(input => !input.checkValidity())) {
            notice('بعضی مقدارهای شمارش معتبر نیستند.',true); return;
        }
        if (!window.confirm('تمام شمارش‌های این دسته در یک پیش‌نویس ذخیره شود؟ این عملیات موجودی را تغییر نمی‌دهد.')) return;

        bulkBusy = true; updateButtons();
        try {
            if (!bulkDocId && !requestKey) requestKey = newRequestKey();
            if (!bulkDocId && !requestKey) throw new Error('مرورگر امکان ساخت شناسه امن درخواست را ندارد.');
            const isUpdate = bulkDocId > 0;
            const result = await categoryRequest(
                isUpdate ? docUrl(app.dataset.bulkUpdateTemplate) : app.dataset.bulkStoreUrl,
                isUpdate ? 'PUT' : 'POST',
                isUpdate
                    ? {actual_quantities:categoryActuals()}
                    : {category_id:selectionId,document_date:date.value,request_key:requestKey,
                       actual_quantities:categoryActuals()}
            );
            bulkDocId = Number(result.document_id);
            bulkDirty = false;
            if (result.edit_url) {
                history.replaceState(null,'',result.edit_url);
            }
            notice('پیش‌نویس کلی ذخیره شد. هیچ موجودی تغییر نکرده است.');
        } catch(error) {
            notice(error.message || 'ذخیره کلی ناموفق بود.',true);
        } finally {
            bulkBusy = false; updateButtons();
        }
    }

    async function finalizeAll() {
        if (!embedded || !bulkDocId || bulkBusy || hasMore || loading || activeQuery) return;
        if ([...productsNode.querySelectorAll('.sc-actual')].some(input => !input.checkValidity())) {
            notice('بعضی مقدارهای شمارش معتبر نیستند.',true); return;
        }
        const missing = [...groups.values()].reduce((n,g) =>
            n + [...g.rows.keys()].filter(id => !g.quantities.has(id) || g.quantities.get(id) === '').length, 0);
        if (missing) {
            notice(fa(missing)+' تنوع هنوز شمارش نشده است. خالی‌ها صفر محسوب نمی‌شوند.',true);
            return;
        }
        if (!window.confirm('تأیید نهایی تمام کالاهای این دسته؟ پس از تأیید، موجودی انبار مرکزی به‌صورت یکجا اصلاح می‌شود و دیگر امکان ویرایش سند وجود ندارد.')) return;
        bulkBusy = true; updateButtons();
        try {
            const result = await categoryRequest(docUrl(app.dataset.bulkFinalizeTemplate),
                'PATCH',{actual_quantities:categoryActuals()});
            bulkDirty = false;
            notice('انبارگردانی تمام کالاهای این دسته نهایی شد.');
            location.assign(app.dataset.indexUrl);
        } catch(error) {
            notice(error.message || 'تأیید نهایی ناموفق بود؛ هیچ اصلاحی اعمال نشد.',true);
        } finally {
            bulkBusy = false; updateButtons();
        }
    }

    bulkSave?.addEventListener('click',saveAll);
    bulkFinalize?.addEventListener('click',finalizeAll);

    productsNode.addEventListener('click', event => {
        const button = event.target.closest('[data-save-product]');
        if (button) saveProduct(Number(button.dataset.saveProduct));
    });
    show.addEventListener('click',begin);
    more.addEventListener('click',requestPage);

    if (embedded) {
        const originalForm = document.getElementById('stocktakeForm');
        originalForm.addEventListener('submit', event => {
            if (originalForm.dataset.categoryMode !== '1') return;
            event.preventDefault();
            event.stopImmediatePropagation();
            notice('برای ذخیره یا تأیید نهایی دسته‌ای از نوار شناور پایین همین صفحه استفاده کنید.', true);
        }, true);
        document.getElementById('scClose').addEventListener('click', () => {
            if (containsUnsavedCounts() && !window.confirm('شمارش‌های ذخیره‌نشده رها می‌شوند. به فرم محصول تکی برگردیم؟')) return;
            reset();
            app.hidden = true;
            document.querySelectorAll('[data-stocktake-single]').forEach(node => { node.hidden = false; });
            delete originalForm.dataset.categoryMode;
            show.focus();
        });
    }

    if (embedded && bulkDocId > 0 && Number(app.dataset.bulkCategoryId)>0) {
        const initial = JSON.parse(document.getElementById('scResumeCounts')?.textContent || '{}');
        for (const [productId, values] of Object.entries(initial)) {
            entered.set(Number(productId),new Map(
                Object.entries(values).map(([variantId,value])=>[Number(variantId),String(value)])
            ));
        }
        selectionId = Number(app.dataset.bulkCategoryId);
        app.hidden = false;
        document.querySelectorAll('[data-stocktake-single]').forEach(node => { node.hidden=true; });
        document.getElementById('stocktakeForm').dataset.categoryMode = '1';
        category.disabled = true;
        subcategory.disabled = true;
        show.disabled = true;
        hasMore = true;
        requestPage();
    }

    window.addEventListener('beforeunload',event => {
        if (!containsUnsavedCounts()) return;
        event.preventDefault();
        event.returnValue = '';
    });
});
