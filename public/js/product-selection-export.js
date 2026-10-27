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
    let submitting = false;

    function showError(message = '') {
        error.textContent = message;
        error.hidden = !message;
    }

    function syncControls() {
        const visibleBoxes = [...body.querySelectorAll('.product-select')];
        const visibleSelected = visibleBoxes.filter(box => selected.has(Number(box.value))).length;
        selectLoaded.checked = visibleBoxes.length > 0 && visibleSelected === visibleBoxes.length;
        selectLoaded.indeterminate = visibleSelected > 0 && visibleSelected < visibleBoxes.length;
        selectLoaded.disabled = submitting || visibleBoxes.length === 0;
        selectVisible.disabled = submitting || visibleBoxes.length === 0;
        selectVisible.textContent = selectLoaded.checked ? 'برداشتن تیک‌های بارگذاری‌شده' : 'انتخاب همهٔ بارگذاری‌شده‌ها';
        for (const box of visibleBoxes) box.checked = selected.has(Number(box.value));
        count.textContent = selected.size ? `${selected.size.toLocaleString('fa-IR')} کالا انتخاب شده` : 'کالایی انتخاب نشده است.';
        clear.disabled = submitting || selected.size === 0;
        download.disabled = submitting || selected.size === 0;
        download.textContent = 'چاپ / ذخیره PDF';
    }

    // Each product row now includes its own checkbox when export is allowed.
    // Observe newly fetched rows only to sync checked state and counts; avoid
    // injecting DOM nodes or shifting table columns after initial render.
    new MutationObserver(syncControls).observe(body, {childList: true});
    syncControls();

    body.addEventListener('change', event => {
        const box = event.target.closest('.product-select');
        if (!box) return;
        if (submitting) { syncControls(); return; }
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

    download.addEventListener('click', () => {
        if (submitting || selected.size === 0) return;
        if (!app.dataset.stockPrintUrl) {
            showError('صفحه چاپ بارگذاری نشده است. صفحه را تازه‌سازی کنید.');
            return;
        }

        submitting = true;
        showError();
        syncControls();
        try {
            // Native form navigation preserves the active session and opens a
            // dedicated print preview. No server-side PDF rendering or CSV file.
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = app.dataset.stockPrintUrl;
            form.target = '_blank';
            form.hidden = true;
            const append = (name, value) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = String(value);
                form.appendChild(input);
            };
            append('_token', csrf());
            for (const id of selected) append('product_ids[]', id);
            document.body.appendChild(form);
            try {
                form.submit();
            } finally {
                form.remove();
            }
        } catch (error) {
            showError('باز کردن پیش‌نمایش چاپ موفق نبود؛ دوباره تلاش کنید.');
        } finally {
            submitting = false;
            syncControls();
        }
    });
});
