document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('commissionApp');
    if (!app) return;

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    })[character]);
    const typeLabels = { category: 'دسته', product: 'کالا', variant: 'تنوع' };
    const canManageRates = app.dataset.canManageRates === '1';
    const canManageCampaigns = app.dataset.canManageCampaigns === '1';
    const cleanRate = (value) => value === null || value === undefined || value === ''
        ? null
        : String(Number.parseFloat(value));

    const nodeMarkup = (node) => {
        const own = cleanRate(node.own_rate);
        const inherited = cleanRate(node.inherited_rate);
        const effective = cleanRate(node.percentage);
        const badge = node.is_missing
            ? '<span class="commission-badge commission-badge--missing">فاقد نرخ</span>'
            : node.is_explicit_zero
                ? '<span class="commission-badge commission-badge--zero">بدون پورسانت</span>'
                : own !== null
                    ? `<span class="commission-badge commission-badge--own">${escapeHtml(own)}٪ اختصاصی</span>`
                    : `<span class="commission-badge commission-badge--inherited">${escapeHtml(effective)}٪ ارث‌بری</span>`;

        const actions = `<button type="button" class="btn btn-sm btn-outline-primary commission-select">${canManageRates ? 'تعیین/ویرایش نرخ' : 'مشاهده جزئیات نرخ'}</button>`
            + (canManageCampaigns ? '<button type="button" class="btn btn-sm btn-outline-success commission-campaign-target">افزودن به اقلام کمپین</button>' : '');

        return `<div class="commission-node" data-type="${escapeHtml(node.type)}" data-id="${escapeHtml(node.id)}"
            data-label="${escapeHtml(node.label)}" data-own="${escapeHtml(own ?? '')}"
            data-inherited="${escapeHtml(inherited ?? '')}" data-effective="${escapeHtml(effective ?? '')}"
            data-source="${escapeHtml(node.source_label || 'تعیین نشده')}">
            <div class="commission-node__head">
                <button type="button" class="commission-expand" ${node.has_children ? '' : 'disabled'} aria-expanded="false">
                    <span class="commission-node__toggle">${node.has_children ? '›' : '•'}</span>
                    <span class="commission-node__kind">${escapeHtml(typeLabels[node.type] || 'قلم')}</span>
                    <strong>${escapeHtml(node.label)}</strong>
                </button>
                <div class="commission-node__meta">${badge}<span class="commission-effective-rate">نرخ مؤثر: ${node.is_missing ? '—' : `${escapeHtml(effective)}٪`}</span></div>
                <div class="commission-node__actions">${actions}</div>
            </div><div class="commission-children d-none" aria-live="polite"></div></div>`;
    };

    const loadMoreMarkup = (payload, type, id) => payload.has_more
        ? `<button type="button" class="btn btn-sm btn-outline-secondary commission-load-more" data-type="${escapeHtml(type)}" data-id="${escapeHtml(id)}" data-page="${escapeHtml(payload.next_page)}">نمایش موارد بیشتر</button>`
        : '';

    const selectedTargets = new Map();
    const targetHost = document.getElementById('campaignTargets');
    const renderTargets = () => {
        if (!targetHost) return;
        targetHost.innerHTML = '';
        if (selectedTargets.size === 0) {
            targetHost.innerHTML = '<p class="text-muted mb-0" data-empty-targets>از درخت نرخ، گزینه «افزودن به اقلام کمپین» را انتخاب کنید.</p>';
            return;
        }
        selectedTargets.forEach((label, key) => {
            const chip = document.createElement('span');
            chip.className = 'commission-selected-item';
            chip.innerHTML = `<span>${escapeHtml(label)}</span><button type="button" aria-label="حذف ${escapeHtml(label)}">×</button><input type="hidden" name="targets[]" value="${escapeHtml(key)}">`;
            chip.querySelector('button').addEventListener('click', () => {
                selectedTargets.delete(key);
                renderTargets();
            });
            targetHost.appendChild(chip);
        });
    };
    const addTarget = (key, label) => {
        selectedTargets.set(key, label || 'قلم انتخاب‌شده');
        renderTargets();
    };

    let selectedRateNode = null;
    const tree = document.getElementById('commissionTree');
    const initialTreeMarkup = tree?.innerHTML || '';
    tree?.addEventListener('click', async (event) => {
        const wrapper = event.target.closest('.commission-node');
        if (!wrapper) return;

        const loadMoreButton = event.target.closest('.commission-load-more');
        if (loadMoreButton) {
            loadMoreButton.disabled = true;
            loadMoreButton.textContent = 'در حال بارگذاری…';
            try {
                const url = new URL(app.dataset.treeUrl, window.location.origin);
                url.search = new URLSearchParams({
                    type: loadMoreButton.dataset.type,
                    id: loadMoreButton.dataset.id,
                    q: '',
                    page: loadMoreButton.dataset.page
                });
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('load_more_failed');
                const payload = await response.json();
                loadMoreButton.insertAdjacentHTML('beforebegin', payload.items.map(nodeMarkup).join(''));
                if (payload.has_more) {
                    loadMoreButton.dataset.page = payload.next_page;
                    loadMoreButton.disabled = false;
                    loadMoreButton.textContent = 'نمایش موارد بیشتر';
                } else {
                    loadMoreButton.remove();
                }
            } catch (error) {
                loadMoreButton.disabled = false;
                loadMoreButton.textContent = 'تلاش دوباره برای نمایش موارد بیشتر';
            }
            return;
        }

        if (event.target.closest('.commission-select')) {
            selectedRateNode = wrapper;
            ['rateTargetType', 'removeRateTargetType'].forEach((id) => { const field = document.getElementById(id); if (field) field.value = wrapper.dataset.type; });
            ['rateTargetId', 'removeRateTargetId'].forEach((id) => { const field = document.getElementById(id); if (field) field.value = wrapper.dataset.id; });
            document.getElementById('rateTargetLabel').textContent = wrapper.dataset.label;
            document.getElementById('rateTargetKind').textContent = typeLabels[wrapper.dataset.type] || 'قلم';
            document.getElementById('rateInherited').textContent = wrapper.dataset.inherited ? `${cleanRate(wrapper.dataset.inherited)}٪` : 'ندارد';
            document.getElementById('rateOwn').textContent = wrapper.dataset.own ? `${cleanRate(wrapper.dataset.own)}٪` : 'ندارد';
            document.getElementById('rateEffective').textContent = wrapper.dataset.effective ? `${cleanRate(wrapper.dataset.effective)}٪` : 'فاقد نرخ';
            document.getElementById('rateSource').textContent = wrapper.dataset.source || 'تعیین نشده';
            const percentage = document.getElementById('ratePercentage');
            if (percentage) percentage.value = wrapper.dataset.own ? cleanRate(wrapper.dataset.own) : '';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('rateEditModal')).show();
        }

        if (event.target.closest('.commission-campaign-target')) {
            const key = `${wrapper.dataset.type}:${wrapper.dataset.id}`;
            addTarget(key, wrapper.dataset.label);
            const button = event.target.closest('.commission-campaign-target');
            const oldText = button.textContent;
            button.textContent = 'به اقلام کمپین افزوده شد ✓';
            setTimeout(() => { button.textContent = oldText; }, 1400);
        }

        const expandButton = event.target.closest('.commission-expand');
        if (!expandButton || expandButton.disabled) return;
        const children = wrapper.querySelector(':scope > .commission-children');
        if (children.dataset.loaded) {
            children.classList.toggle('d-none');
            expandButton.setAttribute('aria-expanded', String(!children.classList.contains('d-none')));
            return;
        }

        children.classList.remove('d-none');
        children.innerHTML = '<div class="commission-node-loading">در حال بارگذاری شاخه…</div>';
        expandButton.setAttribute('aria-expanded', 'true');
        try {
            const url = new URL(app.dataset.treeUrl, window.location.origin);
            url.search = new URLSearchParams({
                type: wrapper.dataset.type,
                id: wrapper.dataset.id,
                q: ''
            });
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('load_failed');
            const payload = await response.json();
            children.innerHTML = payload.items.length
                ? payload.items.map(nodeMarkup).join('') + loadMoreMarkup(payload, wrapper.dataset.type, wrapper.dataset.id)
                : '<div class="commission-empty commission-empty--inline">موردی در این شاخه یافت نشد.</div>';
            children.dataset.loaded = '1';
        } catch (error) {
            children.innerHTML = '<div class="alert alert-danger m-2">بارگذاری شاخه انجام نشد. دوباره تلاش کنید.</div>';
        }
    });

    document.getElementById('rateHistoryButton')?.addEventListener('click', async () => {
        if (!selectedRateNode) return;
        const historyBody = document.getElementById('commissionHistoryBody');
        historyBody.textContent = 'در حال دریافت تاریخچه تغییرات…';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('commissionHistoryModal')).show();
        try {
            const url = new URL(app.dataset.historyUrl, window.location.origin);
            url.search = new URLSearchParams({ target_type: selectedRateNode.dataset.type, target_id: selectedRateNode.dataset.id });
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            const payload = await response.json();
            historyBody.innerHTML = payload.items.length
                ? payload.items.map((item) => `<div class="border-bottom py-2"><strong>${escapeHtml(cleanRate(item.percentage))}٪</strong><div>از ${escapeHtml(item.effective_from)} تا ${escapeHtml(item.effective_to)}</div><small class="text-muted">${escapeHtml(item.created_by || 'سیستم')}</small></div>`).join('')
                : '<div class="commission-empty commission-empty--inline">تاریخچه‌ای ثبت نشده است.</div>';
        } catch (error) {
            historyBody.innerHTML = '<div class="alert alert-danger">دریافت تاریخچه انجام نشد.</div>';
        }
    });

    document.getElementById('commissionExplicitZero')?.addEventListener('click', () => {
        document.getElementById('ratePercentage').value = '0';
        document.getElementById('commissionRateForm').requestSubmit();
    });

    const campaignForm = document.getElementById('commissionCampaignForm');
    const originalCampaignAction = campaignForm?.action;
    document.querySelectorAll('.commission-edit-campaign').forEach((button) => button.addEventListener('click', () => {
        campaignForm.action = button.dataset.action;
        document.getElementById('campaignMethod').value = 'PUT';
        document.getElementById('campaignModalTitle').textContent = 'ویرایش کمپین';
        document.getElementById('campaignName').value = button.dataset.name;
        document.getElementById('campaignBonus').value = cleanRate(button.dataset.bonus);
        document.getElementById('campaignStart').value = button.dataset.start;
        document.getElementById('campaignEnd').value = button.dataset.end;
        document.getElementById('campaignNotes').value = button.dataset.notes || '';
        selectedTargets.clear();
        JSON.parse(button.dataset.targets || '[]').forEach((target) => {
            if (typeof target === 'string') addTarget(target, 'قلم انتخاب‌شده');
            else addTarget(target.key, target.label);
        });
        document.getElementById('campaignSubmit').textContent = 'ذخیره ویرایش';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('campaignModal')).show();
    }));

    document.getElementById('campaignModal')?.addEventListener('hidden.bs.modal', () => {
        if (!campaignForm) return;
        campaignForm.action = originalCampaignAction;
        campaignForm.reset();
        document.getElementById('campaignMethod').value = 'POST';
        document.getElementById('campaignModalTitle').textContent = 'ایجاد کمپین';
        document.getElementById('campaignSubmit').textContent = 'ثبت کمپین';
        selectedTargets.clear();
        renderTargets();
    });

    campaignForm?.addEventListener('submit', (event) => {
        if (selectedTargets.size > 0) return;
        event.preventDefault();
        targetHost.innerHTML = '<p class="text-danger mb-0">حداقل یک قلم کمپین از درخت انتخاب کنید.</p>';
    });

    let searchTimer = null;
    let searchSequence = 0;
    document.getElementById('commissionTreeSearch')?.addEventListener('input', (event) => {
        const term = event.target.value.trim();
        clearTimeout(searchTimer);
        const sequence = ++searchSequence;

        if (term === '') {
            tree.innerHTML = initialTreeMarkup;
            return;
        }
        if (term.length < 2) {
            tree.innerHTML = '<div class="commission-empty commission-empty--inline">برای جستجو حداقل دو حرف وارد کنید.</div>';
            return;
        }

        tree.innerHTML = '<div class="commission-node-loading">در حال جستجو در دسته‌ها، کالاها و تنوع‌ها…</div>';
        searchTimer = setTimeout(async () => {
            try {
                const url = new URL(app.dataset.treeUrl, window.location.origin);
                url.search = new URLSearchParams({ scope: 'all', q: term });
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('search_failed');
                const payload = await response.json();
                if (sequence !== searchSequence) return;
                tree.innerHTML = payload.items.length
                    ? payload.items.map(nodeMarkup).join('') + (payload.is_limited ? '<div class="alert alert-info m-2 commission-search-limited">نتایج جستجو محدود است؛ برای نتیجه دقیق‌تر عبارت جستجو را کامل‌تر کنید.</div>' : '')
                    : '<div class="commission-empty commission-empty--inline">نتیجه‌ای در دسته‌ها، کالاها یا تنوع‌ها پیدا نشد.</div>';
            } catch (error) {
                if (sequence !== searchSequence) return;
                tree.innerHTML = '<div class="alert alert-danger m-2">جستجوی درخت نرخ انجام نشد. دوباره تلاش کنید.</div>';
            }
        }, 300);
    });

    document.querySelectorAll('[data-loading-form]').forEach((form) => form.addEventListener('submit', () => {
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.disabled = true;
            button.dataset.originalText = button.textContent;
            button.textContent = button.dataset.loadingText || 'در حال انجام…';
        });
    }));

    document.querySelectorAll('.commission-money-input').forEach((input) => input.addEventListener('input', () => {
        const english = input.value.replace(/[۰-۹]/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit));
        const digits = english.replace(/\D/g, '');
        input.value = digits ? Number(digits).toLocaleString('en-US') : '';
    }));
    document.querySelectorAll('.commission-signed-money-input').forEach((input) => input.addEventListener('input', () => {
        const negative = input.value.trim().startsWith('-');
        const english = input.value.replace(/[۰-۹]/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit));
        const digits = english.replace(/\D/g, '');
        input.value = digits ? `${negative ? '-' : ''}${Number(digits).toLocaleString('en-US')}` : (negative ? '-' : '');
    }));

    const requestedTab = new URLSearchParams(window.location.search).get('tab') || window.location.hash.replace('#', '');
    const tabMap = { overview: 'overview-tab', rates: 'rates-tab', documents: 'documents-tab' };
    if (tabMap[requestedTab]) bootstrap.Tab.getOrCreateInstance(document.getElementById(tabMap[requestedTab])).show();
    document.querySelectorAll('.commission-tabs [data-bs-toggle="tab"]').forEach((button) => button.addEventListener('shown.bs.tab', () => {
        const tab = Object.keys(tabMap).find((key) => tabMap[key] === button.id) || 'overview';
        const url = new URL(window.location.href);
        if (tab === 'overview') url.searchParams.delete('tab'); else url.searchParams.set('tab', tab);
        history.replaceState({}, '', url);
    }));
});
