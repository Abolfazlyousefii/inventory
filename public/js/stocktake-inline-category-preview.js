/*
 * In-page category counting preview (memory-only, no writes).
 * Restores the prior workflow progressively without creating a draft or applying stock.
 */
(function () {
    'use strict';

    const PAGE_SIZE = 60;
    const MAX_QUANTITY = 2147483647;

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

    // Null means not counted; '0' is a real, explicit count and is never implicit.
    function normalizeActualCount(value) {
        const text = String(value ?? '').trim()
            .replace(/[۰-۹]/g, digit => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)))
            .replace(/[٠-٩]/g, digit => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)));
        if (text === '') return null;
        if (!/^\d+$/.test(text)) throw new RangeError('مقدار شمارش باید عدد صحیح نامنفی باشد.');
        const count = Number(text);
        if (!Number.isSafeInteger(count) || count > MAX_QUANTITY) {
            throw new RangeError('مقدار شمارش خارج از محدوده مجاز است.');
        }
        return String(count);
    }

    function summarizeCounts(values, seen) {
        let counted = 0;
        let zero = 0;
        for (const id of seen) {
            const value = values.get(id);
            if (value === undefined || value === null || value === '') continue;
            counted++;
            if (value === '0') zero++;
        }
        return { seen: seen.size, counted, zero, uncounted: seen.size - counted };
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { validatePage, normalizeActualCount, summarizeCounts, PAGE_SIZE };
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
        const searchInput = document.getElementById('stocktakeInlinePreviewSearch');
        const showNumber = value => Number(value || 0).toLocaleString('fa-IR');
        const entered = new Map(); // variant ID -> explicitly entered quantity, never transmitted
        const known = new Set(); // all distinct variants encountered for the selected scope
        const visible = new Set(); // current page set across paginated results
        const products = new Set();
        let generation = 0;
        let controller = null;
        let scopeId = 0;
        let cursor = 0;
        let hasMore = false;
        let busy = false;
        let query = '';
        let searchTimer = null;

        function message(value, error) {
            status.textContent = value;
            status.classList.toggle('text-danger', Boolean(error));
        }

        function updateSummary() {
            const counts = summarizeCounts(entered, known);
            summary.textContent = showNumber(visible.size) + ' تنوع در نتایج فعلی، ' +
                showNumber(products.size) + ' محصول؛ ' +
                showNumber(counts.seen) + ' تنوع مشاهده‌شده در این محدوده، ' +
                showNumber(counts.counted) + ' شمارش‌شده (از این تعداد ' +
                showNumber(counts.zero) + ' صفر صریح)، ' +
                showNumber(counts.uncounted) + ' شمارش‌نشده.' +
                (hasMore || query ? ' آمار فقط تنوع‌های دریافت‌شده را پوشش می‌دهد.' : '');
        }

        function discardConfirmation() {
            return entered.size === 0 ||
                window.confirm('شمارش‌های واردشده هنوز ذخیره نشده‌اند و با تغییر محدوده یا خروج از پیش‌نمایش پاک می‌شوند. ادامه می‌دهی؟');
        }

        function clearListing() {
            generation++;
            if (controller) controller.abort();
            controller = null;
            busy = false;
            cursor = 0;
            hasMore = false;
            visible.clear();
            products.clear();
            body.replaceChildren();
            more.hidden = true;
            more.disabled = false;
            button.disabled = false;
            updateSummary();
        }

        function reset() {
            clearTimeout(searchTimer);
            clearListing();
            scopeId = 0;
            query = '';
            searchInput.value = '';
            entered.clear();
            known.clear();
            updateSummary();
            panel.hidden = true;
        }

        function addRow(item) {
            const variantId = Number(item.variant_id);
            if (!Number.isSafeInteger(variantId) || variantId < 1 || visible.has(variantId)) return;
            visible.add(variantId);
            known.add(variantId);
            const productId = Number(item.product_id);
            if (Number.isSafeInteger(productId) && productId > 0) products.add(productId);
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

            const td = document.createElement('td');
            const input = document.createElement('input');
            input.type = 'text';
            input.inputMode = 'numeric';
            input.autocomplete = 'off';
            input.className = 'form-control stocktake-inline-actual';
            input.dataset.variantId = String(variantId);
            input.setAttribute('aria-label', 'موجودی واقعی ' + String(item.name || variantId));
            input.placeholder = 'شمارش‌نشده';
            const stored = entered.get(variantId);
            input.value = stored === undefined ? '' : stored;
            td.appendChild(input);
            tr.appendChild(td);
            body.appendChild(tr);
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
                if (query) url.searchParams.set('q', query);
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
                updateSummary();
                message(visible.size ? 'شمارش‌ها فقط در حافظه همین صفحه هستند و هیچ سندی ذخیره نشده است.' :
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
                    updateSummary();
                }
            }
        }

        body.addEventListener('input', function (event) {
            const input = event.target.closest('.stocktake-inline-actual');
            if (!input) return;
            const variantId = Number(input.dataset.variantId);
            if (!known.has(variantId)) return;
            try {
                const count = normalizeActualCount(input.value);
                if (count === null) entered.delete(variantId);
                else entered.set(variantId, count);
                input.classList.remove('is-invalid');
                input.removeAttribute('aria-invalid');
                message('شمارش در همین صفحه نگهداری می‌شود؛ هنوز ذخیره نشده است.', false);
            } catch (error) {
                entered.delete(variantId);
                input.classList.add('is-invalid');
                input.setAttribute('aria-invalid', 'true');
                message(error.message, true);
            }
            updateSummary();
        });

        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            if (!scopeId) return;
            searchTimer = setTimeout(function () {
                const next = searchInput.value.trim();
                if (next === query) return;
                query = next;
                clearListing(); // Do NOT clear entered or known when searching
                hasMore = true;
                loadPage();
            }, 320);
        });

        // Preview mode must never fall through to the existing single-product submit path.
        const form = document.getElementById('stocktakeForm');
        form?.addEventListener('submit', function (event) {
            if (panel.hidden) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            message('شمارش دسته‌ای در این فاز فقط داخل صفحه ثبت می‌شود. برای ثبت محصولی به فرم محصولی برگرد.', true);
        }, true);

        document.getElementById('stocktakeInlinePreviewClose')?.addEventListener('click', function () {
            if (discardConfirmation()) reset();
        });
        document.getElementById('product')?.addEventListener('change', function () {
            if (!panel.hidden) message('محدوده شمارش دسته‌ای هنوز فعال است. برای بازگشت، از دکمه مخصوص استفاده کن.', true);
        });
        category.addEventListener('change', function () {
            if (!panel.hidden) message('دسته انتخابی تغییر کرد. برای نمایش محدوده جدید، دکمه نمایش دسته را بزن؛ شمارش فعلی تا آن زمان حفظ می‌شود.', false);
        });
        subcategory.addEventListener('change', function () {
            if (!panel.hidden) message('زیردسته انتخابی تغییر کرد. برای نمایش محدوده جدید، دکمه نمایش دسته را بزن؛ شمارش فعلی تا آن زمان حفظ می‌شود.', false);
        });
        button.addEventListener('click', function () {
            const selected = Number(subcategory.value || category.value);
            if (!Number.isSafeInteger(selected) || selected < 1) {
                panel.hidden = false;
                message('ابتدا دسته یا زیردسته را انتخاب کن.', true);
                return;
            }
            if (!discardConfirmation()) return;
            reset();
            scopeId = selected;
            hasMore = true;
            panel.hidden = false;
            loadPage();
        });
        more.addEventListener('click', loadPage);
        window.addEventListener('beforeunload', function (event) {
            if (entered.size === 0) return;
            event.preventDefault();
            event.returnValue = '';
        });
    });
})();
