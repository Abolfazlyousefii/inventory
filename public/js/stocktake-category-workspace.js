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
    const saveAll = document.getElementById('scSaveAll');
    const draftList = document.getElementById('scDraftList');

    const groups = new Map();
    // Retain unsaved counts and saved draft links between searches.
    const entered = new Map();
    const saved = new Map();
    const documentIds = new Map();
    const knownProducts = new Map();
    const invalidCounts = new Map();
    let bulkSaving = false;
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
        return invalidCounts.size > 0 || [...entered].some(([productId, values]) => !saved.has(productId) &&
            [...values.values()].some(v => v !== ''));
    }

    function reset() {
        clearTimeout(searchTimer);
        entered.clear();
        saved.clear();
        documentIds.clear();
        knownProducts.clear();
        invalidCounts.clear();
        draftList?.replaceChildren();
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
        knownProducts.set(id, row.product_name);
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
        footer.append(feedback,button);
        card.append(footer);
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
        description.dataset.label = 'تنوع کالا';
        description.append(make('small','',row.sku || ''));
        tr.append(description);
        const centralCell = make('td','sc-number',fa(row.system_available));
        centralCell.dataset.label = 'موجودی انبار مرکزی';
        tr.append(centralCell);
        const reservedCell = make('td','sc-number',fa(row.active_reserved));
        reservedCell.dataset.label = 'رزرو فعال';
        tr.append(reservedCell);
        const queue = make('td','sc-number');
        queue.dataset.label = 'موجودی در صف جمع‌آوری';
        queue.append(make('span',Number(row.queue_total)>0?'sc-badge':'sc-muted',fa(row.queue_total)));
        if (Number(row.queue_total) > 0) {
            queue.append(make('small','sc-flow-details','انتظار: '+fa(row.pending_collection)+
                ' · تحویل انبار: '+fa(row.warehouse_received)+' · جمع‌آوری: '+fa(row.collecting)));
        }
        tr.append(queue);
        const actualCell = make('td');
        actualCell.dataset.label = 'موجودی واقعی';
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
        if (invalidCounts.has(id)) {
            input.value = invalidCounts.get(id).value;
            input.setCustomValidity('فقط عدد صحیح نامنفی مجاز است.');
        }
        if (group.saved) input.disabled = true;
        actualCell.append(input);
        tr.append(actualCell);
        const diff = make('td','sc-number sc-difference','—');
        diff.dataset.label = 'اختلاف';
        diff.dataset.differenceFor = String(id);
        tr.append(diff);
        group.tbody.append(tr);
        if (!group.saved) group.flag.textContent = fa(group.rows.size) + ' تنوع';
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
        for (const group of groups.values()) {
            const counted = [...group.quantities.values()].filter(v => v !== '').length;
            group.button.disabled = group.saved || group.saving || loading || hasMore ||
                bulkSaving ||
                [...invalidCounts.values()].some(item => item.productId === group.id) ||
                [...group.tbody.querySelectorAll('.sc-actual')].some(input => Boolean(input.validationMessage));
            if (!group.saved) group.feedback.textContent = fa(counted) + ' از ' + fa(group.rows.size)
                + ' تنوع شمارش شده · ' + (hasMore || loading ? 'ابتدا همه ردیف‌های دسته را دریافت کنید.' :
                'مقادیر خالی فقط در مرحله تأیید نهایی سند تعیین تکلیف می‌شوند.');
        }
        more.hidden = !hasMore;
        more.disabled = loading || bulkSaving;
        if (saveAll) saveAll.disabled = !selectionId || loading || hasMore || bulkSaving ||
            [...groups.values()].some(group => group.saving) || invalidCounts.size > 0 ||
            pendingProducts().length === 0 || pendingProducts().length > 500;
    }

    function pendingProducts() {
        return [...knownProducts.keys()].filter(id => !saved.has(id));
    }

    function renderDraftList() {
        if (!draftList) return;
        draftList.replaceChildren();
        for (const [id, url] of saved) {
            const item = make('li', 'mb-2');
            const link = make('a', 'sc-success', (knownProducts.get(id) || 'محصول') +
                ' — بررسی و ثبت نهایی پیش‌نویس شماره ' + fa(documentIds.get(id)));
            link.href = url;
            item.append(link);
            draftList.append(item);
        }
    }

    function markSaved(id, result) {
        saved.set(id, result.edit_url);
        documentIds.set(id, Number(result.document_id));
        const group = groups.get(id);
        if (!group) return;
        group.saved = true;
        group.tbody.querySelectorAll('.sc-actual').forEach(input => { input.disabled = true; });
        group.flag.textContent = 'پیش‌نویس ذخیره شد';
        group.feedback.replaceChildren();
        const link = make('a', 'sc-success', 'مشاهده و بررسی سند شماره ' + fa(result.document_id));
        link.href = result.edit_url;
        group.feedback.append(link);
        group.button.textContent = 'ذخیره شد';
    }

    function setBulkControls(disabled) {
        [show, more, category, subcategory, date, liveSearch, document.getElementById('scClose')]
            .filter(Boolean).forEach(node => { node.disabled = disabled; });
        for (const group of groups.values()) {
            group.tbody.querySelectorAll('.sc-actual').forEach(input => {
                input.disabled = disabled || group.saved;
            });
        }
    }

    async function saveAllDrafts() {
        if (!saveAll || saveAll.disabled || bulkSaving) return;
        if (!date.value) { notice('تاریخ سند را مشخص کنید.', true); date.focus(); return; }
        const ids = pendingProducts();
        if (!ids.length || ids.length > 500 || invalidCounts.size) return;
        bulkSaving = true;
        setBulkControls(true);
        updateButtons();
        notice('در حال ذخیره یکجای پیش‌نویس‌ها؛ این صفحه را نبندید...');
        try {
            const response = await fetch(app.dataset.bulkStoreUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''},
                body: JSON.stringify({
                    category_id: selectionId, document_date: date.value,
                    products: ids.map(id => ({product_id: id,
                        ...(documentIds.has(id) ? {document_id: documentIds.get(id)} : {}),
                        actual_quantities: Object.fromEntries([...(entered.get(id) || [])]
                            .filter(([, value]) => value !== ''))})),
                }),
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(result.errors && Object.values(result.errors).flat().join('، ') ||
                    result.message || 'ذخیره یکجای پیش‌نویس‌ها ناموفق بود. مقادیر واردشده حفظ شده‌اند.');
            }
            if (!Array.isArray(result.documents) || result.documents.length !== ids.length ||
                new Set(result.documents.map(item => Number(item.product_id))).size !== ids.length ||
                result.documents.some(item => !ids.includes(Number(item.product_id)) ||
                    !item.document_id || !item.edit_url)) throw new Error('پاسخ ثبت پیش‌نویس‌ها معتبر نبود.');
            result.documents.forEach(item => markSaved(Number(item.product_id), item));
            renderDraftList();
            notice(fa(result.documents.length) + ' پیش‌نویس یکجا ذخیره شد. از فهرست زیر هر سند را بررسی و سپس ثبت نهایی کنید.');
        } catch (error) {
            notice(error.message || 'ذخیره ناموفق بود؛ مقادیر شمارش حفظ شده‌اند.', true);
        } finally {
            bulkSaving = false;
            setBulkControls(false);
            if (!embedded) subcategory.disabled = !category.value;
            updateButtons();
        }
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
                    'همه تنوع‌های دسته دریافت شدند. شمارش‌ها را وارد کنید و در پایان «ذخیره یکجای پیش‌نویس‌ها» را بزنید.'));
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
        if (bulkSaving || [...groups.values()].some(group => group.saving)) return;
        const selected = Number(subcategory.value || category.value);
        if (!Number.isSafeInteger(selected) || selected < 1) {
            notice('ابتدا دسته‌بندی یا زیردسته‌بندی را انتخاب کنید.',true);
            return;
        }
        if (containsUnsavedCounts() &&
            !window.confirm('شمارش‌های ذخیره‌نشده این دسته از بین می‌روند. دسته جدید نمایش داده شود؟')) return;
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
            if (!selectionId || bulkSaving) return;
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
        if (!group || group.saved || bulkSaving) return;
        const normalized = field.value.replace(/[۰-۹]/g, d => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
            .replace(/[٠-٩]/g,d => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))).trim();
        const valid = normalized === '' || (/^\d+$/.test(normalized) &&
            Number.isSafeInteger(Number(normalized)) && Number(normalized) <= 2147483647);
        field.setCustomValidity(valid?'':'فقط عدد صحیح نامنفی مجاز است.');
        if (!valid) {
            invalidCounts.set(Number(field.dataset.variant), {value: field.value, productId: group.id});
            group.button.disabled = true;
            group.feedback.textContent = 'مقدار شمارش معتبر نیست.';
            updateButtons();
            return;
        }
        invalidCounts.delete(Number(field.dataset.variant));
        field.value = normalized;
        group.quantities.set(Number(field.dataset.variant),normalized);
        entered.set(group.id,group.quantities);
        updateDifference(group,Number(field.dataset.variant));
        updateButtons();
    });

    async function saveProduct(id) {
        const group = groups.get(id);
        if (!group || group.button.disabled || group.saved || group.saving || loading || hasMore || bulkSaving) return;
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
            documentIds.set(id, Number(result.document_id));
            group.tbody.querySelectorAll('.sc-actual').forEach(input=>input.disabled=true);
            group.flag.textContent = 'پیش‌نویس ذخیره شد';
            group.feedback.replaceChildren();
            const link = make('a','sc-success','مشاهده و بررسی سند شماره ' + fa(result.document_id));
            link.href=result.edit_url;
            group.feedback.append(link);
            group.button.textContent = 'ذخیره شد';
            renderDraftList();
        } catch(error) {
            group.feedback.textContent = error.message || 'ذخیره پیش‌نویس ناموفق بود؛ دوباره تلاش کنید.';
            group.feedback.classList.add('is-error');
        } finally {
            group.saving = false;
            updateButtons();
        }
    }

    productsNode.addEventListener('click', event => {
        const button = event.target.closest('[data-save-product]');
        if (button) saveProduct(Number(button.dataset.saveProduct));
    });
    show.addEventListener('click',begin);
    more.addEventListener('click',requestPage);
    saveAll?.addEventListener('click',saveAllDrafts);

    if (embedded) {
        const originalForm = document.getElementById('stocktakeForm');
        originalForm.addEventListener('submit', event => {
            if (originalForm.dataset.categoryMode !== '1') return;
            event.preventDefault();
            event.stopImmediatePropagation();
            notice('برای ذخیره همه شمارش‌ها، دکمه «ذخیره یکجای پیش‌نویس‌ها» را بزنید. ثبت نهایی پس از بررسی هر سند انجام می‌شود.', true);
        }, true);
        document.getElementById('scClose').addEventListener('click', () => {
            if (bulkSaving || [...groups.values()].some(group => group.saving)) return;
            if (containsUnsavedCounts() && !window.confirm('شمارش‌های ذخیره‌نشده رها می‌شوند. به فرم محصول تکی برگردیم؟')) return;
            reset();
            app.hidden = true;
            document.querySelectorAll('[data-stocktake-single]').forEach(node => { node.hidden = false; });
            delete originalForm.dataset.categoryMode;
            show.focus();
        });
    }

    window.addEventListener('beforeunload',event => {
        if (!containsUnsavedCounts() && invalidCounts.size === 0 && !bulkSaving) return;
        event.preventDefault();
        event.returnValue = '';
    });
});
