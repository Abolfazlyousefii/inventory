document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('productsApp');
    const body = document.getElementById('productsBody');
    const selectLoaded = document.getElementById('selectLoadedProducts');
    const count = document.getElementById('selectedProductsCount');
    const clear = document.getElementById('clearProductSelection');
    const selectVisible = document.getElementById('selectVisibleProducts');
    const download = document.getElementById('downloadSelectedProducts');
    const error = document.getElementById('productExportError');
    if (!app?.dataset.selectionExportUrl || !body || !selectLoaded) return;

    const selected = new Set();
    const maxProducts = 200;
    let downloading = false;

    function showError(message = '') {
        error.textContent = message;
        error.hidden = !message;
    }

    function syncControls() {
        const visibleBoxes = [...body.querySelectorAll('.product-select')];
        const visibleSelected = visibleBoxes.filter(box => selected.has(Number(box.value))).length;
        selectLoaded.checked = visibleBoxes.length > 0 && visibleSelected === visibleBoxes.length;
        selectLoaded.indeterminate = visibleSelected > 0 && visibleSelected < visibleBoxes.length;
        selectLoaded.disabled = visibleBoxes.length === 0;
        selectVisible.disabled = downloading || visibleBoxes.length === 0;
        selectVisible.textContent = selectLoaded.checked ? 'برداشتن تیک‌های بارگذاری‌شده' : 'انتخاب همهٔ بارگذاری‌شده‌ها';
        for (const box of visibleBoxes) box.checked = selected.has(Number(box.value));
        count.textContent = selected.size ? `${selected.size.toLocaleString('fa-IR')} کالا انتخاب شده` : 'کالایی انتخاب نشده است.';
        clear.disabled = downloading || selected.size === 0;
        download.disabled = downloading || selected.size === 0;
        download.textContent = downloading ? 'در حال ساخت فایل...' : 'دریافت PDF کالاهای انتخابی';
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

    download.addEventListener('click', async () => {
        if (downloading || selected.size === 0) return;
        downloading = true;
        showError();
        syncControls();
        try {
            const form = new FormData();
            for (const id of selected) form.append('product_ids[]', String(id));
            const response = await fetch(app.dataset.selectionExportUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'Accept': 'application/pdf, application/json' },
                credentials: 'same-origin',
                body: form,
            });
            if (!response.ok) {
                const payload = await response.json().catch(() => ({}));
                throw new Error(payload.message || 'ساخت فایل انجام نشد. دوباره تلاش کنید.');
            }
            const blob = await response.blob();
            if (!blob.size || !blob.type.includes('pdf')) throw new Error('فایل PDF معتبر دریافت نشد.');
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `ariya-products-${new Date().toISOString().slice(0, 10)}.pdf`;
            document.body.append(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 60000);
        } catch (exception) {
            showError(exception.message || 'ساخت فایل انجام نشد. دوباره تلاش کنید.');
        } finally {
            downloading = false;
            syncControls();
        }
    });
});
