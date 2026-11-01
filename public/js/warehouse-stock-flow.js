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

    function row(item) {
        const tr = document.createElement('tr');
        tr.append(cell(number(++loaded), 'num'));
        tr.append(cell(item.product_name, 'item'));
        tr.append(cell([item.variant_name, item.details].filter(Boolean).join(' / ') || item.variant_code || '—'));
        tr.append(cell(number(item.available), 'num positive'));
        tr.append(cell(number(item.official_reserved), 'num'));
        tr.append(cell(number(item.pending_collection), 'num queue'));
        tr.append(cell(number(item.warehouse_received), 'num queue'));
        tr.append(cell(number(item.collecting), 'num queue'));
        tr.append(cell(number(item.queue_total), 'num queue'));
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
