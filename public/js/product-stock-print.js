document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    const app = document.getElementById('stockPrintApp');
    if (!app) return;

    const printButton = document.getElementById('printNow');
    const retryButton = document.getElementById('cancelPrintLoad');
    const closeButton = document.getElementById('closePrint');
    const status = document.getElementById('printStatus');
    const progress = document.getElementById('printProgress');
    const totalEl = document.getElementById('printTotalRows');
    const loadedEl = document.getElementById('printLoadedRows');
    const tbody = document.getElementById('printTableBody');

    const productIds = JSON.parse(app.dataset.productIds || '[]');
    const maxRows = Number(app.dataset.maxRows || 2000);
    const persian = value => Number(value || 0).toLocaleString('fa-IR');
    let currentController = null;
    let running = false;
    let stopped = false;

    function state(message, isError = false) {
        status.textContent = message;
        status.classList.toggle('error', isError);
        status.hidden = false;
    }

    function td(value, className = '') {
        const cell = document.createElement('td');
        cell.textContent = String(value ?? '—');
        if (className) cell.className = className;
        return cell;
    }

    function printRow(row, index) {
        const tr = document.createElement('tr');
        tr.appendChild(td(persian(index), 'numeric'));
        tr.appendChild(td(row.product_name || 'کالا', 'product-name'));
        tr.appendChild(td([row.product_code, row.product_sku].filter(Boolean).join(' / ') || '—', 'code'));
        tr.appendChild(td([row.variant_name, row.variant_code].filter(Boolean).join(' — ') || 'کالای بدون تنوع'));
        tr.appendChild(td([row.brand_name, row.model_name, row.color_name, row.variety_name].filter(Boolean).join(' / ') || '—'));
        tr.appendChild(td(persian(row.stock), 'numeric'));
        tr.appendChild(td(persian(row.reserved), 'numeric'));
        tr.appendChild(td(persian(row.sell_price_rial), 'price'));
        tr.appendChild(td(row.sales_enabled ? 'مجاز' : 'غیرفعال'));
        return tr;
    }

    async function request(url, body) {
        const controller = new AbortController();
        currentController = controller;
        const timeout = setTimeout(() => controller.abort(), 25000);
        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify(body),
                signal: controller.signal,
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                const message = result.errors && Object.values(result.errors).flat().join('، ');
                throw new Error(message || result.message || 'سرور امکان دریافت اطلاعات را نداد.');
            }
            return result;
        } finally {
            clearTimeout(timeout);
            if (currentController === controller) currentController = null;
        }
    }

    async function renderRows(rows, baseIndex) {
        // Avoid a single long main-thread task while creating large paper tables.
        for (let offset = 0; offset < rows.length; offset += 150) {
            if (stopped) throw new Error('بارگیری متوقف شد.');
            const fragment = document.createDocumentFragment();
            const page = rows.slice(offset, offset + 150);
            page.forEach((row, index) => fragment.appendChild(printRow(row, baseIndex + offset + index + 1)));
            tbody.appendChild(fragment);
            await new Promise(resolve => requestAnimationFrame(resolve));
        }
    }

    async function load() {
        if (running) return;
        running = true;
        stopped = false;
        tbody.replaceChildren();
        printButton.disabled = true;
        retryButton.disabled = false;
        retryButton.hidden = false;
        retryButton.textContent = 'توقف بارگیری';
        progress.value = 0;
        totalEl.textContent = '—';
        loadedEl.textContent = persian(0);
        state('در حال دریافت اقلام و بررسی موجودی...');

        let loaded = 0;
        try {
            const init = await request(app.dataset.startUrl, {product_ids: productIds});
            const total = Number(init.total_rows);
            if (!Number.isSafeInteger(total) || total < 0 || total > maxRows) {
                throw new Error('حجم گزارش برای چاپ زیاد است؛ تعداد کالاهای انتخابی را کمتر کنید.');
            }
            totalEl.textContent = persian(total);
            if (total === 0) {
                state('هیچ تنوع فعال و دارای موجودی در این انتخاب پیدا نشد.');
                retryButton.hidden = true;
                return;
            }

            let cursor = 0;
            let hasMore = true;
            while (hasMore) {
                if (stopped) throw new Error('بارگیری متوقف شد.');
                const data = await request(app.dataset.batchUrl, {product_ids: productIds, after_id: cursor});
                if (!Array.isArray(data.rows) || (data.has_more && data.rows.length === 0)) {
                    throw new Error('دریافت ردیف‌ها ناقص است؛ دوباره تلاش کنید.');
                }
                if (loaded + data.rows.length > maxRows) {
                    throw new Error('تعداد ردیف‌ها از سقف مجاز چاپ بیشتر شد؛ تعداد کالاها را کاهش دهید.');
                }
                await renderRows(data.rows, loaded);
                loaded += data.rows.length;
                loadedEl.textContent = persian(loaded);
                progress.value = Math.min(99, Math.floor(loaded / total * 100));
                state('در حال دریافت اقلام: ' + persian(loaded) + ' از ' + persian(total) + ' ردیف');
                hasMore = Boolean(data.has_more);
                const next = Number(data.next_cursor);
                if (hasMore && (!Number.isSafeInteger(next) || next <= cursor)) {
                    throw new Error('صفحه‌بندی موجودی نامعتبر است.');
                }
                cursor = next;
            }

            if (stopped) throw new Error('بارگیری متوقف شد.');
            if (loaded !== total) {
                state('موجودی هنگام تهیه گزارش تغییر کرده است (' + persian(loaded) + ' ردیف دریافت شد). برای چاپ دقیق، گزارش را مجدداً بارگیری کنید.', true);
                retryButton.textContent = 'بارگیری مجدد';
                return;
            }
            progress.value = 100;
            state('گزارش کامل آماده چاپ است؛ روی «چاپ / ذخیره PDF» کلیک کنید.');
            printButton.disabled = false;
            retryButton.hidden = true;
        } catch (error) {
            if (stopped) state('دریافت اطلاعات متوقف شد. برای ادامه، بارگیری را مجدداً انجام دهید.', true);
            else state(error.name === 'AbortError'
                ? 'مهلت دریافت پاسخ از سرور پایان یافت. دوباره تلاش کنید.'
                : (error.message || 'خطا در دریافت اطلاعات.'), true);
            retryButton.textContent = 'بارگیری مجدد';
        } finally {
            running = false;
        }
    }

    printButton.addEventListener('click', () => {
        if (!printButton.disabled) window.print();
    });
    closeButton.addEventListener('click', () => window.close());
    retryButton.addEventListener('click', () => {
        if (running) {
            stopped = true;
            currentController?.abort();
            retryButton.disabled = true;
            return;
        }
        load();
    });

    if (app.dataset.error) {
        state(app.dataset.error, true);
        retryButton.hidden = true;
    } else {
        load();
    }
});
