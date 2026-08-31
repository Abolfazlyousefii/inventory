(() => {
    'use strict';

    const clean = (value = '') => String(value)
        .replace(/\u200c/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    const toLatin = (value = '') => {
        const fa = '۰۱۲۳۴۵۶۷۸۹';
        const ar = '٠١٢٣٤٥٦٧٨٩';

        return String(value)
            .replace(/[۰-۹]/g, (digit) => fa.indexOf(digit))
            .replace(/[٠-٩]/g, (digit) => ar.indexOf(digit));
    };

    const amount = (value = '') => {
        const normalized = toLatin(clean(value))
            .replace(/[,\u066c٬،\s]/g, '')
            .replace(/[^\d+\-.]/g, '');

        const parsed = Number.parseFloat(normalized);
        return Number.isFinite(parsed) ? parsed : 0;
    };

    const findSellerTable = (app) => {
        return [...app.querySelectorAll('#commission-overview .commission-table')]
            .find((table) => {
                const headers = [...table.querySelectorAll('thead th')]
                    .map((th) => clean(th.textContent));

                return headers.includes('فروشنده')
                    && headers.some((header) => header.includes('محاسبه‌شده'))
                    && headers.some((header) => header.includes('وضعیت سند'));
            }) || null;
    };

    const addFinanceNote = (app) => {
        const overview = app.querySelector('#commission-overview');
        if (!overview || overview.querySelector('.commission-finance-note')) return;

        const grid = [...overview.querySelectorAll('.commission-kpi-grid')]
            .find((candidate) => {
                const text = clean(candidate.textContent);
                return text.includes('پورسانت محاسبه‌شده')
                    && text.includes('تأییدشده مالی');
            });

        if (!grid) return;

        const note = document.createElement('div');
        note.className = 'commission-finance-note';
        note.innerHTML = `
            <span><strong>محاسبه‌شده:</strong> مبلغ فعلی موتور پورسانت</span>
            <span aria-hidden="true">•</span>
            <span><strong>تأیید مالی:</strong> مبلغ بررسی‌شده در سند مالی</span>
        `;
        grid.insertAdjacentElement('afterend', note);
    };

    const installSellerFilter = (app) => {
        const table = findSellerTable(app);
        if (!table) return;

        const tbody = table.tBodies[0];
        const wrapper = table.closest('.table-responsive');
        if (!tbody || !wrapper || wrapper.dataset.p43Filter === '1') return;

        const headers = [...table.querySelectorAll('thead th')]
            .map((th) => clean(th.textContent));

        const indexOf = (...needles) => headers.findIndex(
            (header) => needles.some((needle) => header.includes(needle))
        );

        const map = {
            calculated: indexOf('محاسبه‌شده'),
            approved: indexOf('تأییدشده'),
            pending: indexOf('در انتظار بررسی'),
            correction: indexOf('برگشتی', 'اصلاح'),
            status: indexOf('وضعیت سند'),
        };

        const rows = [...tbody.rows].filter((row) => row.cells.length > 1);
        if (!rows.length) return;

        const states = new Map();

        rows.forEach((row) => {
            const cells = [...row.cells];
            const valueAt = (index) => index >= 0 && cells[index]
                ? clean(cells[index].textContent)
                : '';

            const calculated = amount(valueAt(map.calculated));
            const approved = amount(valueAt(map.approved));
            const pending = amount(valueAt(map.pending));
            const correction = amount(valueAt(map.correction));
            const status = valueAt(map.status);

            const hasDocument = status !== '' && !status.includes('فاقد سند');
            const attention = pending > 0 || /پیش.?نویس|در حال بررسی|نیازمند|معوق/.test(status);
            const active =
                Math.abs(calculated) > 0 ||
                Math.abs(approved) > 0 ||
                Math.abs(correction) > 0 ||
                pending > 0 ||
                hasDocument;

            states.set(row, { active, attention, inactive: !active });
        });

        const count = (key) => rows.filter((row) => states.get(row)[key]).length;
        const counts = {
            all: rows.length,
            active: count('active'),
            attention: count('attention'),
            inactive: count('inactive'),
        };

        const toolbar = document.createElement('div');
        toolbar.className = 'commission-seller-filter';
        toolbar.innerHTML = `
            <span class="commission-seller-filter__label">نمایش:</span>
            <div class="commission-seller-filter__options" role="group" aria-label="فیلتر فروشندگان">
                ${[
                    ['active', 'دارای فعالیت', counts.active],
                    ['attention', 'نیازمند بررسی', counts.attention],
                    ['inactive', 'بدون فعالیت', counts.inactive],
                    ['all', 'همه', counts.all],
                ].map(([key, label, countValue]) => `
                    <button type="button"
                            class="commission-seller-filter__button"
                            data-p43-filter="${key}"
                            aria-pressed="false">
                        <span>${label}</span>
                        <span class="commission-seller-filter__count">${countValue.toLocaleString('fa-IR')}</span>
                    </button>
                `).join('')}
            </div>
        `;

        wrapper.insertAdjacentElement('beforebegin', toolbar);

        const apply = (filter) => {
            rows.forEach((row) => {
                const state = states.get(row);
                const visible =
                    filter === 'all'
                    || (filter === 'active' && state.active)
                    || (filter === 'attention' && state.attention)
                    || (filter === 'inactive' && state.inactive);

                row.hidden = !visible;
            });

            toolbar.querySelectorAll('[data-p43-filter]').forEach((button) => {
                button.setAttribute(
                    'aria-pressed',
                    button.dataset.p43Filter === filter ? 'true' : 'false'
                );
            });
        };

        toolbar.addEventListener('click', (event) => {
            const button = event.target.closest('[data-p43-filter]');
            if (!button) return;
            apply(button.dataset.p43Filter);
        });

        apply(counts.active > 0 && counts.active < counts.all ? 'active' : 'all');
        wrapper.dataset.p43Filter = '1';
    };

    const init = () => {
        const app = document.getElementById('commissionApp');
        if (!app || app.dataset.p43Ready === '1') return;

        app.dataset.p43Ready = '1';
        addFinanceNote(app);
        installSellerFilter(app);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
