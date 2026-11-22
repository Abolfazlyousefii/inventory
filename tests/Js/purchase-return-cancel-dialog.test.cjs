const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {runInNewContext} = require('node:vm');
const {join} = require('node:path');

// Minimal DOM stand-in exercises the real cancellation handler without a browser or database.
function mountCancellation() {
    const element = () => Object.assign(new EventTarget(), {
        value: '', textContent: '', disabled: false, error: '',
        focus() {},
        setCustomValidity(message) {this.error = message;},
    });
    const selectors = [
        '[data-pr-cancel-version]', '[data-pr-cancel-number]', '[data-pr-cancel-supplier]',
        '[data-pr-cancel-purchase]', '[data-pr-cancel-warehouse]', '[data-pr-cancel-quantity]',
        '[data-pr-cancel-items-count]', '[data-pr-cancel-warehouse-warning]',
        '[name="reason"]', '[data-pr-cancel-submit]', '[data-pr-cancel-close]',
    ];
    const controls = Object.fromEntries(selectors.map(key => [key, element()]));
    const form = element();
    form.querySelector = selector => controls[selector];
    form.reportValidity = () => !controls['[name="reason"]'].error;
    const dialog = element();
    dialog.querySelector = selector => selector === '[data-pr-cancel-form]' ? form : controls[selector];
    dialog.showModal = () => {dialog.open = true;};
    dialog.close = () => {dialog.open = false;};
    const trigger = element();
    trigger.dataset = {
        action: '/purchase-returns/7', version: '3', number: '00007',
        supplier: '<strong>تأمین‌کننده</strong>', purchase: '42',
        warehouse: 'انبار مرجوعی', quantity: '5', itemsCount: '2',
    };
    const document = {
        querySelector: selector => selector === '[data-pr-cancel-dialog]' ? dialog : null,
        querySelectorAll: selector => selector === '[data-pr-cancel-trigger]' ? [trigger] : [],
    };
    const source = readFileSync(join(__dirname, '../../public/js/purchase-returns.js'), 'utf8');
    runInNewContext(source, {document, Intl, BigInt});
    return {controls, trigger, dialog, form};
}

test('cancellation is a shared, safely populated dialog; closing never submits', () => {
    const {controls, trigger, dialog, form} = mountCancellation();
    trigger.dispatchEvent(new Event('click'));
    assert.equal(dialog.open, true);
    assert.equal(form.action, '/purchase-returns/7');
    assert.equal(controls['[data-pr-cancel-version]'].value, '3');
    assert.equal(controls['[data-pr-cancel-number]'].textContent, '۰۰۰۰۷');
    assert.equal(controls['[data-pr-cancel-items-count]'].textContent, '۲');
    assert.equal(controls['[data-pr-cancel-warehouse-warning]'].textContent, 'انبار مرجوعی');
    assert.equal(controls['[data-pr-cancel-supplier]'].textContent, '<strong>تأمین‌کننده</strong>');
    controls['[data-pr-cancel-close]'].dispatchEvent(new Event('click'));
    assert.equal(dialog.open, false);
    assert.equal(controls['[data-pr-cancel-submit]'].disabled, false);
});

test('blank cancellation reason is rejected, and repeated submit is blocked', () => {
    const {controls, trigger, form} = mountCancellation();
    trigger.dispatchEvent(new Event('click'));
    const reason = controls['[name="reason"]'];
    const submit = controls['[data-pr-cancel-submit]'];
    reason.value = '   ';
    const blank = new Event('submit', {cancelable: true});
    form.dispatchEvent(blank);
    assert.equal(blank.defaultPrevented, true);
    assert.equal(submit.disabled, false);

    reason.value = 'اصلاح حساب تأمین‌کننده';
    reason.dispatchEvent(new Event('input'));
    const first = new Event('submit', {cancelable: true});
    form.dispatchEvent(first);
    assert.equal(first.defaultPrevented, false);
    assert.equal(submit.disabled, true);
    const second = new Event('submit', {cancelable: true});
    form.dispatchEvent(second);
    assert.equal(second.defaultPrevented, true);
});
