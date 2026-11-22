const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {join} = require('node:path');
const {runInNewContext} = require('node:vm');

function mountPrint({validDocument = true} = {}) {
    const trigger = new EventTarget();
    trigger.dataset = {prPrintUrl: '/purchase-returns/4/print'};
    let printCount = 0;
    let focusCount = 0;
    let alertCount = 0;
    const printable = Object.assign(new EventTarget(), {
        document: {
            querySelector: selector => selector === '[data-pr-print-document]' && validDocument ? {} : null,
            fonts: {ready: Promise.resolve()},
        },
        focus: () => {focusCount++;},
        print: () => {printCount++;},
    });
    const frame = Object.assign(new EventTarget(), {
        contentWindow: printable,
        style: {},
        setAttribute() {},
        remove() {this.removed = true;},
    });
    const document = {
        querySelector: () => null,
        querySelectorAll: selector => selector === '[data-pr-print-url]' ? [trigger] : [],
        createElement: tag => {
            assert.equal(tag, 'iframe');
            return frame;
        },
        body: {appendChild: element => {assert.equal(element, frame);}},
    };
    const source = readFileSync(join(__dirname, '../../public/js/purchase-returns.js'), 'utf8');
    runInNewContext(source, {document, BigInt, Intl, alert: () => {alertCount++;}});
    return {trigger, frame, printable, counts: () => ({printCount, focusCount, alertCount})};
}

test('print action uses the existing route in a hidden frame without leaving the page', async () => {
    const {trigger, frame, printable, counts} = mountPrint();
    trigger.dispatchEvent(new Event('click'));
    assert.equal(frame.src, '/purchase-returns/4/print');
    assert.match(frame.style.cssText, /left:-10000px/);
    trigger.dispatchEvent(new Event('click')); // double click does not trigger another print
    frame.dispatchEvent(new Event('load'));
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(counts(), {printCount: 1, focusCount: 1, alertCount: 0});
    printable.dispatchEvent(new Event('afterprint'));
    assert.equal(frame.removed, true);
});

test('non-printable responses never open the print dialog', async () => {
    const {trigger, frame, counts} = mountPrint({validDocument: false});
    trigger.dispatchEvent(new Event('click'));
    frame.dispatchEvent(new Event('load'));
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(counts(), {printCount: 0, focusCount: 0, alertCount: 1});
    assert.equal(frame.removed, true);
});

test('both archive and detail actions print directly and the template has no extra print button', () => {
    const views = ['index.blade.php', 'show.blade.php'];
    for (const view of views) {
        const text = readFileSync(join(__dirname, '../../resources/views/purchase-returns', view), 'utf8');
        assert.match(text, /<button[^>]*data-pr-print-url=/);
        assert.doesNotMatch(text, /target="_blank"[^>]*>چاپ/);
    }
    const print = readFileSync(join(__dirname, '../../resources/views/purchase-returns/print.blade.php'), 'utf8');
    assert.match(print, /data-pr-print-document/);
    assert.match(print, /IRANSans/);
    assert.doesNotMatch(print, /window\.print\(\)|چاپ \/ ذخیره PDF/);
});
