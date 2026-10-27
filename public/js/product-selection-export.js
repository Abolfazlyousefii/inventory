document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('productsApp');
    const body = document.getElementById('productsBody');
    const selectLoaded = document.getElementById('selectLoadedProducts');
    const count = document.getElementById('selectedProductsCount');
    const clear = document.getElementById('clearProductSelection');
    const selectVisible = document.getElementById('selectVisibleProducts');
    const download = document.getElementById('downloadSelectedProducts');
    const pdfButton = document.getElementById('downloadSelectedProductsPdf');
    const cancelButton = document.getElementById('cancelStockExport');
    const progressWrap = document.getElementById('productExportProgressWrap');
    const progressBar = document.getElementById('productExportProgress');
    const progressLabel = document.getElementById('productExportProgressLabel');
    const error = document.getElementById('productExportError');
    if (!app?.dataset.selectionExportUrl || !body || !selectLoaded) return;

    const selected = new Set();
    const maxProducts = 200;
    let downloading = false;
    let activeController = null;
    let cancelled = false;

    function showError(message = '') {
        error.textContent = message;
        error.hidden = !message;
    }

    function syncControls() {
        const visibleBoxes = [...body.querySelectorAll('.product-select')];
        const visibleSelected = visibleBoxes.filter(box => selected.has(Number(box.value))).length;
        selectLoaded.checked = visibleBoxes.length > 0 && visibleSelected === visibleBoxes.length;
        selectLoaded.indeterminate = visibleSelected > 0 && visibleSelected < visibleBoxes.length;
        selectLoaded.disabled = downloading || visibleBoxes.length === 0;
        selectVisible.disabled = downloading || visibleBoxes.length === 0;
        selectVisible.textContent = selectLoaded.checked ? 'برداشتن تیک‌های بارگذاری‌شده' : 'انتخاب همهٔ بارگذاری‌شده‌ها';
        for (const box of visibleBoxes) box.checked = selected.has(Number(box.value));
        count.textContent = selected.size ? `${selected.size.toLocaleString('fa-IR')} کالا انتخاب شده` : 'کالایی انتخاب نشده است.';
        clear.disabled = downloading || selected.size === 0;
        download.disabled = downloading || selected.size === 0;
        download.textContent = downloading ? 'در حال دریافت ردیف‌ها...' : 'خروجی ریز موجودی (Excel)';
        pdfButton.disabled = downloading || selected.size === 0 || selected.size > 5;
        pdfButton.title = selected.size > 5 ? 'PDF سنگین است؛ برای بیش از ۵ کالا از خروجی Excel استفاده کنید.' : 'PDF سبک تا ۵ کالا';
        cancelButton.hidden = !downloading;
    }

    function decorateRows() {
        for (const row of body.querySelectorAll('tr[data-product-id]')) {
            if (row.querySelector('.product-select')) continue;
            const id = Number(row.dataset.productId);
            if (!Number.isSafeInteger(id) || id <= 0) continue;
            const cell = document.createElement('td');
            cell.className = 'select-col';
            const label = document.createElement('label');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'product-select';
            checkbox.value = String(id);
            checkbox.setAttribute('aria-label', 'انتخاب کالا برای خروجی');
            label.append(checkbox);
            const mobileLabel = document.createElement('span');
            mobileLabel.className = 'desktop-label';
            mobileLabel.textContent = ' انتخاب برای خروجی';
            label.append(mobileLabel);
            cell.append(label);
            row.prepend(cell);
        }
        syncControls();
    }

    new MutationObserver(decorateRows).observe(body, {childList: true});
    decorateRows();

    body.addEventListener('change', event => {
        const box = event.target.closest('.product-select');
        if (!box) return;
        if (downloading) { syncControls(); return; }
        const id = Number(box.value);
        if (box.checked && selected.size >= maxProducts && !selected.has(id)) {
            box.checked = false;
            showError('حداکثر ۲۰۰ کالا را می‌توان در یک فایل انتخاب کرد.');
            return;
        }
        box.checked ? selected.add(id) : selected.delete(id);
        showError();
        syncControls();
    });

    function toggleVisible(shouldSelect) {
        const boxes = [...body.querySelectorAll('.product-select')];
        if (shouldSelect) {
            for (const box of boxes) {
                if (selected.size >= maxProducts) break;
                selected.add(Number(box.value));
            }
            if (boxes.some(box => !selected.has(Number(box.value)))) {
                showError('حداکثر ۲۰۰ کالا انتخاب شد؛ برای خروجی بیشتر، ابتدا این فایل را دریافت کنید.');
            } else showError();
        } else {
            boxes.forEach(box => selected.delete(Number(box.value)));
            showError();
        }
        syncControls();
    }

    selectLoaded.addEventListener('change', () => toggleVisible(selectLoaded.checked));
    selectVisible.addEventListener('click', () => toggleVisible(!selectLoaded.checked));

    clear.addEventListener('click', () => {
        selected.clear();
        showError();
        syncControls();
    });

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    function updateProgress(done, total) {
        const percent = total > 0 ? Math.min(100, Math.floor(done * 100 / total)) : 0;
        progressBar.value = percent;
        progressLabel.textContent = percent.toLocaleString('fa-IR') + '٪ — ' + done.toLocaleString('fa-IR') + ' از ' + total.toLocaleString('fa-IR') + ' ردیف';
    }

    async function postJson(url, body) {
        activeController = new AbortController();
        const timeout = setTimeout(() => activeController?.abort(), 25000);
        try {
            const response = await fetch(url, {
                method: 'POST', credentials: 'same-origin',
                headers: {'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json', 'Content-Type': 'application/json'},
                body: JSON.stringify(body), signal: activeController.signal,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                const validation = payload.errors && Object.values(payload.errors).flat().join('، ');
                throw new Error(validation || payload.message || 'درخواست خروجی موفق نبود؛ لطفاً تعداد کمتری کالا انتخاب کنید.');
            }
            return payload;
        } finally {
            clearTimeout(timeout);
            activeController = null;
        }
    }

    function saveBlob(blob, extension) {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'ariya-stock-variants-' + new Date().toISOString().slice(0, 10) + '.' + extension;
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    }

    cancelButton.addEventListener('click', () => {
        cancelled = true;
        activeController?.abort();
    });

    download.addEventListener('click', async () => {
        if (downloading || selected.size === 0) return;
        if (!window.ProductStockCsv) { showError('ماژول خروجی Excel بارگذاری نشده است. صفحه را تازه‌سازی کنید.'); return; }
        downloading = true;
        cancelled = false;
        showError();
        progressWrap.hidden = false;
        updateProgress(0, 0);
        syncControls();
        const ids = [...selected].sort((a, b) => a - b);
        const base = {product_ids: ids};
        try {
            const init = await postJson(app.dataset.stockExportStartUrl, base);
            const total = Number(init.total_rows);
            if (!Number.isSafeInteger(total) || total < 0) throw new Error('تعداد ردیف‌های خروجی معتبر نیست.');
            if (total === 0) throw new Error('از کالاهای انتخاب‌شده، هیچ تنوع فعال و دارای موجودی پیدا نشد.');
            const chunks = [window.ProductStockCsv.header()];
            let cursor = 0;
            let done = 0;
            let more = true;
            while (more) {
                if (cancelled) throw new DOMException('Export cancelled', 'AbortError');
                const result = await postJson(app.dataset.stockExportBatchUrl, {...base, after_id: cursor});
                const rows = result.rows || [];
                if (!Array.isArray(rows) || (rows.length === 0 && result.has_more)) {
                    throw new Error('صفحه‌بندی خروجی ناقص است؛ دوباره تلاش کنید.');
                }
                chunks.push(window.ProductStockCsv.rowsCsv(rows));
                done += rows.length;
                updateProgress(done, total);
                more = Boolean(result.has_more);
                const next = Number(result.next_cursor);
                if (more && (!Number.isSafeInteger(next) || next <= cursor)) {
                    throw new Error('ترتیب دریافت تنوع‌ها معتبر نیست.');
                }
                cursor = next;
                // Yield to browser; each server request is limited to 300 variant rows.
                if (more) await new Promise(resolve => setTimeout(resolve, 50));
            }
            if (cancelled) throw new DOMException('Export cancelled', 'AbortError');
            updateProgress(total, total);
            saveBlob(new Blob(chunks, {type: 'text/csv;charset=utf-8'}), 'csv');
        } catch (exception) {
            showError(cancelled ? 'خروجی لغو شد؛ هیچ تغییری در اطلاعات کالا انجام نشد.'
                : exception.name === 'AbortError' ? 'مهلت پاسخ سرور تمام شد یا اتصال قطع شد. لطفاً دوباره تلاش کنید.'
                : exception.message || 'خطا در دریافت موجودی کالا.');
        } finally {
            downloading = false;
            activeController = null;
            syncControls();
        }
    });

    pdfButton.addEventListener('click', async () => {
        if (downloading || selected.size === 0 || selected.size > 5) return;
        downloading = true;
        showError();
        syncControls();
        try {
            const form = new FormData();
            for (const id of selected) form.append('product_ids[]', String(id));
            const response = await fetch(app.dataset.selectionExportUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: {'X-CSRF-TOKEN': csrf(), 'Accept': 'application/pdf, application/json'},
                body: form,
            });
            if (!response.ok) {
                const payload = await response.json().catch(() => ({}));
                throw new Error(payload.errors?.product_ids?.[0] || payload.message || 'PDF ساخته نشد؛ از خروجی Excel استفاده کنید.');
            }
            const blob = await response.blob();
            if (!blob.size || !blob.type.includes('pdf')) throw new Error('فایل PDF معتبر دریافت نشد.');
            saveBlob(blob, 'pdf');
        } catch (exception) {
            showError(exception.message || 'برای خروجی‌های بزرگ از Excel استفاده کنید.');
        } finally {
            downloading = false;
            syncControls();
        }
    });
});
