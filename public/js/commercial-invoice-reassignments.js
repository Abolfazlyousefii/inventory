(() => {
    const app = document.getElementById('invoiceReassignmentApp');
    if (!app) return;

    const searchUrl = app.dataset.searchUrl;
    const previewUrl = app.dataset.previewUrl;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const searchInput = document.getElementById('invoiceReassignmentSearch');
    const sellerFilter = document.getElementById('invoiceCurrentSellerFilter');
    const searchButton = document.getElementById('invoiceSearchButton');
    const resetButton = document.getElementById('invoiceSearchReset');
    const rows = document.getElementById('invoiceSearchRows');
    const state = document.getElementById('invoiceSearchState');
    const empty = document.getElementById('invoiceSearchEmpty');
    const errorBox = document.getElementById('invoiceSearchError');
    const selectVisible = document.getElementById('invoiceSelectVisible');
    const selectedCount = document.getElementById('selectedInvoiceCount');
    const selectedInputs = document.getElementById('invoiceSelectedInputs');
    const destinationSeller = document.getElementById('destinationSeller');
    const reason = document.getElementById('transferReason');
    const previewButton = document.getElementById('previewTransferButton');
    const submitButton = document.getElementById('confirmTransferButton');
    const clearButton = document.getElementById('clearSelectedInvoices');
    const transferForm = document.getElementById('invoiceTransferForm');
    const previewCard = document.getElementById('invoiceTransferPreview');
    const previewStats = document.getElementById('invoicePreviewStats');
    const previewRows = document.getElementById('invoicePreviewRows');
    const previewToken = document.getElementById('invoicePreviewToken');

    const selected = new Map();
    let visibleInvoices = [];
    let previewIsFresh = false;
    let searchController = null;

    const fa = value => new Intl.NumberFormat('fa-IR').format(Number(value || 0));
    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));

    function setError(message = '') {
        errorBox.hidden = !message;
        errorBox.textContent = message;
    }

    function validationMessage(payload, fallback) {
        if (payload?.errors) {
            return Object.values(payload.errors).flat().join(' ');
        }
        return payload?.message || fallback;
    }

    function invalidatePreview() {
        previewIsFresh = false;
        previewToken.value = '';
        previewCard.hidden = true;
        submitButton.disabled = true;
    }

    function syncSelectedUi() {
        selectedCount.textContent = fa(selected.size);
        selectedInputs.innerHTML = '';
        [...selected.keys()].sort((a, b) => a - b).forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'invoice_ids[]';
            input.value = String(id);
            selectedInputs.appendChild(input);
        });

        const hasSelection = selected.size > 0;
        previewButton.disabled = !hasSelection;
        clearButton.disabled = !hasSelection;
        if (!hasSelection) invalidatePreview();

        document.querySelectorAll('[data-invoice-select]').forEach(box => {
            const id = Number(box.dataset.invoiceSelect);
            box.checked = selected.has(id);
            box.closest('tr')?.classList.toggle('is-selected', box.checked);
        });

        const visibleIds = visibleInvoices.map(item => Number(item.id));
        const selectedVisible = visibleIds.filter(id => selected.has(id)).length;
        selectVisible.checked = visibleIds.length > 0 && selectedVisible === visibleIds.length;
        selectVisible.indeterminate = selectedVisible > 0 && selectedVisible < visibleIds.length;
    }

    function claimHtml(invoice) {
        const claims = invoice.commission_claims || [];
        if (!claims.length) return '<span class="commission-free">آزاد / بدون سند فعال</span>';
        const first = claims[0];
        const more = claims.length > 1 ? `<span class="commission-multiple">${fa(claims.length)} اتصال فعال؛ نیازمند اصلاح خودکار</span>` : '';
        return `<div class="commission-claim"><strong>${escapeHtml(first.document_number)}</strong><small>${escapeHtml(first.seller_name)} · ${escapeHtml(first.amount_display)}</small>${more}</div>`;
    }

    function renderRows(data) {
        visibleInvoices = data;
        rows.innerHTML = data.map(invoice => `
            <tr data-invoice-row="${invoice.id}">
                <td class="invoice-select-col"><input type="checkbox" class="form-check-input" data-invoice-select="${invoice.id}" aria-label="انتخاب فاکتور ${escapeHtml(invoice.number)}"></td>
                <td><span class="invoice-number">${escapeHtml(invoice.number)}</span><span class="invoice-meta">${escapeHtml(invoice.date)} · ${escapeHtml(invoice.status_label)}</span>${invoice.is_cancelled ? '<span class="invoice-cancelled">لغوشده</span>' : ''}</td>
                <td><strong>${escapeHtml(invoice.customer)}</strong>${invoice.mobile ? `<span class="invoice-meta">${escapeHtml(invoice.mobile)}</span>` : ''}</td>
                <td>${invoice.seller ? `<span class="seller-chip">${escapeHtml(invoice.seller.name)}</span>` : '<span class="text-muted">بدون فروشنده</span>'}</td>
                <td>${claimHtml(invoice)}</td>
                <td><strong>${escapeHtml(invoice.total_display)}</strong></td>
            </tr>
        `).join('');

        rows.querySelectorAll('[data-invoice-select]').forEach(box => {
            box.addEventListener('change', () => {
                const id = Number(box.dataset.invoiceSelect);
                const item = visibleInvoices.find(invoice => Number(invoice.id) === id);
                if (box.checked && item) {
                    if (selected.size >= 100 && !selected.has(id)) {
                        box.checked = false;
                        setError('حداکثر ۱۰۰ فاکتور را می‌توان در یک عملیات انتقال داد.');
                        return;
                    }
                    selected.set(id, item);
                } else {
                    selected.delete(id);
                }
                invalidatePreview();
                syncSelectedUi();
            });
        });
        syncSelectedUi();
    }

    async function search() {
        setError('');
        state.hidden = false;
        empty.hidden = true;
        state.textContent = 'در حال دریافت فاکتورها…';
        searchButton.disabled = true;
        searchController?.abort();
        searchController = new AbortController();

        const params = new URLSearchParams();
        if (searchInput.value.trim()) params.set('q', searchInput.value.trim());
        if (sellerFilter.value) params.set('seller_id', sellerFilter.value);

        try {
            const response = await fetch(`${searchUrl}?${params.toString()}`, {
                headers: {'Accept': 'application/json'},
                signal: searchController.signal,
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(validationMessage(payload, 'دریافت فاکتورها انجام نشد.'));
            renderRows(payload.data || []);
            state.textContent = `${fa((payload.data || []).length)} فاکتور نمایش داده شد؛ حداکثر ۵۰ نتیجه آخر.`;
            empty.hidden = (payload.data || []).length > 0;
        } catch (error) {
            if (error.name === 'AbortError') return;
            rows.innerHTML = '';
            visibleInvoices = [];
            state.textContent = '';
            setError(error.message || 'دریافت فاکتورها انجام نشد.');
        } finally {
            searchButton.disabled = false;
        }
    }

    function renderPreview(payload) {
        const summary = payload.summary;
        previewStats.innerHTML = `
            <div class="preview-stat"><span>فاکتورهای انتخاب‌شده</span><strong>${fa(summary.invoice_count)}</strong></div>
            <div class="preview-stat"><span>تغییر مالک فروش</span><strong>${fa(summary.seller_change_count)}</strong></div>
            <div class="preview-stat"><span>اتصال پورسانتی قابل آزادسازی</span><strong class="preview-release">${fa(summary.commission_release_count)}</strong></div>
            <div class="preview-stat"><span>جمع مبلغ فاکتورها</span><strong>${escapeHtml(summary.total_display)}</strong></div>
        `;
        previewRows.innerHTML = (payload.data || []).map(item => {
            const release = Number(item.commission_claims_to_release || 0);
            const effect = release > 0
                ? `<span class="preview-release">${fa(release)} اتصال از سند قبلی آزاد می‌شود</span>`
                : item.seller_will_change
                    ? '<span>مالک فروش تغییر می‌کند؛ سند فعال قبلی ندارد</span>'
                    : '<span class="preview-noop">فروشنده از قبل مقصد است؛ تغییری لازم نیست</span>';
            return `<tr><td><strong class="invoice-number">${escapeHtml(item.number)}</strong><span class="invoice-meta">${escapeHtml(item.customer)}</span></td><td>${item.seller ? escapeHtml(item.seller.name) : 'بدون فروشنده'}</td><td><strong>${escapeHtml(summary.destination_seller.name)}</strong></td><td>${effect}</td><td>${escapeHtml(item.total_display)}</td></tr>`;
        }).join('');
        previewCard.hidden = false;
        previewIsFresh = true;
        submitButton.disabled = false;
        previewCard.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    async function preview() {
        setError('');
        if (!selected.size) return setError('حداقل یک فاکتور انتخاب کنید.');
        if (!destinationSeller.value) return setError('فروشنده مقصد را انتخاب کنید.');
        if (!reason.value.trim()) return setError('دلیل انتقال را وارد کنید.');

        previewButton.disabled = true;
        try {
            const response = await fetch(previewUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({
                    invoice_ids: [...selected.keys()],
                    seller_id: Number(destinationSeller.value),
                    reason: reason.value.trim(),
                    sync_preinvoice: document.getElementById('syncPreinvoice')?.checked ? 1 : 0,
                }),
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(validationMessage(payload, 'پیش‌نمایش انتقال انجام نشد.'));
            previewToken.value = payload.preview_token || '';
            if (!previewToken.value) throw new Error('توکن پیش‌نمایش معتبر دریافت نشد.');
            renderPreview(payload);
        } catch (error) {
            setError(error.message || 'پیش‌نمایش انتقال انجام نشد.');
        } finally {
            previewButton.disabled = selected.size === 0;
        }
    }

    selectVisible.addEventListener('change', () => {
        if (selectVisible.checked) {
            for (const item of visibleInvoices) {
                if (selected.size >= 100 && !selected.has(Number(item.id))) break;
                selected.set(Number(item.id), item);
            }
        } else {
            visibleInvoices.forEach(item => selected.delete(Number(item.id)));
        }
        invalidatePreview();
        syncSelectedUi();
    });

    searchButton.addEventListener('click', search);
    searchInput.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            search();
        }
    });
    sellerFilter.addEventListener('change', search);
    resetButton.addEventListener('click', () => {
        searchInput.value = '';
        sellerFilter.value = '';
        search();
    });
    clearButton.addEventListener('click', () => {
        selected.clear();
        invalidatePreview();
        syncSelectedUi();
    });
    destinationSeller.addEventListener('change', invalidatePreview);
    reason.addEventListener('input', invalidatePreview);
    document.getElementById('syncPreinvoice')?.addEventListener('change', invalidatePreview);
    previewButton.addEventListener('click', preview);

    transferForm.addEventListener('submit', event => {
        if (!selected.size) {
            event.preventDefault();
            return setError('حداقل یک فاکتور انتخاب کنید.');
        }
        if (!previewIsFresh) {
            event.preventDefault();
            return setError('قبل از انتقال، دکمه «بررسی قبل از انتقال» را بزنید و پیش‌نمایش را بررسی کنید.');
        }
        if (!window.confirm(`انتقال ${selected.size} فاکتور به فروشنده انتخاب‌شده انجام شود؟ تاریخچه حذف نمی‌شود و اتصالات پورسانتی قبلی آزاد خواهند شد.`)) {
            event.preventDefault();
            return;
        }
        submitButton.disabled = true;
        submitButton.textContent = 'در حال انتقال…';
    });

    search();
})();
