const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const script = fs.readFileSync(path.join(__dirname, '../../public/js/stocktake-category-workspace.js'), 'utf8');

function element(tag = 'div') {
    const children = [];
    const n = {
        tag, children, className: '', dataset: {}, value: '', textContent: '',
        hidden: false, disabled: false, validationMessage: '',
        classList: {toggle() {}, add() {}, remove() {}},
        append(...items) { children.push(...items); },
        appendChild(item) { children.push(item); },
        replaceChildren(...items) { children.splice(0, children.length, ...items); },
        setAttribute() {},
        setCustomValidity(message) { this.validationMessage = message; },
        addEventListener(name, callback) { this['on_' + name] = callback; },
        focus() {},
        closest(selector) {
            if (selector === '.sc-actual' && this.className.includes('sc-actual')) return this;
            if (selector === '[data-save-product]' && this.dataset.saveProduct) return this;
            return null;
        },
        querySelectorAll(selector) {
            const result = [];
            function walk(current) {
                if (selector === '.sc-actual' && current.className?.includes('sc-actual')) result.push(current);
                if (selector === '[data-difference-for]' && current.dataset?.differenceFor) result.push(current);
                for (const child of current.children || []) walk(child);
            }
            walk(this);
            return result;
        },
    };
    return n;
}

function fixture({allowConfirm = true, customRows} = {}) {
    const nodes = Object.fromEntries(
        ['stocktakeCategoryApp', 'category', 'subcategory', 'scShow', 'scMore',
            'scStatus', 'scProducts', 'scLiveSearch', 'scClose', 'stocktakeForm', 'scSaveAll', 'scFinalizeAll', 'scDraftList']
            .map(id => [id, element()])
    );
    nodes.stocktakeCategoryApp.dataset = {
        embedded: '1', rowsUrl: '/stock-count-documents/category/variants',
        storeUrl: '/stock-count-documents',
        bulkStoreUrl: '/stock-count-documents/category-drafts',
    };
    nodes.category.value = '3';
    nodes.subcategory.value = '';
    nodes.stocktakeForm.dataset = {};
    const date = element('input');
    date.value = '2026-10-29';
    const listeners = {};
    const requests = [];
    const row = (id, name, product = 7) => ({
        product_id: product, product_name: 'ایرپاد', variant_id: id, name,
        sku: 'MODEL-'+id, system_available: 200, active_reserved: 20,
        expected_physical: 220, queue_total: 100, pending_collection: 100,
        warehouse_received: 0, collecting: 0,
    });
    const rows = customRows || [row(101, 'مشکی'), row(102, 'سفید')];
    const document = {
        addEventListener(name, callback) {listeners[name] = callback;},
        getElementById(id) {return nodes[id];},
        querySelector(selector) {
            if (selector === '[name="document_date"]') return date;
            if (selector === 'meta[name=csrf-token]') return {content: 'csrf-token'};
            return null;
        },
        querySelectorAll() {return [];},
        createElement(tag) {return element(tag);},
    };
    const context = {
        document, location: {origin: 'https://erp.example.test'},
        URL, AbortController, Number, Intl, setTimeout, clearTimeout,
        window: {addEventListener() {}, confirm() {return allowConfirm;}},
        fetch: async (url, options = {}) => {
            if (options.method === 'POST') {
                const payload = JSON.parse(options.body);
                requests.push({method: 'POST', payload});
                return {ok: true, async json() {return {documents: payload.products.map(product => ({product_id: product.product_id, document_id: 55, edit_url: '/stock-count-documents/55/edit', counted_count: Object.keys(product.actual_quantities).length, variants_count: 2, review_token: 'review-55'}))};}};
            }
            requests.push({method: 'GET', url: String(url)});
            return {ok: true, async json() {return {
                rows, has_more: false, next_cursor: rows.at(-1)?.variant_id || 0,
            };}};
        },
    };
    vm.runInNewContext(script, context);
    listeners.DOMContentLoaded();

    const inputs = () => nodes.scProducts.querySelectorAll('.sc-actual');
    const button = () => nodes.scSaveAll;
    const enter = (input, value) => {
        input.value = value;
        nodes.scProducts.on_input({target: input});
    };
    return {nodes, requests, inputs, button, enter};
}

async function until(predicate) {
    for (let n = 0; n < 100 && !predicate(); n++) {
        await new Promise(resolve => setImmediate(resolve));
    }
    assert.ok(predicate(), 'expected stocktake UI state not reached');
}

test('invalid quantity remains blocked when another variant is edited', async () => {
    const ui = fixture();
    await ui.nodes.scShow.on_click();
    await until(() => ui.inputs().length === 2);
    const [first, second] = ui.inputs();
    ui.enter(first, 'نامعتبر');
    assert.notEqual(first.validationMessage, '');
    assert.equal(ui.button().disabled, true);

    ui.enter(second, '2');
    assert.equal(ui.button().disabled, true, 'invalid first input must prevent draft submission');

    await ui.nodes.scSaveAll.on_click();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(ui.requests.filter(x => x.method === 'POST').length, 0);
});

test('Persian zero is sent as explicit 0 while blank remains missing; draft never finalizes stock', async () => {
    const ui = fixture();
    await ui.nodes.scShow.on_click();
    await until(() => ui.inputs().length === 2);
    const [first, second] = ui.inputs();
    ui.enter(first, '۰');
    assert.equal(first.value, '0');
    assert.equal(second.value, '');

    await ui.nodes.scSaveAll.on_click();
    await until(() => ui.requests.some(x => x.method === 'POST'));
    const writes = ui.requests.filter(x => x.method === 'POST');
    assert.equal(writes.length, 1);
    assert.equal(writes[0].payload.products[0].product_id, 7);
    assert.equal(writes[0].payload.products[0].actual_quantities['101'], '0');
    assert.equal(Object.hasOwn(writes[0].payload.products[0].actual_quantities, '102'), false);
    assert.ok(!ui.requests.some(x => x.method === 'POST' && x.url?.includes('finalize')));
    assert.ok(ui.inputs().every(x => !x.disabled), 'drafts remain editable until explicit finalization');
    assert.equal(ui.nodes.scFinalizeAll.disabled, true, 'blank variant still blocks finalization');
});

test('declined category switch preserves unsaved count and does not fetch a different category', async () => {
    const ui = fixture({allowConfirm: false});
    await ui.nodes.scShow.on_click();
    await until(() => ui.inputs().length === 2);
    ui.enter(ui.inputs()[0], '15');
    const gets = ui.requests.filter(x => x.method === 'GET').length;

    ui.nodes.category.value = '9';
    await ui.nodes.scShow.on_click();
    assert.equal(ui.inputs()[0].value, '15');
    assert.equal(ui.requests.filter(x => x.method === 'GET').length, gets);
});
