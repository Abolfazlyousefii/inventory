import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync(new URL('../../public/js/finance-payment-calculator.js', import.meta.url), 'utf8');

class FakeElement {
    constructor() {
        this.children = [];
        this.listeners = {};
        this.fields = {};
        this.dataset = {};
        this.attributes = {};
        this.classList = { toggle: () => {} };
        this.hidden = false;
        this.disabled = false;
        this.textContent = '';
        this.value = '';
        this.parent = null;
    }
    get rows() { return this.children; }
    addEventListener(event, handler) { this.listeners[event] = handler; }
    setAttribute(name, value) { this.attributes[name] = value; }
    fire(event) { return this.listeners[event]?.({ preventDefault() {} }); }
    appendChild(item) { this.children.push(item); item.parent = this; return item; }
    replaceChildren(...children) { this.children = []; children.forEach(item => this.appendChild(item)); }
    querySelector(selector) { return this.fields[selector] || null; }
    querySelectorAll(selector) {
        if (selector === '.pc-row-result') return this.rows.map(row => row.querySelector(selector)).filter(Boolean);
        if (selector === '[data-pc-mode]') return this.children.filter(item => Boolean(item.dataset.pcMode));
        if (selector === '[data-pc-example]') return this.children.filter(item => Boolean(item.dataset.pcExample));
        return [];
    }
    remove() {
        if (!this.parent) return;
        const index = this.parent.children.indexOf(this);
        if (index >= 0) this.parent.children.splice(index, 1);
    }
    focus() { this.focused = true; }
}

function makeRow(kind) {
    const row = new FakeElement();
    const fields = kind === 'fixed'
        ? ['.pc-kind', '.pc-due', '.pc-amount', '.pc-remove']
        : ['.pc-due', '.pc-weight', '.pc-row-result', '.pc-remove'];
    fields.forEach(key => { row.fields[key] = new FakeElement(); });
    if (kind === 'fixed') row.fields['.pc-kind'].value = 'cash';
    else row.fields['.pc-weight'].value = '1';
    row.cloneNode = () => makeRow(kind);
    return row;
}

function mount(response = () => ({
    ok: true,
    result: {
        fixed_payments: [{ day: 0, amount_rial: 50000000 }],
        flexible_payments: [{ day: 30, amount_rial: 53000000 }],
        total_nominal_rial: 103000000,
        fixed_total_rial: 50000000,
        flexible_total_rial: 53000000,
        nominal_increase_rial: 3000000,
        present_value_rounded_rial: 100000000,
        overall_rate: 0.03,
        daily_rate: 0.00194
    }
})) {
    const ids = [
        'pc-calculator', 'pc-form', 'pc-fixed-body', 'pc-weighted-body',
        'pc-price', 'pc-rate', 'pc-base-date', 'pc-customer',
        'pc-result-status', 'pc-result', 'pc-error', 'pc-submit', 'pc-print',
        'pc-fixed-empty', 'pc-fixed-template', 'pc-weighted-template',
        'pc-result-customer', 'pc-result-rows', 'pc-nominal',
        'pc-fixed-total', 'pc-cheques-total', 'pc-increase', 'pc-present',
        'pc-overall', 'pc-daily', 'pc-add-fixed', 'pc-add-weighted', 'pc-reset',
        'pc-price-field', 'pc-weighted-panel', 'pc-mode-help', 'pc-fixed-title',
        'pc-fixed-help', 'pc-price-label', 'pc-answer', 'pc-answer-title',
        'pc-answer-help', 'pc-increase-label'
    ];
    const elements = Object.fromEntries(ids.map(key => [key, new FakeElement()]));
    const el = key => elements[key];
    el('pc-calculator').dataset = {
        calculateUrl: '/finance/reports/payment-calculator/calculate',
        printUrl: '/finance/reports/payment-calculator/print',
        firstDue: '۱۴۰۵/۰۹/۱۴',
        secondDue: '۱۴۰۵/۱۰/۱۴',
        thirdDue: '۱۴۰۵/۱۱/۱۴'
    };
    for (const mode of ['schedule', 'cash_needed', 'cash_value']) {
        const button = new FakeElement();
        button.dataset.pcMode = mode;
        el('pc-calculator').appendChild(button);
    }
    for (const scenario of ['schedule', 'last_cheque', 'cash_needed', 'cash_value']) {
        const button = new FakeElement();
        button.dataset.pcExample = scenario;
        el('pc-calculator').appendChild(button);
    }
    el('pc-base-date').value = '۱۴۰۵/۰۸/۱۴';
    el('pc-print').disabled = true;
    el('pc-result').hidden = true;
    el('pc-fixed-template').content = { firstElementChild: makeRow('fixed') };
    el('pc-weighted-template').content = { firstElementChild: makeRow('weighted') };
    el('pc-form').fields['[name="_token"]'] = { value: 'placeholder-token' };
    const posted = [];
    const submitted = [];
    const body = new FakeElement();
    const document = {
        body,
        getElementById: el,
        createElement: tag => {
            const element = new FakeElement();
            if (tag === 'form') {
                element.submit = () => {
                    submitted.push({
                        action: element.action,
                        target: element.target,
                        inputs: element.children.map(input => [input.name, input.value])
                    });
                };
            }
            return element;
        }
    };
    const fetch = async (url, options) => {
        posted.push({ url, options });
        const output = response();
        return { ok: output.ok, json: async () => output };
    };
    runInNewContext(script, {
        document, fetch, AbortController, Intl,
        setTimeout: () => 1,
        clearTimeout: () => {},
        console
    }, { filename: 'finance-payment-calculator.js' });
    return { el, posted, submitted, modeButton: value => el('pc-calculator').children.find(button => button.dataset.pcMode === value), exampleButton: value => el('pc-calculator').children.find(button => button.dataset.pcExample === value) };
}

