/*
 * Read-only preview of a category/descendants in the ORIGINAL stocktake form.
 * Deliberately does not create drafts, submit forms, or finalize stock counts.
 */
(function () {
    'use strict';

    const PAGE_SIZE = 60;

    function validatePage(payload, previousCursor) {
        if (!payload || !Array.isArray(payload.rows) || typeof payload.has_more !== 'boolean') {
            throw new Error('پاسخ فهرست تنوع‌ها معتبر نیست.');
        }
        const cursor = Number(payload.next_cursor);
        if (!Number.isSafeInteger(cursor) || cursor < previousCursor) {
            throw new Error('شناسه ادامه دریافت تنوع‌ها نامعتبر است.');
        }
        if (payload.has_more && (payload.rows.length === 0 || cursor <= previousCursor)) {
            throw new Error('صفحه‌بندی تنوع‌های دسته معتبر نیست.');
        }
        return { rows: payload.rows, cursor, hasMore: payload.has_more };
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { validatePage, PAGE_SIZE };
    }
    if (typeof document === 'undefined') return;

    document.addEventListener('DOMContentLoaded', function () {
        const panel = document.getElementById('stocktakeInlinePreview');
        const button = document.getElementById('stocktakeInlinePreviewShow');
        const category = document.getElementById('category');
        const subcategory = document.getElementById('subcategory');
        if (!panel || !button || !category || !subcategory) return;

        const body = document.getElementById('stocktakeInlinePreviewRows');
        const status = document.getElementById('stocktakeInlinePreviewStatus');
        const more = document.getElementById('stocktakeInlinePreviewMore');
        const summary = document.getElementById('stocktakeInlinePreviewSummary');
        const showNumber = value => Number(value || 0).toLocaleString('fa-IR');
        let generation = 0;
        let controller = null;
        let scopeId = 0;
        let cursor = 0;
        let loaded = 0;
        let hasMore = false;
        let busy = false;
        const seen = new Set();
        const products = new Set();

        function message(value, error) {
            status.textContent = value;
            status.classList.toggle('text-danger', Boolean(error));
        }

        function reset() {
            generation++;
            if (controller) controller.abort();
            controller = null;
            busy = false;
            scopeId = 0;
            cursor = 0;
            loaded = 0;
            hasMore = false;
            seen.clear();
            products.clear();
            body.replaceChildren();
            summary.textContent = '';
            more.hidden = true;
            more.disabled = false;
            button.disabled = false;
            panel.hidden = true;
        }

        function addRow(item) {
            const variantId = Number(item.variant_id);
            if (!Number.isSafeInteger(variantId) || variantId < 1 || seen.has(variantId)) return;
            seen.add(variantId);
            const productId = Number(item.product_id);
            if (Number.isSafeInteger(productId)) products.add(productId);
            const tr = document.createElement('tr');
            const cells = [
                item.product_name || '—',
                item.name || '—',
                item.sku || '—',
                showNumber(item.system_available),
                showNumber(item.active_reserved),
                showNumber(item.expected_physical),
                showNumber(item.queue_total)
            ];
            cells.forEach(function (value) {
                const td = document.createElement('td');
                td.textContent = String(value);
                tr.appendChild(td);
            });
            body.appendChild(tr);
            loaded++;
        }

        async function loadPage() {
            if (!scopeId || !hasMore || busy) return;
            const currentGeneration = generation;
            const requestedCursor = cursor;
            busy = true;
            button.disabled = true;
            more.disabled = true;
            controller = new AbortController();
            message('در حال دریافت تنوع‌های دسته ...', false);
            try {
                const url = new URL(panel.dataset.rowsUrl, window.location.origin);
                url.searchParams.set('category_id', String(scopeId));
                url.searchParams.set('after_id', String(requestedCursor));
                url.searchParams.set('limit', String(PAGE_SIZE));
                const response = await fetch(url.toString(), {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: controller.signal
                });
                if (!response.ok) throw new Error('دریافت فهرست دسته ناموفق بود.');
                const page = validatePage(await response.json(), requestedCursor);
                if (currentGeneration !== generation) return;
                page.rows.forEach(addRow);
                cursor = page.cursor;
                hasMore = page.hasMore;
                more.hidden = !hasMore;
                summary.textContent = showNumber(loaded) + ' تنوع از ' + showNumber(products.size) +
                    ' محصول دریافت شد' + (hasMore ? '؛ برای ادامه «نمایش تنوع‌های بیشتر» را بزنید.' : '؛ دریافت کامل شد.');
                message(loaded ? 'این پیش‌نمایش فقط خواندنی است؛ هیچ سند یا موجودی‌ای تغییر نکرده است.' :
                    'در این محدوده تنوعی پیدا نشد.', false);
            } catch (error) {
                if (currentGeneration !== generation || error.name === 'AbortError') return;
                hasMore = true;
                more.hidden = false;
                message(error.message || 'دریافت فهرست ناموفق بود؛ دوباره تلاش کنید.', true);
            } finally {
                if (currentGeneration === generation) {
                    controller = null;
                    busy = false;
                    button.disabled = false;
                    more.disabled = false;
                }
            }
        }

        category.addEventListener('change', reset);
        subcategory.addEventListener('change', reset);
        button.addEventListener('click', function () {
            const selected = Number(subcategory.value || category.value);
            if (!Number.isSafeInteger(selected) || selected < 1) {
                message('ابتدا دسته یا زیردسته را انتخاب کن.', true);
                panel.hidden = false;
                return;
            }
            reset();
            scopeId = selected;
            hasMore = true;
            panel.hidden = false;
            loadPage();
        });
        more.addEventListener('click', loadPage);
    });
})();
