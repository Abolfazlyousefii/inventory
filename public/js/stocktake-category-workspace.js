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
    const finalizeAll = document.getElementById('scFinalizeAll');
    const reviewSummary = document.getElementById('scReviewSummary');
    const toolbarStatus = document.getElementById('scToolbarStatus');
    const reviewButton = document.getElementById('scReview');
    const toolbar = document.getElementById('scBulkToolbar');

    const groups = new Map();
    // Retain unsaved counts and saved draft links between searches.
    const entered = new Map();
    const saved = new Map();
    const documentIds = new Map();
    const knownProducts = new Map();
    const invalidCounts = new Map();
    const dirtyProducts = new Set();
    const savedMetadata = new Map();
    const finalized = new Set();
    const loadedRows = new Map();
    let bulkSaving = false;
    let bulkFinalizing = false;
    let allCategoryLoaded = false;
    let reviewAcknowledged = false;
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
        if (toolbarStatus) toolbarStatus.textContent = message;
    };

    function containsUnsavedCounts() {
        return dirtyProducts.size > 0 || invalidCounts.size > 0 || [...entered].some(([productId, values]) => !saved.has(productId) &&
            [...values.values()].some(v => v !== ''));
    }

    function reset() {
        clearTimeout(searchTimer);
        entered.clear();
        saved.clear();
        documentIds.clear();
        knownProducts.clear();
        invalidCounts.clear();
        dirtyProducts.clear();
        savedMetadata.clear();
        finalized.clear();
        loadedRows.clear();
        allCategoryLoaded = false;
        reviewAcknowledged = false;
        date.disabled = false;
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
        footer.append(feedback);
        card.append(footer);
        productsNode.append(card);

        const stored = saved.get(id);
        const group = {id,name:row.product_name,rows:new Map(),quantities:entered.get(id) || new Map(),
            tbody,feedback,flag,saved:Boolean(stored),saving:false};
        if (!entered.has(id)) entered.set(id,group.quantities);
        if (stored) {
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
        if (!loadedRows.has(id)) reviewAcknowledged = false;
        loadedRows.set(id,row);
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
        if (finalized.has(group.id)) input.disabled = true;
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
            if (!group.saved || dirtyProducts.has(group.id)) group.feedback.textContent = fa(counted) + ' از ' + fa(group.rows.size)
                + ' تنوع شمارش شده · ' + (hasMore || loading ? 'ابتدا همه ردیف‌های دسته را دریافت کنید.' :
                'شمارش‌ها را با دکمه ذخیره یکجا ثبت کنید. مقدار خالی یعنی شمارش‌نشده.');
        }
        more.hidden = !hasMore && allCategoryLoaded;
        more.textContent = hasMore ? 'دریافت ادامه کالاها' : 'دریافت ادامه کل دسته';
        more.disabled = loading || bulkSaving || bulkFinalizing;
        if (saveAll) saveAll.disabled = !selectionId || loading || hasMore || bulkSaving || bulkFinalizing ||
            invalidCounts.size > 0 ||
            pendingProducts().length === 0 || pendingProducts().length > 500;
        if (finalizeAll) finalizeAll.disabled = !canFinalize();
        if (reviewButton) reviewButton.disabled = savedMetadata.size === 0 || bulkSaving || bulkFinalizing;
        if (toolbar) toolbar.hidden = !selectionId;
    }

    function pendingProducts() {
        return [...knownProducts.keys()].filter(id => !finalized.has(id) && (!saved.has(id) || dirtyProducts.has(id)));
    }

    function completeDraft(meta) {
        return Number.isInteger(Number(meta?.variants_count)) && Number(meta.variants_count) > 0 &&
            Number(meta.counted_count) === Number(meta.variants_count) && Boolean(meta.review_token);
    }

    function canFinalize() {
        return reviewAcknowledged && Boolean(selectionId) && allCategoryLoaded && !loading && !hasMore && !bulkSaving &&
            !bulkFinalizing && !containsUnsavedCounts() && pendingProducts().length === 0 &&
            savedMetadata.size > 0 && savedMetadata.size <= 500 &&
            [...savedMetadata.entries()].every(([id, meta]) => !finalized.has(id) && completeDraft(meta));
    }

    function renderDraftList() {
        if (!draftList) return;
        draftList.replaceChildren();
        const totals = {variants:0, counted:0, before:0, actual:0, increase:0, decrease:0};
        for (const [id, url] of saved) {
            const meta = savedMetadata.get(id) || {};
            const completed = completeDraft(meta);
            const item = make('li', 'mb-2');
            const link = make('a', 'sc-success', (knownProducts.get(id) || 'محصول') +
                ' — سند ' + fa(documentIds.get(id)) + ' · ' + fa(meta.counted_count) + ' از ' + fa(meta.variants_count) +
                ' تنوع · ' + (finalized.has(id) ? 'نهایی شد' : dirtyProducts.has(id) ? 'تغییرات ذخیره‌نشده' : completed ? 'آماده تأیید' : 'شمارش ناقص'));
            link.href = url;
            item.append(link);
            draftList.append(item);
            totals.variants += Number(meta.variants_count || 0);
            totals.counted += Number(meta.counted_count || 0);
            totals.before += Number(meta.total_before || 0);
            totals.actual += Number(meta.total_actual || 0);
            totals.increase += Number(meta.total_increase || 0);
            totals.decrease += Number(meta.total_decrease || 0);
        }
        if (reviewSummary) reviewSummary.textContent = fa(saved.size) + ' پیش‌نویس · ' + fa(totals.counted) +
            ' از ' + fa(totals.variants) + ' تنوع شمارش شده · موجودی قبل: ' + fa(totals.before) +
            ' · شمارش واقعی: ' + fa(totals.actual) + ' · افزایش: ' + fa(totals.increase) + ' · کاهش: ' + fa(totals.decrease) +
            (allCategoryLoaded ? '' : ' · دریافت کل دسته هنوز کامل نیست.') +
            (dirtyProducts.size ? ' · ابتدا تغییرات را دوباره ذخیره کنید.' : '') +
            ([...savedMetadata.values()].some(meta => !completeDraft(meta)) ? ' · برای ثبت نهایی، تمام تنوع‌های شمارش‌نشده را تکمیل کنید.' : '');
    }

    function markSaved(id, result) {
        saved.set(id, result.edit_url);
        documentIds.set(id, Number(result.document_id));
        savedMetadata.set(id, result);
        dirtyProducts.delete(id);
        const group = groups.get(id);
        if (!group) return;
        group.saved = true;
        group.flag.textContent = 'پیش‌نویس ذخیره شد';
        group.feedback.replaceChildren();
        const link = make('a', 'sc-success', 'مشاهده و بررسی سند شماره ' + fa(result.document_id));
        link.href = result.edit_url;
        group.feedback.append(link);
    }

    function setBulkControls(disabled) {
        [show, more, category, subcategory, date, liveSearch, document.getElementById('scClose')]
            .filter(Boolean).forEach(node => { node.disabled = disabled; });
        date.disabled = disabled || (knownProducts.size > 0 && finalized.size === knownProducts.size);
        for (const group of groups.values()) {
            group.tbody.querySelectorAll('.sc-actual').forEach(input => {
                input.disabled = disabled || finalized.has(group.id);
            });
        }
    }

    async function saveAllDrafts() {
        if (!saveAll || saveAll.disabled || bulkSaving || bulkFinalizing) return;
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
                            .map(([variant, value]) => [variant, value === '' ? null : value]))})),
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
            reviewAcknowledged = false;
            renderDraftList();
            notice(fa(result.documents.length) + ' پیش‌نویس یکجا ذخیره شد. جمع‌بندی را بررسی کنید؛ سپس با «ثبت نهایی همه» تأیید کنید.');
        } catch (error) {
            notice(error.message || 'ذخیره ناموفق بود؛ مقادیر شمارش حفظ شده‌اند.', true);
        } finally {
            bulkSaving = false;
            setBulkControls(false);
            if (!embedded) subcategory.disabled = !category.value;
            updateButtons();
        }
    }

    async function finalizeAllDrafts() {
        if (!canFinalize()) return;
        if (!window.confirm('جمع‌بندی شمارش و اختلاف‌ها را بررسی کرده‌اید؟ همه ' + fa(savedMetadata.size) +
            ' پیش‌نویس با یک عملیات ثبت نهایی می‌شوند و موجودی مرکزی تغییر می‌کند. دستهٔ بسته‌شده همچنان تا بازگشایی صریح بسته می‌ماند.')) return;
        bulkFinalizing = true;
        setBulkControls(true);
        updateButtons();
        notice('در حال ثبت نهایی همه پیش‌نویس‌ها؛ صفحه را نبندید...');
        try {
            const response = await fetch(app.dataset.bulkFinalizeUrl, {
                method:'POST', credentials:'same-origin',
                headers:{'Accept':'application/json','Content-Type':'application/json',
                    'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]')?.content || ''},
                body:JSON.stringify({category_id:selectionId,
                    document_ids:[...savedMetadata.values()].map(meta => Number(meta.document_id)),
                    review_tokens:Object.fromEntries([...savedMetadata.values()].map(meta => [String(meta.document_id),meta.review_token])),
                }),
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                const conflicts = Array.isArray(result.conflicts) ? result.conflicts.map(item => item.reason || item.message || '').filter(Boolean).join('، ') : '';
                throw new Error([result.message, result.errors && Object.values(result.errors).flat().join('، '), conflicts]
                    .filter(Boolean).join(' · ') || 'ثبت نهایی انجام نشد؛ پیش‌نویس‌ها حفظ شده‌اند.');
            }
            if (!Array.isArray(result.documents) || result.documents.length !== savedMetadata.size ||
                new Set(result.documents.map(item => Number(item.product_id))).size !== savedMetadata.size ||
                result.documents.some(item => !savedMetadata.has(Number(item.product_id)) || !item.view_url ||
                    Number(savedMetadata.get(Number(item.product_id)).document_id) !== Number(item.document_id))) {
                throw new Error('پاسخ ثبت نهایی معتبر نیست؛ وضعیت اسناد را در آرشیو بررسی کنید.');
            }
            result.documents.forEach(item => {
                const id = Number(item.product_id);
                finalized.add(id);
                saved.set(id,item.view_url);
                const group = groups.get(id);
                if (group) group.flag.textContent = 'نهایی شد';
            });
            renderDraftList();
            notice('همه ' + fa(result.documents.length) + ' سند یکجا ثبت نهایی شدند. دسته‌های بسته‌شده تا بازگشایی صریح همچنان بسته‌اند.');
        } catch (error) {
            notice(error.message || 'ثبت نهایی انجام نشد؛ دوباره بررسی کنید.',true);
        } finally {
            bulkFinalizing = false;
            setBulkControls(false);
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
            if (!activeQuery && !hasMore) allCategoryLoaded = true;
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
                renderDraftList();
            }
        }
    }

    async function begin() {
        if (bulkSaving || bulkFinalizing) return;
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
            if (!selectionId || bulkSaving || bulkFinalizing) return;
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
        if (!group || finalized.has(group.id) || bulkSaving || bulkFinalizing) return;
        const normalized = field.value.replace(/[۰-۹]/g, d => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
            .replace(/[٠-٩]/g,d => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))).trim();
        const valid = normalized === '' || (/^\d+$/.test(normalized) &&
            Number.isSafeInteger(Number(normalized)) && Number(normalized) <= 2147483647);
        field.setCustomValidity(valid?'':'فقط عدد صحیح نامنفی مجاز است.');
        reviewAcknowledged = false;
        if (!valid) {
            invalidCounts.set(Number(field.dataset.variant), {value: field.value, productId: group.id});
            group.feedback.textContent = 'مقدار شمارش معتبر نیست.';
            updateButtons();
            return;
        }
        invalidCounts.delete(Number(field.dataset.variant));
        field.value = normalized;
        group.quantities.set(Number(field.dataset.variant),normalized);
        entered.set(group.id,group.quantities);
        dirtyProducts.add(group.id);
        group.flag.textContent = 'تغییرات ذخیره‌نشده';
        updateDifference(group,Number(field.dataset.variant));
        updateButtons();
        renderDraftList();
    });

    show.addEventListener('click',begin);
    more.addEventListener('click',async () => {
        if (loading || bulkSaving || bulkFinalizing) return;
        if (!hasMore && !allCategoryLoaded) {
            clearTimeout(searchTimer);
            if (liveSearch) liveSearch.value = '';
            activeQuery = '';
            clearListing();
            hasMore = true;
        }
        await requestPage();
    });
    saveAll?.addEventListener('click',saveAllDrafts);
    date.addEventListener('change',() => {
        if (bulkSaving || bulkFinalizing) return;
        reviewAcknowledged = false;
        for (const id of knownProducts.keys()) {
            if (!finalized.has(id)) dirtyProducts.add(id);
        }
        updateButtons();
        renderDraftList();
    });
    finalizeAll?.addEventListener('click',finalizeAllDrafts);
    reviewButton?.addEventListener('click',() => {
        if (!savedMetadata.size || bulkSaving || bulkFinalizing) return;
        reviewAcknowledged = true;
        document.getElementById('scReviewPanel')?.scrollIntoView?.({behavior:'smooth',block:'center'});
        updateButtons();
    });
    updateButtons();

    if (embedded) {
        const originalForm = document.getElementById('stocktakeForm');
        originalForm.addEventListener('submit', event => {
            if (originalForm.dataset.categoryMode !== '1') return;
            event.preventDefault();
            event.stopImmediatePropagation();
            notice('برای ذخیره همه شمارش‌ها، دکمه «ذخیره یکجای پیش‌نویس‌ها» را بزنید؛ سپس جمع‌بندی را بررسی و همه را یکجا ثبت نهایی کنید.', true);
        }, true);
        document.getElementById('scClose').addEventListener('click', () => {
            if (bulkSaving || bulkFinalizing) return;
            if (containsUnsavedCounts() && !window.confirm('شمارش‌های ذخیره‌نشده رها می‌شوند. به فرم محصول تکی برگردیم؟')) return;
            reset();
            app.hidden = true;
            document.querySelectorAll('[data-stocktake-single]').forEach(node => { node.hidden = false; });
            delete originalForm.dataset.categoryMode;
            show.focus();
        });
    }

    window.addEventListener('beforeunload',event => {
        if (!containsUnsavedCounts() && invalidCounts.size === 0 && !bulkSaving && !bulkFinalizing) return;
        event.preventDefault();
        event.returnValue = '';
    });
});