const flush = () => new Promise(resolve => setImmediate(resolve));

test('adding and removing cheques updates submitted data and only verified results can print', async () => {
    const { el, posted, submitted } = mount();
    assert.equal(el('pc-weighted-body').rows.length, 2);

    el('pc-weighted-body').rows[1].querySelector('.pc-remove').fire('click');
    assert.equal(el('pc-weighted-body').rows.length, 1);

    el('pc-add-fixed').fire('click');
    assert.equal(el('pc-fixed-body').rows.length, 1);
    el('pc-price').value = '100,000,000';
    el('pc-rate').value = '۶';
    el('pc-customer').value = 'مشتری نمونه';
    el('pc-fixed-body').rows[0].querySelector('.pc-amount').value = '۵۰٬۰۰۰٬۰۰۰';

    el('pc-form').fire('submit');
    await flush();
    assert.equal(posted.length, 1);
    const inputs = JSON.parse(posted[0].options.body);
    assert.equal(inputs.price_rial, '100000000');
    assert.equal(inputs.monthly_percent, '6');
    assert.equal(inputs.fixed_payments[0].amount_rial, '50000000');
    assert.equal(inputs.weighted_payments.length, 1);
    assert.equal(el('pc-print').disabled, false);

    el('pc-print').fire('click');
    assert.equal(submitted.length, 1);
    assert.equal(submitted[0].action, '/finance/reports/payment-calculator/print');
    assert.equal(submitted[0].target, '_blank');
    const fields = Object.fromEntries(submitted[0].inputs);
    assert.equal(fields.customer_name, 'مشتری نمونه');
    assert.equal(fields['fixed_payments[0][amount_rial]'], '50000000');
    assert.equal(fields['weighted_payments[0][due_date]'], '۱۴۰۵/۰۹/۱۴');

    el('pc-rate').value = '۷';
    el('pc-form').fire('input');
    assert.equal(el('pc-print').disabled, true);
    assert.equal(el('pc-result').hidden, true);
});

test('an invalid response keeps the print button disabled', async () => {
    const { el } = mount(() => ({
        ok: false, message: 'مبلغ نامعتبر', errors: { calculation: ['برنامه پرداخت معتبر نیست.'] }
    }));
    el('pc-price').value = '100000000';
    el('pc-rate').value = '6';
    el('pc-weighted-body').rows[1].querySelector('.pc-remove').fire('click');

    el('pc-form').fire('submit');
    await flush();
    assert.equal(el('pc-print').disabled, true);
    assert.equal(el('pc-error').hidden, false);
    assert.match(el('pc-error').textContent, /برنامه پرداخت معتبر نیست/);
});


test('cash-needed and cash-value modes send only the relevant input rows', async () => {
    const { el, posted, modeButton, exampleButton } = mount(() => ({
        ok: true,
        result: {
            mode: 'cash_needed', fixed_payments: [{ day: 90, amount_rial: 80000000 }],
            flexible_payments: [], computed_payments: [{ day: 0, amount_rial: 32830457 }],
            calculated_cash_rial: 32830457, cash_equivalent_rial: 0,
            total_nominal_rial: 112830457, fixed_total_rial: 80000000,
            flexible_total_rial: 0, total_cheques_rial: 80000000,
            nominal_increase_rial: 12830457, present_value_rounded_rial: 100000000,
            overall_rate: 0.12830457, daily_rate: 0.00194
        }
    }));
    exampleButton('cash_needed').fire('click');
    assert.equal(el('pc-weighted-panel').hidden, true);
    assert.equal(el('pc-fixed-body').rows.length, 1);
    assert.equal(el('pc-fixed-body').rows[0].querySelector('.pc-amount').value, '80000000');
    el('pc-form').fire('submit');
    await flush();
    assert.equal(posted.length, 1);
    const payload = JSON.parse(posted[0].options.body);
    assert.equal(payload.mode, 'cash_needed');
    assert.equal(payload.weighted_payments.length, 0);
    assert.equal(payload.price_rial, '100000000');
    assert.equal(el('pc-print').disabled, false);

    modeButton('cash_value').fire('click');
    assert.equal(el('pc-price-field').hidden, true);
    assert.equal(el('pc-print').disabled, true);
    exampleButton('cash_value').fire('click');
    assert.equal(el('pc-fixed-body').rows.length, 3);
    el('pc-form').fire('submit');
    await flush();
    const second = JSON.parse(posted[1].options.body);
    assert.equal(second.mode, 'cash_value');
    assert.equal(second.price_rial, null);
    assert.equal(second.weighted_payments.length, 0);
    assert.deepEqual(second.fixed_payments.map(x => x.amount_rial), ['20000000', '30000000', '80000000']);
});

test('last-cheque example fills a known advance, known cheque, and one computed cheque', async () => {
    const { el, posted, exampleButton } = mount();
    exampleButton('last_cheque').fire('click');
    assert.equal(el('pc-fixed-body').rows.length, 2);
    assert.equal(el('pc-weighted-body').rows.length, 1);
    assert.equal(el('pc-weighted-panel').hidden, false);
    el('pc-form').fire('submit');
    await flush();
    const data = JSON.parse(posted[0].options.body);
    assert.equal(data.mode, 'schedule');
    assert.equal(data.fixed_payments[0].amount_rial, '20000000');
    assert.equal(data.fixed_payments[1].kind, 'cheque');
    assert.equal(data.fixed_payments[1].amount_rial, '30000000');
    assert.equal(data.weighted_payments[0].due_date, '۱۴۰۵/۱۰/۱۴');
});
