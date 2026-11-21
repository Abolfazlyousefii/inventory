export function discountTotal(unit, quantity, price) {
    if (![unit, quantity, price].every(Number.isSafeInteger) || unit < 0 || quantity < 0 || price < 0 || unit > price || !Number.isSafeInteger(unit * quantity)) {
        throw new RangeError('تخفیف واحد باید عدد صحیح، مثبت یا صفر و حداکثر برابر قیمت واحد باشد.');
    }
    return unit * quantity;
}
export function historicalTotal(total, oldQuantity, quantity, price) {
    return Math.min(Math.floor(total / oldQuantity) * quantity + Math.round((total % oldQuantity) * quantity / oldQuantity), quantity * price);
}
export function initialUnit(total, quantity) {
    return {unit: quantity > 0 ? Math.floor(total / quantity) : 0, approximate: quantity > 0 && total % quantity !== 0};
}
if (typeof document !== 'undefined') {
    const body = document.getElementById('invoiceItemsBody');
    const states = new WeakMap();
    const number = value => Number(String(value ?? '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/[,٬\s]/g, '') || 0);
    const format = value => Number(value).toLocaleString('fa-IR');
    function init(row) {
        if (states.has(row)) return states.get(row);
        const total = row.querySelector('[name$="[line_discount_amount]"]');
        const qty = row.querySelector('[name$="[quantity]"]');
        const price = row.querySelector('[name$="[price]"]');
        if (!total || !qty || !price || row.dataset.removedHistory === '1') return null;
        const originalTotal = number(total.value), originalQty = number(qty.value);
        const initial = initialUnit(originalTotal, originalQty);
        const unit = document.createElement('input');
        unit.type = 'text'; unit.inputMode = 'numeric'; unit.autocomplete = 'off';
        unit.className = 'form-control form-control-sm js-unit-discount';
        unit.setAttribute('aria-label','تخفیف واحد (ریال)');unit.value = format(initial.unit);
        unit.readOnly = total.readOnly;unit.disabled = total.disabled;
        const hint = document.createElement('small');hint.className = 'd-block text-muted mt-1';
        total.type = 'hidden';total.insertAdjacentElement('afterend',unit);unit.insertAdjacentElement('afterend',hint);
        const state = {total, qty, price, unit, hint, originalTotal, originalQty, approximate:initial.approximate, edited:false};
        states.set(row,state);sync(row);return state;
    }
    function sync(row) {
        const s = states.get(row);if (!s) return;
        s.unit.setCustomValidity('');
        if (!s.unit.readOnly && !s.unit.disabled) {
            try {
                const q=number(s.qty.value), p=number(s.price.value);
                let value=number(s.total.value);
                if (s.edited || !s.approximate) value=discountTotal(number(s.unit.value),q,p);
                else if(q!==s.originalQty) value=s.originalQty>0?historicalTotal(s.originalTotal,s.originalQty,q,p):0;
                if(!Number.isSafeInteger(value)||value<0||value>q*p)throw new RangeError('تخفیف از مبلغ کالا بیشتر است.');
                s.total.value=String(value);
            } catch(error) {s.unit.setCustomValidity(error.message);}
        }
        s.hint.textContent=`تخفیف کل ردیف: ${format(number(s.total.value))} ریال`+(s.approximate&&!s.edited?'؛ واحد تقریبی، مبلغ قبلی دقیق حفظ می‌شود.':'');
    }
    function syncAll(){body?.querySelectorAll('tr').forEach(row=>{init(row);sync(row)});}
    window.CollectionUnitDiscount={syncAll};
    body?.addEventListener('input',event=>{
        const row=event.target.closest('tr'),s=row&&init(row);if(!s)return;
        if(event.target===s.unit){s.edited=true;sync(row);s.total.dispatchEvent(new Event('input',{bubbles:true}));}
        else sync(row);
    },true);
    body?.addEventListener('focusout',event=>{if(event.target.classList.contains('js-unit-discount')&&!event.target.validationMessage)event.target.value=format(number(event.target.value));});
    body?.closest('form')?.addEventListener('submit',event=>{syncAll();const invalid=Array.from(body.querySelectorAll('.js-unit-discount')).find(el=>!el.checkValidity());if(invalid){event.preventDefault();event.stopImmediatePropagation();invalid.reportValidity();}},true);
    if(body)new MutationObserver(syncAll).observe(body,{childList:true});
    syncAll();
}
