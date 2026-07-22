const page = document.querySelector('[data-product-export-page]');

if (page) {
    const form = document.getElementById('productExportForm');
    const result = document.getElementById('productExportResult');
    const root = document.getElementById('root-category');
    const child = document.getElementById('subcategory');
    const brand = document.getElementById('model-brand');
    const grid = document.getElementById('modelGrid');
    const search = document.getElementById('modelSearch');
    const count = document.getElementById('modelCount');
    const downloadButton = document.getElementById('downloadProductsButton');
    let aborter;
    let childAborter;
    let modelAborter;
    let timer;
    const selected = new Set(JSON.parse(page.dataset.selectedModels || '[]').map(String));
    const fa = (number) => new Intl.NumberFormat('fa-IR').format(number);
    const params = () => new URLSearchParams(new FormData(form));

    function updateSelectedCount() {
        count.textContent = `${fa(selected.size)} مدل انتخاب‌شده`;
    }

    function hideBrokenImages(scope = result) {
        scope.querySelectorAll('.pe-product-image img').forEach((image) => {
            image.addEventListener('error', () => image.closest('.pe-product-image')?.remove(), { once: true });
        });
    }

    function bindPreviewEnhancements(scope = result) {
        hideBrokenImages(scope);

        scope.querySelectorAll('.pe-colors-toggle').forEach((button) => {
            button.addEventListener('click', () => toggleColors(button));
        });
    }

    function toggleColors(button) {
        const shell = button.closest('.pe-colors-preview');
        const preview = shell?.querySelector('.pe-colors-grid--preview');
        const all = shell?.querySelector('.pe-colors-grid--all');
        if (!shell || !preview || !all) return;
        const expanded = all.hidden === false;
        all.hidden = expanded;
        preview.hidden = !expanded;
        button.textContent = expanded ? shell.dataset.collapsedLabel : shell.dataset.expandedLabel;
    }

    function refresh() {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            aborter?.abort();
            aborter = new AbortController();
            result.classList.add('is-loading');
            try {
                const query = params();
                const response = await fetch(`${page.dataset.dataUrl}?${query}`, {
                    headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: aborter.signal,
                });
                if (!response.ok) throw new Error(response.status);
                const loading = result.querySelector('.pe-loading')?.outerHTML || '<div class="pe-loading">در حال دریافت اطلاعات...</div>';
                result.innerHTML = loading + await response.text();
                history.replaceState({}, '', `${form.action}?${query}`);
                bindPreviewEnhancements(result);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    result.insertAdjacentHTML('afterbegin', '<div class="alert alert-danger m-3">دریافت اطلاعات با خطا روبه‌رو شد.</div>');
                }
            } finally {
                result.classList.remove('is-loading');
            }
        }, 250);
    }

    async function loadModels(clearSelection = true) {
        modelAborter?.abort();
        modelAborter = new AbortController();
        if (clearSelection) selected.clear();
        updateSelectedCount();
        grid.innerHTML = '<div class="pe-model-empty">در حال بارگیری...</div>';
        if (!brand.value) {
            grid.innerHTML = '<div class="pe-model-empty">ابتدا نوع مدل را انتخاب کنید.</div>';
            refresh();
            return;
        }
        try {
            const response = await fetch(`${page.dataset.modelListsUrl}?${new URLSearchParams({ brand: brand.value })}`, {
                headers: { Accept: 'application/json' },
                signal: modelAborter.signal,
            });
            const data = await response.json();
            grid.innerHTML = '';
            if (!data.items.length) grid.innerHTML = '<div class="pe-model-empty">مدلی برای این نوع پیدا نشد.</div>';
            data.items.forEach((item) => {
                const label = document.createElement('label');
                label.className = 'pe-model-option';
                label.dataset.name = `${item.name || ''} ${item.code || ''}`.toLowerCase();
                label.innerHTML = `<input type="checkbox" name="model_list_ids[]" value="${item.id}"><span class="pe-model-option__check"></span><span class="pe-model-option__text"><strong dir="ltr">${item.name || ''}</strong>${item.code ? `<small>کد ${item.code}</small>` : ''}</span>`;
                const input = label.querySelector('input');
                input.checked = selected.has(String(item.id));
                input.addEventListener('change', () => {
                    input.checked ? selected.add(input.value) : selected.delete(input.value);
                    updateSelectedCount();
                    refresh();
                });
                grid.appendChild(label);
            });
            updateSelectedCount();
        } catch (error) {
            if (error.name !== 'AbortError') grid.innerHTML = '<div class="pe-model-empty">خطا در دریافت مدل‌ها</div>';
        }
        refresh();
    }

    async function loadChildren() {
        childAborter?.abort();
        childAborter = new AbortController();
        child.innerHTML = '<option value="">در حال بارگیری...</option>';
        child.disabled = true;
        if (!root.value) {
            child.innerHTML = '<option value="">همه زیردسته‌ها</option>';
            child.disabled = false;
            refresh();
            return;
        }
        try {
            const response = await fetch(page.dataset.childrenUrlTemplate.replace('__ID__', root.value), {
                headers: { Accept: 'application/json' },
                signal: childAborter.signal,
            });
            const data = await response.json();
            child.innerHTML = '<option value="">همه زیردسته‌ها</option>';
            data.items.forEach((item) => child.add(new Option(item.name, item.id)));
        } finally {
            child.disabled = false;
            refresh();
        }
    }

    function filterModels() {
        const query = search.value.trim().toLowerCase();
        grid.querySelectorAll('.pe-model-option').forEach((option) => {
            option.style.display = !query || option.dataset.name.includes(query) ? 'flex' : 'none';
        });
    }

    function selectAll() {
        grid.querySelectorAll('.pe-model-option').forEach((option) => {
            if (option.style.display === 'none') return;
            const input = option.querySelector('input');
            input.checked = true;
            selected.add(input.value);
        });
        updateSelectedCount();
        refresh();
    }

    function clearSelection() {
        selected.clear();
        grid.querySelectorAll('input[type="checkbox"]').forEach((input) => { input.checked = false; });
        updateSelectedCount();
        refresh();
    }

    root.addEventListener('change', loadChildren);
    brand.addEventListener('change', () => loadModels(true));
    search.addEventListener('input', filterModels);
    document.getElementById('selectVisibleModels').addEventListener('click', selectAll);
    document.getElementById('clearModels').addEventListener('click', clearSelection);
    form.addEventListener('change', (event) => {
        if (![root, brand].includes(event.target) && event.target.type !== 'checkbox') refresh();
    });
    form.addEventListener('submit', (event) => { event.preventDefault(); refresh(); });
    downloadButton?.addEventListener('click', () => { window.location.assign(`${page.dataset.downloadUrl}?${params()}`); });
    bindPreviewEnhancements(document);
    if (brand.value) loadModels(false); else updateSelectedCount();
}
