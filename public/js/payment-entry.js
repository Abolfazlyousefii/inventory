(() => {
    const init = () => document.querySelectorAll('[data-payment-modal]').forEach(modal => {
        if (modal.dataset.paymentReady) return;
        modal.dataset.paymentReady = 'true';
        const form = modal.querySelector('[data-payment-entry]');
        const method = form.querySelector('[data-payment-method]');
        const toggle = () => {
            const cheque = method.value === 'cheque';
            for (const [selector, show] of [['[data-payment-cheque]', cheque], ['[data-payment-cash]', !cheque]]) {
                form.querySelectorAll(selector).forEach(block => {
                    block.classList.toggle('d-none', !show);
                    block.querySelectorAll('input,select').forEach(input => { input.disabled = !show; });
                });
            }
            form.querySelector('[name="payment_date"]').required = !cheque;
            ['due_date', 'received_date', 'cheque_number'].forEach(name => { form.elements[name].required = cheque; });
        };
        method.addEventListener('change', toggle); toggle();
        const amount = form.elements.amount;
        amount.addEventListener('input', () => {
            if (amount.hasAttribute('data-rial')) return;
            const digits = amount.value.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/\D/g, '');
            amount.value = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        });
        const invoice = form.querySelector('[data-payment-invoice]');
        if (invoice) {
            const update = () => {
                const remaining = invoice.selectedOptions[0]?.dataset.remaining;
                form.querySelector('[data-payment-remaining]').textContent = remaining == null ? 'ابتدا فاکتور را انتخاب کنید' : Number(remaining).toLocaleString('fa-IR') + ' ریال';
            };
            invoice.addEventListener('change', update); update();
        }
        form.addEventListener('submit', () => {
            const button = form.querySelector('[type="submit"]');
            button.disabled = true; button.textContent = 'در حال ثبت…';
        });
        if (modal.hasAttribute('data-payment-reopen') && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modal).show();
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
