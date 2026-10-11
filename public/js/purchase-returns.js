(() => {
    'use strict';
    const latin = value => String(value).replace(/[۰-۹٠-٩]/g, c => '۰۱۲۳۴۵۶۷۸۹'.includes(c) ? '۰۱۲۳۴۵۶۷۸۹'.indexOf(c) : '٠١٢٣٤٥٦٧٨٩'.indexOf(c));
    const format = value => new Intl.NumberFormat('fa-IR').format(value);
    const supplier = document.querySelector('[data-pr-supplier]');
    supplier?.addEventListener('change', () => { document.querySelector('[data-pr-purchase]').value = ''; supplier.form.submit(); });
    const purchase = document.querySelector('[data-pr-purchase]');
    purchase?.addEventListener('change', () => purchase.form.submit());
    const entry = document.querySelector('[data-pr-entry]');
    const recalculate = () => {
        let total = 0;
        const quantities = {};
        entry?.querySelectorAll('[data-pr-row]').forEach(row => {
            const raw = latin(row.querySelector('[data-pr-quantity]').value.trim());
            quantities[row.dataset.stockKey] = (quantities[row.dataset.stockKey] || 0) + (/^\d+$/.test(raw) ? Number(raw) : 0);
        });
        entry?.querySelectorAll('[data-pr-row]').forEach(row => {
            const input = row.querySelector('[data-pr-quantity]');
            const raw = latin(input.value.trim());
            const quantity = /^\d+$/.test(raw) ? Number(raw) : 0;
            input.setCustomValidity(!/^\d+$/.test(raw) ? 'تعداد صحیح وارد کنید.' : quantity > Number(input.dataset.limit) ? 'تعداد از سقف قابل برگشت بیشتر است.' : quantities[row.dataset.stockKey] > Number(row.dataset.capacity) ? 'جمع برگشت این تنوع از موجودی آزاد بیشتر است.' : '');
            const amount = quantity * Number(row.dataset.price);
            row.querySelector('[data-pr-line-total]').textContent = format(amount);
            total += amount;
        });
        if (entry) entry.querySelector('[data-pr-total]').textContent = format(total) + ' ریال';
    };
    entry?.addEventListener('input', recalculate); recalculate();
    entry?.addEventListener('submit', event => {
        entry.querySelectorAll('[data-pr-quantity]').forEach(input => { input.value = latin(input.value.trim()); });
        recalculate();
        if (!entry.reportValidity()) { event.preventDefault(); return; }
        entry.querySelector('button[type="submit"]').disabled = true;
    });
    document.querySelector('[data-pr-delete]')?.addEventListener('submit', event => {
        if (!window.confirm('سند حذف شود و کالاها به موجودی آزاد مرکزی برگردند؟')) event.preventDefault();
        else event.currentTarget.querySelector('button').disabled = true;
    });
})();
