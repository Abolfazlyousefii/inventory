(() => {
    'use strict';
    const form = document.getElementById('financeEditForm');
    if (!form) return;
    const math = window.SalesDiscountMath;
    const normalized = value => math.input(value);
    const number = value => Number(normalized(value)) || 0;
    const money = value => Number(value).toLocaleString('en-US') + ' ریال';
    const text = (root, selector, value) => { const el = root.querySelector(selector); if (el) el.textContent = value; };
    const percent = input => input.matches('[data-product-discount-value]')
        ? input.closest('[data-product-discount-group]').querySelector('[data-product-discount-type]').value === 'percent'
        : input.matches('[data-invoice-discount-value]') && form.querySelector('[data-invoice-discount-type]').value === 'percent';
    let dirty = false;
    function validate(input) {
        const value = normalized(input.value);
        const isPercent = percent(input);
        const valid = isPercent ? /^\d+(?:\.\d{0,2})?$/.test(value) && Number(value) <= 100 : /^\d+$/.test(value) && Number.isSafeInteger(Number(value));
        input.setCustomValidity(valid ? '' : (isPercent ? 'درصد باید بین صفر تا ۱۰۰ و حداکثر دو رقم اعشار باشد.' : 'عدد صحیح و مثبت را وارد کنید؛ مبالغ به ریال هستند.'));
        input.setAttribute('aria-invalid', valid ? 'false' : 'true');
        return valid;
    }
    function recalculate() {
        let gross = 0, discount = 0;
        form.querySelectorAll('[data-product-discount-group]').forEach(group => {
            const rows = [...group.querySelectorAll('[data-finance-item-row]')];
            const weights = rows.map(row => number(row.querySelector('.js-qty').value) * number(row.querySelector('.js-price').value));
            const groupGross = weights.reduce((a,b) => a+b, 0);
            const amount = math.amount(groupGross, group.querySelector('[data-product-discount-type]').value, group.querySelector('[data-product-discount-value]').value);
            const shares = math.allocate(weights, amount);
            rows.forEach((row, index) => {
                text(row, '[data-line-gross]', money(weights[index]));
                text(row, '[data-line-discount]', money(shares[index]));
                text(row, '[data-line-net]', money(weights[index] - shares[index]));
            });
            text(group, '[data-group-gross]', money(groupGross));
            text(group, '[data-group-discount-amount]', money(amount));
            text(group, '[data-group-final]', money(groupGross - amount));
            text(group, '[data-group-quantity]', rows.reduce((sum,row) => sum + number(row.querySelector('.js-qty').value),0));
            gross += groupGross; discount += amount;
        });
        const invoiceType = form.querySelector('[data-invoice-discount-type]').value;
        const invoiceValue = form.querySelector('[data-invoice-discount-value]');
        invoiceValue.readOnly = invoiceType === 'none';
        const invoiceDiscount = math.amount(gross-discount, invoiceType, invoiceValue.value);
        const shipping = number(form.querySelector('[data-summary-shipping]').dataset.value);
        const total = gross - discount - invoiceDiscount + shipping;
        form.dataset.grandTotal = String(total);
        text(form, '[data-summary-gross]', money(gross));
        text(form, '[data-summary-product-discount]', money(discount));
        text(form, '[data-summary-invoice-discount]', money(invoiceDiscount));
        text(form, '[data-summary-total-discount]', money(discount + invoiceDiscount));
        text(form, '[data-summary-grand-total]', money(total));
        updateRemaining();
    }
    function updateRemaining() {
        const paid = window.FinanceReviewPayments?.rows().reduce((sum,row) => sum + Number(row.amount),0) || 0;
        text(form, '[data-summary-remaining]', money(Number(form.dataset.grandTotal) - paid));
    }
    // Text fields avoid browser spinners and wheel increments. Format only on blur
    // so editing in the middle of a number never moves the caret unexpectedly.
    document.querySelector('.finance-review').addEventListener('keydown', event => {
        if (event.target.matches('[data-finance-number]') && ['ArrowUp','ArrowDown','Enter'].includes(event.key)) event.preventDefault();
    });
    form.addEventListener('input', event => { dirty = true; if (event.target.matches('[data-finance-number]')) validate(event.target); recalculate(); });
    form.addEventListener('change', event => {
        dirty = true;
        const group = event.target.closest('[data-product-discount-group]');
        if (event.target.matches('[data-product-discount-type],[data-invoice-discount-type]')) {
            const input = group?.querySelector('[data-product-discount-value]') ?? form.querySelector('[data-invoice-discount-value]');
            validate(input);
        }
        recalculate();
    });
    document.querySelector('.finance-review').addEventListener('focusout', event => {
        const input = event.target;
        if (!input.matches('[data-finance-number]') || !validate(input)) return;
        input.value = percent(input) ? normalized(input.value) : Number(normalized(input.value)).toLocaleString('en-US');
    });
    form.addEventListener('submit', event => {
        const fields = [...form.querySelectorAll('[data-finance-number]')];
        const invalid = fields.find(input => !validate(input));
        if (invalid) {
            event.preventDefault(); event.stopImmediatePropagation();
            invalid.closest('details')?.setAttribute('open',''); invalid.focus(); invalid.reportValidity();
        }
    });
    form.addEventListener('invalid', event => {
        event.target.closest('details')?.setAttribute('open','');
    }, true);
    document.querySelector('[data-collaborative-link]')?.addEventListener('click', event => {
        if (dirty || (window.FinanceReviewPayments?.rows().length ?? 0)>0) {
            event.preventDefault();
            const notice = document.getElementById('financeInputError'); notice.hidden = false;
            notice.textContent = 'ابتدا تغییرات را با «فقط ذخیره تغییرات» ذخیره کنید. برای افزودن یا حذف کالا، پرداخت‌های آماده‌شده را فعلاً حذف کنید تا اطلاعات از دست نرود.';
            notice.scrollIntoView({block:'center'});
        }
    });
    document.addEventListener('finance:payments-changed', updateRemaining);
    recalculate();
})();
