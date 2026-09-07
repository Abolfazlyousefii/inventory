import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import test from 'node:test';

const blade = readFileSync(new URL('../../resources/views/preinvoice/create.blade.php', import.meta.url), 'utf8');
const source = blade.slice(blade.indexOf('    function preloadCustomerOption('), blade.indexOf('    function getRecentProducts('));
function setup(available = true) {
    const elements = {customer_search_select: {value: '', options: [], add(o) {this.options.push(o);}}, customer_search_error: {}, customer_id: {value: ''}};
    const handlers = {};
    let config, done, fail;
    const errors = [], applied = [];
    const wrapper = {hasClass: () => false, select2: c => {config = c;}, on: (e, f) => {handlers[e] = f;}, trigger() {}, val(v) {elements.customer_search_select.value = v; return this;}};
    const jq = () => wrapper;
    jq.fn = {select2() {}};
    jq.ajax = () => ({done(f) {done = f;}, fail(f) {fail = f;}, abort() {fail({status: 0}, 'abort');}});
    const context = vm.createContext({window: available ? {jQuery: jq} : {}, document: {getElementById: id => elements[id]}, console: {error: (...args) => errors.push(args)}, API: {customers: '/customers', customer: '/customers/__CUSTOMER_ID__'}, OLD_CUSTOMER_ID: '', customerFullName: c => c.name, applyCustomerToForm: c => applied.push(c), clearCustomer() {}, Option: function(text, value) {this.text = text; this.value = String(value);}, fetch: async () => ({ok: true, json: async () => ({data: {customer: {id: 7, name: 'Local'}}})})});
    vm.runInContext(source, context);
    context.initCustomerSearch();
    return {context, elements, handlers, errors, applied, config, done: data => done(data), fail: (...args) => fail(...args)};
}

test('missing dependencies display a visible error without throwing', () => {
    const s = setup(false);
    assert.equal(s.elements.customer_search_select.disabled, true);
    assert.equal(s.elements.customer_search_error.hidden, false);
    assert.equal(s.errors.length, 1);
});

test('search errors, invalid JSON, malformed responses and recovery are visible', () => {
    const s = setup();
    assert.equal(s.config.minimumInputLength, 0);
    assert.equal(s.config.placeholder, 'نام، موبایل یا کد مشتری...');
    let failures = 0, successes = 0;
    s.config.ajax.transport({}, () => successes++, () => failures++);
    for (const status of [403, 419, 422, 500, 503]) s.fail({status}, 'error', 'HTTP failure');
    s.fail({status: 200}, 'parsererror', 'HTML response');
    s.done({unexpected: []});
    assert.equal(failures, 7);
    assert.equal(s.errors.length, 7);
    assert.equal(s.elements.customer_search_error.hidden, false);
    s.fail({status: 0}, 'abort');
    assert.equal(failures, 7);
    s.done({data: {customers: [{id: 7, name: 'Local'}]}});
    assert.equal(successes, 1);
    assert.equal(s.elements.customer_search_error.hidden, true);
});

test('selection loads details and ignores a response after clearing', async () => {
    const s = setup();
    s.elements.customer_search_select.value = '7';
    await s.handlers['select2:select']({params: {data: {id: 7}}});
    assert.equal(s.applied[0].id, 7);
    const pending = s.handlers['select2:select']({params: {data: {id: 7}}});
    s.elements.customer_search_select.value = '';
    await pending;
    assert.equal(s.applied.length, 1);
});

test('failed details restore the previous selection and report the error', async () => {
    const s = setup();
    s.elements.customer_id.value = '3';
    s.elements.customer_search_select.value = '7';
    s.context.fetch = async () => ({ok: false, status: 503});
    await s.handlers['select2:select']({params: {data: {id: 7}}});
    assert.equal(s.elements.customer_search_select.value, '3');
    assert.equal(s.applied.length, 0);
    assert.equal(s.errors.length, 1);
});

test('old/edit preload applies details, deduplicates options and reports failure', async () => {
    const s = setup();
    s.elements.customer_id.value = '7';
    await s.context.loadOldCustomer();
    await s.context.loadOldCustomer();
    assert.equal(s.applied.length, 2);
    assert.equal(s.elements.customer_search_select.options.length, 1);
    s.context.fetch = async () => {throw new Error('offline');};
    await s.context.loadOldCustomer();
    assert.equal(s.errors.length, 1);
});
