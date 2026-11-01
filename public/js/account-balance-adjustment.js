(() => {
    const init = () => {
        const modal = document.getElementById('balanceAdjustmentModal');
        if (!modal) return;
        const form = modal.querySelector('form'), amount = form.elements.target_amount;
        const toggle = () => {
            const settled = form.elements.balance_type.value === 'settled';
            amount.readOnly = settled;
            if (settled) amount.value = '0';
        };
        form.elements.balance_type.addEventListener('change', toggle); toggle();
        amount.addEventListener('input', () => {
            if (amount.hasAttribute('data-rial')) return;
            const digits = amount.value.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/\D/g, '');
            amount.value = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        });
        form.addEventListener('submit', () => {const b=form.querySelector('[type="submit"]');b.disabled=true;b.textContent='در حال ثبت…';});
        if (modal.hasAttribute('data-balance-reopen') && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modal).show();
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
