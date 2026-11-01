(function (root) {
    'use strict';
    const raw = value => String(value ?? '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
        .replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/−/g, '-').replace(/٫/g, '.').replace(/[,٬\s\u200e\u200f]/g, '');
    const number = value => Number(typeof value === 'string' ? raw(value) : value);
    function format(value, kind = 'amount') {
        const normalized = raw(value);
        if (kind === 'percent' || !/^-?\d+$/.test(normalized)) return normalized;
        // String grouping preserves every rial, including values above JS's integer precision.
        return normalized.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
    function caret(before, position, after) {
        const count = raw(before.slice(0, position)).length;
        if (!count) return 0;
        let seen = 0;
        for (let i = 0; i < after.length; i++) {
            if (after[i] !== ',') seen++;
            if (seen === count) return i + 1;
        }
        return after.length;
    }
    const selector = 'input[data-rial],input.money';
    function kind(input) {
        const source = input.dataset.rialKindSource;
        if (source) return document.querySelector(source)?.value.includes('percent') ? 'percent' : 'amount';
        const discount = input.closest('[data-rial-discount]');
        if (discount) return discount.querySelector('select')?.value === 'percent' ? 'percent' : 'amount';
        if (input.hasAttribute('data-product-discount-value')) return input.closest('[data-product-discount-group]')?.querySelector('[data-product-discount-type]')?.value === 'percent' ? 'percent' : 'amount';
        if (input.hasAttribute('data-invoice-discount-value')) return input.form?.querySelector('[data-invoice-discount-type]')?.value === 'percent' ? 'percent' : 'amount';
        return 'amount';
    }
    const ownedErrors = new WeakSet();
    function enhance(input, preserveCaret = false) {
        if (!input?.matches?.(selector) || ['hidden','checkbox','radio','file'].includes(input.type)) return;
        const before = input.value, position = input.selectionStart;
        if (input.type === 'number') input.type = 'text';
        const mode = kind(input);
        input.inputMode = mode === 'percent' ? 'decimal' : 'numeric';
        input.style.direction = 'ltr'; input.style.textAlign = 'left';
        const after = format(before, mode);
        if (before !== after) input.value = after;
        if (preserveCaret && position !== null && document.activeElement === input) {
            const next = caret(before, position, after); input.setSelectionRange(next, next);
        }
        const value = raw(after);
        let error = '';
        if (value && mode !== 'percent') {
            if (!/^-?\d+$/.test(value)) error = 'مبلغ را به ریال و بدون اعشار وارد کنید.';
            else if (input.min !== '' && number(value) < number(input.min)) error = 'مبلغ کمتر از حد مجاز است.';
            else if (input.max !== '' && number(value) > number(input.max)) error = 'مبلغ بیشتر از حد مجاز است.';
        }
        if (error) { input.setCustomValidity(error); ownedErrors.add(input); }
        else if (ownedErrors.has(input)) { input.setCustomValidity(''); ownedErrors.delete(input); }
    }
    let installed = false;
    function install() {
        if (installed || typeof document === 'undefined') return;
        installed = true;
        const scan = scope => {
            if (scope.matches?.(selector)) enhance(scope);
            scope.querySelectorAll?.(selector).forEach(input => enhance(input));
        };
        scan(document);
        document.addEventListener('input', event => enhance(event.target, true), true);
        document.addEventListener('focusin', event => enhance(event.target), true);
        document.addEventListener('focusout', event => enhance(event.target), true);
        document.addEventListener('keydown', event => {
            if (event.target.matches?.(selector) && ['ArrowUp','ArrowDown'].includes(event.key)) event.preventDefault();
        }, true);
        document.addEventListener('change', event => {
            if (event.target.matches?.('select')) scan(event.target.form || document);
        });
        document.addEventListener('formdata', event => {
            // Native POST/GET and FormData-based AJAX all send canonical numeric strings.
            for (const input of [...event.target.elements].filter(field => field.matches?.(selector))) {
                if (input.name && !input.disabled && input.type !== 'hidden' && event.formData.has(input.name)) event.formData.set(input.name, raw(input.value));
            }
        });
        new MutationObserver(records => {
            for (const record of records) for (const node of record.addedNodes) {
                if (node.nodeType === 1) scan(node);
            }
        }).observe(document.documentElement, {childList:true, subtree:true});
    }
    const api = {raw, number, format, caret, enhance, install};
    root.RialInput = api;
    if (typeof module !== 'undefined') module.exports = api;
    if (typeof document !== 'undefined') {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
    }
})(typeof window === 'undefined' ? globalThis : window);
