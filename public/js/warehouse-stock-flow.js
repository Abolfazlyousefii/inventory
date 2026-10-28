document.addEventListener('DOMContentLoaded', () => {
    'use strict';
    const app = document.getElementById('stockFlowApp');
    if (!app) return;
    const tbody = document.getElementById('stockFlowRows');
    const message = document.getElementById('stockFlowMessage');
    const loadedLabel = document.getElementById('stockFlowLoaded');
    const more = document.getElementById('stockFlowMore');
    const printButton = document.getElementById('stockFlowPrint');
    const cancelButton = document.getElementById('stockFlowCancel');
    const printMode = app.dataset.print === '1';
    const overlay = document.getElementById('flowDetailBackdrop');
    const detailPanel = document.getElementById('flowDetailPanel');
    const detailHeading = document.getElementById('flowDetailHeading');
    const detailDescription = document.getElementById('flowDetailDescription');
    const detailMessage = document.getElementById('flowDetailMessage');
    const detailRows = document.getElementById('flowDetailRows');
    const detailMore = document.getElementById('flowDetailMore');
    const detailClose = document.getElementById('flowDetailClose');
    let detailState = null;
    let detailRequest = null;
    let detailVersion = 0;
    let detailTrigger = null;

    const number = value => Number(value || 0).toLocaleString('fa-IR');
    let cursor = 0;
    let loaded = 0;
    let busy = false;
    let hasMore = true;
    let cancelled = false;
    let pending = null;

    function cell(value, className) {
        const td = document.createElement('td');
        td.textContent = value === undefined || value === null || value === '' ? '—' : String(value);
        if (className) td.className = className;
        return td;
    }


    function provenanceCell(value, kind, item, className) {
        const td = cell(number(value), className);
        if (overlay && !printMode && Number(value) > 0) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'source-button';
            button.textContent = 'مشاهده اسناد';
            button.setAttribute('aria-label', 'مشاهده اسناد مربوط به '+item.product_name);
            button.addEventListener('click', () => openDocuments(item, kind, button));
            td.appendChild(button);
        }
        return td;
    }

    function closeDocuments() {
        if (!overlay || overlay.hidden) return;
        detailVersion++;
        detailRequest?.abort();
        detailRequest = null;
        detailState = null;
        overlay.hidden = true;
        detailTrigger?.focus();
        detailTrigger = null;
    }

    function detailStatus(text, isError = false) {
        detailMessage.textContent = text;
        detailMessage.classList.toggle('error', isError);
    }

    function makeDocumentRow(item) {
        const tr = document.createElement('tr');
        const id = cell(item.document_number, 'detail-doc');
        tr.appendChild(id);
        tr.appendChild(cell(item.customer_name));
        tr.appendChild(cell(item.seller_name));
        tr.appendChild(cell(item.status_label));
        tr.appendChild(cell(number(item.quantity), 'num'));
        const action = document.createElement('td');
        const link = document.createElement('a');
        const url = new URL(item.document_url, location.origin);
        if (url.origin === location.origin) {
            link.href = url.href;
            link.textContent = 'مشاهده سند';
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            action.appendChild(link);
        } else {
            action.textContent = '—';
        }
        tr.appendChild(action);
        return tr;
    }

    async function fetchDocuments() {
        const state = detailState;
        if (!state || state.busy || !state.hasMore) return;
        state.busy = true;
        detailMore.disabled = true;
        const version = detailVersion;
        const controller = new AbortController();
        detailRequest = controller;
        try {
            const url = new URL(app.dataset.detailsUrl.replace('__VARIANT__', String(state.variantId)), location.origin);
            url.searchParams.set('kind', state.kind);
            url.searchParams.set('after_id', String(state.cursor));
            url.searchParams.set('limit', '50');
            const response = await fetch(url, {
                method: 'GET', credentials: 'same-origin',
                headers: {'Accept':'application/json'},
                signal: controller.signal,
            });
            if (!response.ok) throw new Error('دریافت اسناد با خطا روبه‌رو شد (HTTP '+response.status+').');
            const result = await response.json();
            if (!Array.isArray(result.rows) || (result.has_more && result.rows.length === 0)) {
                throw new Error('صفحه‌بندی اسناد معتبر نیست.');
            }
            if (version !== detailVersion) return;
            const next = Number(result.next_cursor);
            if (result.has_more && (!Number.isSafeInteger(next) || next <= state.cursor)) {
                throw new Error('امکان ادامه دریافت اسناد وجود ندارد.');
            }
            const fragment = document.createDocumentFragment();
            for (const item of result.rows) fragment.appendChild(makeDocumentRow(item));
            detailRows.appendChild(fragment);
            state.cursor = next;
            state.loaded += result.rows.length;
            state.hasMore = Boolean(result.has_more);
            detailMore.hidden = !state.hasMore;
            detailStatus(state.loaded === 0
                ? 'در وضعیت فعلی سندی برای این مقدار یافت نشد؛ ممکن است وضعیت اسناد تغییر کرده باشد.'
                : number(state.loaded)+' سند نمایش داده شد'+(state.hasMore?'؛ اسناد بیشتری موجود است.':'.'));
        } catch (error) {
            if (version !== detailVersion || error.name === 'AbortError') return;
            detailMore.hidden = false; // Retry the same cursor; don't show success.
            detailMore.textContent = 'تلاش مجدد';
            detailStatus(error.message, true);
        } finally {
            if (version === detailVersion) {
                state.busy = false;
                detailMore.disabled = false;
                detailRequest = null;
            }
        }
    }

    function openDocuments(item, kind, trigger) {
        if (!overlay) return;
        closeDocuments();
        detailTrigger = trigger;
        detailState = {
            variantId: Number(item.variant_id),
            kind, cursor: 0, loaded: 0, hasMore: true, busy: false,
        };
        const isQueue = kind === 'queue';
        detailHeading.textContent = isQueue ? 'فاکتورهای موجود در صف جمع‌آوری' : 'پیش‌فاکتورهای دارای رزرو رسمی';
        detailDescription.textContent = item.product_name+' / '+
            [item.variant_name, item.details].filter(Boolean).join(' / ')+
            ' — تعداد در گزارش: '+number(isQueue ? item.queue_total : item.official_reserved);
        detailRows.replaceChildren();
        detailMore.hidden = true;
        detailMore.textContent = 'نمایش اسناد بیشتر';
        overlay.hidden = false;
        detailStatus('در حال دریافت اسناد مرتبط...');
        detailPanel.focus();
        detailVersion++;
        fetchDocuments();
    }

    if (overlay) {
        detailClose.addEventListener('click', closeDocuments);
        detailMore.addEventListener('click', fetchDocuments);
        overlay.addEventListener('click', event => {
            if (event.target === overlay) closeDocuments();
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !overlay.hidden) closeDocuments();
        });
    }

    function row(item) {
        const tr = document.createElement('tr');
        tr.append(cell(number(++loaded), 'num'));
        tr.append(cell(item.product_name, 'item'));
        tr.append(cell([item.variant_name, item.details].filter(Boolean).join(' / ') || item.variant_code || '—'));
        tr.append(cell(number(item.available), 'num positive'));
        tr.append(provenanceCell(item.official_reserved, 'official', item, 'num'));
        tr.append(cell(number(item.pending_collection), 'num queue'));
        tr.append(cell(number(item.warehouse_received), 'num queue'));
        tr.append(cell(number(item.collecting), 'num queue'));
        tr.append(provenanceCell(item.queue_total, 'queue', item, 'num queue'));
        return tr;
    }

    function status(text, error) {
        message.textContent = text;
        message.classList.toggle('error', Boolean(error));
    }

    async function fetchPage() {
        if (!hasMore || busy || cancelled) return;
        busy = true;
        more.disabled = true;
        pending = new AbortController();
        try {
            const url = new URL(app.dataset.rowsUrl, location.origin);
            url.searchParams.set('after_id', String(cursor));
            url.searchParams.set('limit', '100');
            if (app.dataset.q) url.searchParams.set('q', app.dataset.q);
            if (app.dataset.onlyInFlow === '1') url.searchParams.set('only_in_flow','1');
            const response = await fetch(url.toString(), {
                method: 'GET', credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                signal: pending.signal,
            });
            if (!response.ok) throw new Error('دریافت اطلاعات با خطا مواجه شد (HTTP '+response.status+').');
            const result = await response.json();
            if (!Array.isArray(result.rows) || (result.has_more && result.rows.length === 0)) {
                throw new Error('اطلاعات صفحه‌بندی معتبر نیست.');
            }
            const fragment = document.createDocumentFragment();
            for (const item of result.rows) fragment.appendChild(row(item));
            tbody.appendChild(fragment);
            loadedLabel.textContent = 'ردیف‌های دریافت‌شده: '+number(loaded);
            const next = Number(result.next_cursor);
            if (result.has_more && (!Number.isSafeInteger(next) || next <= cursor)) {
                throw new Error('اشکال در ادامه گزارش؛ دریافت متوقف شد.');
            }
            cursor = next;
            hasMore = Boolean(result.has_more);
            status(hasMore ? number(loaded)+' ردیف دریافت شد؛ ادامه گزارش در دسترس است.'
                : number(loaded)+' ردیف دریافت شد؛ گزارش کامل است.', false);
            more.hidden = printMode || !hasMore;
            if (!hasMore && printButton) {
                printButton.disabled = false;
                cancelButton.hidden = true;
            }
        } catch (error) {
            cancelled = true; // fail closed: never allow partial printing
            more.hidden = true;
            if (printButton) printButton.disabled = true;
            status(error.name === 'AbortError' ? 'دریافت گزارش متوقف شد؛ برای گزارش کامل صفحه را تازه‌سازی کنید.'
                : error.message, true);
            cancelButton.hidden = true;
        } finally {
            busy = false;
            pending = null;
            more.disabled = false;
        }
    }

    more.addEventListener('click', fetchPage);
    if (printButton) {
        printButton.disabled = true;
        printButton.addEventListener('click', () => {
            if (!printButton.disabled && !hasMore && !cancelled) window.print();
        });
    }
    cancelButton.addEventListener('click', () => {
        cancelled = true;
        pending?.abort();
        more.hidden = true;
        if (printButton) printButton.disabled = true;
        cancelButton.hidden = true;
        status('بارگیری متوقف شد. گزارش ناقص قابل چاپ نیست.', true);
    });

    if (printMode) {
        cancelButton.hidden = false;
        (async () => {
            while (hasMore && !cancelled) {
                await fetchPage();
                await new Promise(resolve => requestAnimationFrame(resolve));
            }
        })();
    } else {
        fetchPage();
    }
});
