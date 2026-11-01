(() => {
    'use strict';
    const form = document.getElementById('financeEditForm');
    if (!form) return;
    const read = name => form.elements.namedItem(name)?.value ?? '';
    const value = (root, selector) => root.querySelector(selector)?.value ?? '';
    const number = text => Number(String(text).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/[^0-9]/g, ''));
    const rows = () => [...form.querySelectorAll('[data-finance-item-row]')];
    const finalize = form.querySelector('button[value="save_and_finalize"]');
    const syncFinalize = () => {
        if (finalize) finalize.disabled = form.dataset.submitting === '1' || rows().some(row => number(value(row, '.js-price')) <= 0 || number(value(row, '.js-qty')) <= 0);
    };
    form.addEventListener('input', syncFinalize);
    form.addEventListener('change', syncFinalize);
    syncFinalize();
    form.addEventListener('submit', event => {
        if (form.dataset.submitting === '1') { event.preventDefault(); return; }
        const button = event.submitter;
        const intent = button?.value === 'save_and_finalize' ? 'save_and_finalize' : 'save';
        if (intent === 'save_and_finalize' && finalize?.disabled) { event.preventDefault(); return; }
        try {
            if (document.getElementById('financePaymentComposer') && !window.FinanceReviewPayments) {
                throw new Error('Payment editor did not initialize.');
            }
            const items = rows().map(row => ({id: Number(row.dataset.itemId), quantity: value(row, '.js-qty'), price: value(row, '.js-price')}));
            const groups = [...form.querySelectorAll('[data-product-discount-group]')].map(group => ({product_id: Number(group.dataset.productId), type: value(group, '[data-product-discount-type]'), value: value(group, '[data-product-discount-value]')}));
            const payload = {finance_version: read('finance_version'), items, product_discounts: groups, invoice_discount_type: read('invoice_discount_type'), invoice_discount_value: read('invoice_discount_value'), edit_reason: read('edit_reason'), intent, payments: window.FinanceReviewPayments?.rows() ?? []};
            const hidden = (name, val) => { let field = form.querySelector(`input[name="${name}"]`); if (!field) { field = document.createElement('input'); field.type = 'hidden'; field.name = name; form.appendChild(field); } field.value = val; };
            hidden('finance_payload', JSON.stringify(payload));
            hidden('finance_payload_item_count', String(items.length));
            // Keep PHP's input count constant; the complete editor state travels in one value.
            [...form.elements].forEach(field => { if (field.name && !['_token', '_method', 'finance_payload', 'finance_payload_item_count'].includes(field.name)) field.disabled = true; });
            if (button?.hasAttribute('formaction')) form.action = button.formAction;
            const method = form.querySelector('[name="_method"]');
            if (method) method.value = intent === 'save_and_finalize' ? 'POST' : 'PUT';
            form.dataset.submitting = '1';
            form.querySelectorAll('button[type="submit"],button[name="intent"]').forEach(b => { b.disabled = true; });
            if (button) button.textContent = 'در حال ذخیره…';
        } catch (error) {
            event.preventDefault();
            window.alert('آماده‌سازی اطلاعات فرم انجام نشد؛ صفحه را تازه کنید و دوباره تلاش کنید.');
        }
    });
})();
