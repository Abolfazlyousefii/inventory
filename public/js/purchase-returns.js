(() => {
    'use strict';

    // UI-only preview: the PurchaseReturnService remains authoritative for all amounts.
    const netRefund = (netTotal, purchasedQuantity, previousQuantity, previousAmount, quantity) => {
        const [net, bought, previous, refunded, qty] = [netTotal, purchasedQuantity, previousQuantity, previousAmount, quantity].map(BigInt);
        if (bought <= 0n || qty < 0n || previous < 0n || previous + qty > bought || refunded > net || refunded < 0n) {
            throw new RangeError('Inconsistent purchase return pricing');
        }
        if (qty === 0n) return 0n;
        const amount = net * (previous + qty) / bought - refunded;
        return amount > 0n ? amount : 0n;
    };
    const latin = value => String(value).replace(/[۰-۹٠-٩]/g, char =>
        '۰۱۲۳۴۵۶۷۸۹'.includes(char) ? '۰۱۲۳۴۵۶۷۸۹'.indexOf(char) : '٠١٢٣٤٥٦٧٨٩'.indexOf(char)
    );
    const format = value => new Intl.NumberFormat('fa-IR').format(value);
    const quantityError = (raw, limit, totalForVariant, freeCapacity) => {
        const text = latin(String(raw).trim());
        const quantity = /^\d+$/.test(text) ? Number(text) : NaN;
        if (!Number.isSafeInteger(quantity) || quantity < 0) return 'تعداد باید عدد صحیح و نامنفی باشد.';
        if (quantity > Number(limit)) return 'تعداد از حداکثر قابل برگشت بیشتر است.';
        if (totalForVariant > Number(freeCapacity)) return 'جمع تعداد این تنوع از موجودی آزاد انبار مبدأ بیشتر است.';
        return '';
    };
    globalThis.PurchaseReturnPricing = {netRefund, latin, quantityError};
    if (typeof document === 'undefined') return;

    // Reuse the Select2 library already loaded by the application layout.
    const supplier = document.querySelector('[data-pr-supplier]');
    const purchase = document.querySelector('[data-pr-purchase]');
    const warehouse = document.querySelector('[data-pr-warehouse]');
    const $ = globalThis.jQuery;
    const select2Available = !!($?.fn?.select2);
    if (supplier && select2Available) $(supplier).select2({width: '100%', dir: 'rtl'});
    if (purchase && select2Available) {
        $(purchase).select2({
            width: '100%', dir: 'rtl', placeholder: 'جست‌وجوی شماره سند خرید', allowClear: true,
            ajax: {
                url: purchase.dataset.prLookupUrl, dataType: 'json', delay: 250, cache: true,
                data: params => ({supplier_id: supplier.value, q: params.term || '', page: params.page || 1}),
                processResults: response => response,
            },
        });
    }
    const onChange = (element, callback) => {
        if (!element) return;
        if (select2Available && (element === supplier || element === purchase)) $(element).on('change', callback);
        else element.addEventListener('change', callback);
    };
    onChange(supplier, () => {
        if (purchase) {
            purchase.value = '';
            if (select2Available) $(purchase).trigger('change.select2');
        }
        supplier.form.submit();
    });
    onChange(purchase, () => { if (purchase.value) purchase.form.submit(); });
    warehouse?.addEventListener('change', () => warehouse.form.submit());

    const entry = document.querySelector('[data-pr-entry]');
    if (entry) {
        const rows = [...entry.querySelectorAll('[data-pr-row]')];
        const search = document.querySelector('[data-pr-item-search]');
        const onlySelected = document.querySelector('[data-pr-only-selected]');
        const save = entry.querySelector('[data-pr-save]');
        const review = entry.querySelector('[data-pr-review-dialog]');
        let confirmed = false;
        let submitting = false;
        let summary = {quantity: 0, count: 0, amount: 0n};

        const filterRows = () => {
            const term = (search?.value || '').trim().toLocaleLowerCase('fa');
            let visible = 0;
            rows.forEach(row => {
                const quantity = Number(latin(row.querySelector('[data-pr-quantity]').value));
                const matches = row.cells[0].textContent.toLocaleLowerCase('fa').includes(term)
                    && (!onlySelected?.checked || quantity > 0);
                row.hidden = !matches;
                if (matches) visible++;
            });
            const empty = entry.querySelector('[data-pr-no-results]');
            if (empty) empty.hidden = visible !== 0;
        };
        const recalculate = () => {
            const quantities = new Map();
            rows.forEach(row => {
                const raw = latin(row.querySelector('[data-pr-quantity]').value.trim());
                if (/^\d+$/.test(raw)) quantities.set(row.dataset.stockKey, (quantities.get(row.dataset.stockKey) || 0) + Number(raw));
            });
            let amount = 0n, quantity = 0, count = 0;
            rows.forEach(row => {
                const input = row.querySelector('[data-pr-quantity]');
                const raw = latin(input.value.trim());
                const qty = /^\d+$/.test(raw) ? Number(raw) : NaN;
                let error = quantityError(raw, input.dataset.limit,
                    quantities.get(row.dataset.stockKey) || 0, row.dataset.capacity);
                let lineAmount = 0n;
                try {
                    if (!error) lineAmount = netRefund(row.dataset.netTotal, row.dataset.purchasedQuantity,
                        row.dataset.previousQuantity, row.dataset.previousAmount, qty);
                } catch {
                    error = 'تعداد یا مبلغ برگشت‌های قبلی نیاز به بررسی دارد.';
                }
                input.setCustomValidity(error);
                const message = row.querySelector('[data-pr-error]');
                if (message) message.textContent = error;
                row.querySelector('[data-pr-line-total]').textContent = format(lineAmount) + ' ریال';
                if (!error && qty > 0) {quantity += qty; count++; amount += lineAmount;}
            });
            summary = {quantity, count, amount};
            entry.querySelector('[data-pr-total]').textContent = format(amount) + ' ریال';
            entry.querySelector('[data-pr-total-quantity]').textContent = format(quantity);
            entry.querySelector('[data-pr-selected-count]').textContent = format(count);
            filterRows();
        };
        entry.addEventListener('input', event => {
            if (event.target.matches('[data-pr-quantity]')) recalculate();
        });
        search?.addEventListener('input', filterRows);
        onlySelected?.addEventListener('change', filterRows);
        entry.querySelector('[data-pr-zero-all]')?.addEventListener('click', () => {
            if (!rows.some(row => Number(latin(row.querySelector('[data-pr-quantity]').value)) > 0)) return;
            if (!globalThis.confirm('تعداد برگشت همه اقلام صفر شود؟ تغییرات هنوز ثبت نخواهند شد.')) return;
            rows.forEach(row => {row.querySelector('[data-pr-quantity]').value = '0';});
            recalculate();
        });
        entry.addEventListener('submit', event => {
            rows.forEach(row => {
                const input = row.querySelector('[data-pr-quantity]');
                input.value = latin(input.value.trim());
            });
            recalculate();
            if (!entry.reportValidity() || summary.count === 0) {
                event.preventDefault();
                confirmed = false;
                if (summary.count === 0 && entry.reportValidity()) {
                    globalThis.alert('حداقل یک کالا با تعداد برگشت بیشتر از صفر انتخاب کنید.');
                }
                return;
            }
            if (submitting) {event.preventDefault(); return;}
            if (!confirmed && review?.showModal) {
                event.preventDefault();
                review.querySelector('[data-pr-review-quantity]').textContent = format(summary.quantity);
                review.querySelector('[data-pr-review-count]').textContent = format(summary.count);
                review.querySelector('[data-pr-review-total]').textContent = format(summary.amount) + ' ریال';
                review.showModal();
                return;
            }
            submitting = true;
            save.disabled = true;
        });
        review?.querySelector('[data-pr-review-close]')?.addEventListener('click', () => review.close());
        review?.querySelector('[data-pr-review-confirm]')?.addEventListener('click', () => {
            confirmed = true;
            review.close();
            entry.requestSubmit(save);
        });
        review?.addEventListener('close', () => {if (!submitting) confirmed = false;});
        recalculate();
    }

    // Print the existing document template without navigating away from the archive/details.
    const printTriggers = document.querySelectorAll('[data-pr-print-url]');
    let printing = false;
    printTriggers.forEach(trigger => trigger.addEventListener('click', () => {
        if (printing) return;
        printing = true;
        const frame = document.createElement('iframe');
        frame.title = 'چاپ برگشت از خرید';
        frame.setAttribute('aria-hidden', 'true');
        frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:210mm;height:297mm;border:0';
        const cleanup = () => {frame.remove(); printing = false;};
        const fail = () => {
            cleanup();
            globalThis.alert('بارگذاری سند برای چاپ ممکن نشد. دوباره تلاش کنید.');
        };
        frame.addEventListener('load', async () => {
            try {
                const printable = frame.contentWindow;
                if (!printable?.document?.querySelector('[data-pr-print-document]')) return fail();
                // Wait for the Persian typeface before opening the browser print/PDF dialog.
                if (printable.document.fonts?.ready) await printable.document.fonts.ready;
                printable.addEventListener('afterprint', cleanup, {once: true});
                printable.focus();
                printable.print();
            } catch {
                fail();
            }
        }, {once: true});
        frame.addEventListener('error', fail, {once: true});
        frame.src = trigger.dataset.prPrintUrl;
        document.body.appendChild(frame);
    }));

    // The same dialog and destroy endpoint are used on the archive and the detail page.
    const cancelDialog = document.querySelector('[data-pr-cancel-dialog]');
    const cancelForm = cancelDialog?.querySelector('[data-pr-cancel-form]');
    if (cancelDialog && cancelForm) {
        const reason = cancelForm.querySelector('[name="reason"]');
        const submit = cancelForm.querySelector('[data-pr-cancel-submit]');
        let cancelling = false;
        document.querySelectorAll('[data-pr-cancel-trigger]').forEach(trigger => {
            trigger.addEventListener('click', () => {
                const data = trigger.dataset;
                cancelForm.action = data.action;
                cancelForm.querySelector('[data-pr-cancel-version]').value = data.version;
                ['number', 'supplier', 'purchase', 'warehouse', 'quantity'].forEach(key => {
                    cancelDialog.querySelector(`[data-pr-cancel-${key}]`).textContent = key === 'number' ? data[key].replace(/[0-9]/g, digit => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]) : (key === 'purchase' || key === 'quantity' ? format(data[key]) : data[key]);
                });
                cancelDialog.querySelector('[data-pr-cancel-items-count]').textContent = format(data.itemsCount);
                cancelDialog.querySelector('[data-pr-cancel-warehouse-warning]').textContent = data.warehouse;
                reason.value = '';
                reason.setCustomValidity('');
                submit.disabled = false;
                cancelling = false;
                cancelDialog.showModal();
                reason.focus();
            });
        });
        cancelDialog.querySelector('[data-pr-cancel-close]').addEventListener('click', () => cancelDialog.close());
        reason.addEventListener('input', () => reason.setCustomValidity(''));
        cancelForm.addEventListener('submit', event => {
            reason.setCustomValidity(reason.value.trim() ? '' : 'دلیل ابطال الزامی است.');
            if (!cancelForm.reportValidity() || cancelling) {event.preventDefault(); return;}
            cancelling = true;
            submit.disabled = true;
        });
    }
})();
