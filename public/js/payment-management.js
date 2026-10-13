(() => {
    const form = document.querySelector('[data-payment-edit]');
    if (!form) return;
    const method = form.querySelector('[data-payment-method]');
    const toggle = () => {
        const cheque = method.value === 'cheque';
        for (const [selector, show] of [['[data-payment-cash]', !cheque], ['[data-payment-cheque]', cheque]]) {
            const section = form.querySelector(selector);
            section.hidden = !show;
            section.querySelectorAll('input,select').forEach(input => { input.disabled = !show; });
        }
    };
    method.addEventListener('change', toggle); toggle();
    const amount = form.querySelector('[data-payment-amount]');
    const digits = value => value.replace(/[۰-۹]/g, c => '۰۱۲۳۴۵۶۷۸۹'.indexOf(c)).replace(/[٠-٩]/g, c => '٠١٢٣٤٥٦٧٨٩'.indexOf(c)).replace(/[,٬\s]/g, '');
    amount.addEventListener('input', () => { const value = digits(amount.value); if (/^\d*$/.test(value)) amount.value = value.replace(/\B(?=(\d{3})+(?!\d))/g, ','); });
    form.addEventListener('submit', () => { amount.value = digits(amount.value); form.querySelector('button[type="submit"]').disabled = true; });
    const deletion = document.querySelector('[data-payment-delete]');
    deletion?.addEventListener('submit', event => {
        if (!window.confirm('این پرداخت حذف شود؟ مانده حساب مشتری تغییر می‌کند.')) event.preventDefault();
        else deletion.querySelector('button[type="submit"]').disabled = true;
    });
})();
