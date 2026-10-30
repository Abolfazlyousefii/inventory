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

function fixture({allowConfirm = true, customRows, rejectBulk = false} = {}) {
    const nodes = Object.fromEntries(
        ['stocktakeCategoryApp', 'category', 'subcategory', 'scShow', 'scMore',
            'scStatus', 'scProducts', 'scLiveSearch', 'scClose', 'stocktakeForm', 'scSaveAll', 'scDraftList']
            .map(id => [id, element()])
    );
    nodes.stocktakeCategoryApp.dataset = {
        embedded: '1', rowsUrl: '/stock-count-documents/category/variants',
        storeUrl: '/stock-count-documents', bulkStoreUrl: '/stock-count-documents/category-drafts',
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
    const rows = customRows || [row(101, 'مشکی'), row(102, 'سفید', 8)];
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
                requests.push({method: 'POST', url: String(url), payload});
                if (String(url).endsWith('category-drafts')) {
                    if (rejectBulk) return {ok: false, async json() {return {errors: {products: ['Count rejected']}};}};
                    return {ok: true, async json() {return {documents: payload.products.map(p => ({
                        product_id: p.product_id, product_name: 'Product '+p.product_id,
                        document_id: p.product_id+50, edit_url: '/draft/'+p.product_id,
                        counted_count: Object.keys(p.actual_quantities).length, variants_count: 1,
                    }))};}};
                }
                return {ok: true, async json() {return {document_id: 55, edit_url: '/stock-count-documents/55/edit'};}};
            }
            requests.push({method: 'GET', url: String(url)});
            return {ok: true, async json() {return {
                rows: rows.filter(r => !new URL(url).searchParams.get('q') || r.product_id === 8), has_more: false, next_cursor: rows.at(-1)?.variant_id || 0,
            };}};
        },
    };
    vm.runInNewContext(script, context);
    listeners.DOMContentLoaded();

    const inputs = () => nodes.scProducts.querySelectorAll('.sc-actual');
    const button = () => {
        function find(node) {
            if (node.dataset?.saveProduct) return node;
            for (const child of node.children || []) {
                const match = find(child);
                if (match) return match;
            }
            return null;
        }
        return find(nodes.scProducts);
    };
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

test('one bulk save submits both products, preserves explicit zero and lists review links', async () => {
    const ui = fixture();
    await ui.nodes.scShow.on_click();
    ui.enter(ui.inputs()[0], '۰');
    ui.enter(ui.inputs()[1], '12');
    assert.equal(ui.nodes.scSaveAll.disabled, false);
    await ui.nodes.scSaveAll.on_click();
    const writes = ui.requests.filter(x => x.method === 'POST');
    assert.equal(writes.length, 1);
    assert.equal(writes[0].url, '/stock-count-documents/category-drafts');
    assert.equal(writes[0].payload.category_id, 3);
    assert.equal(writes[0].payload.products.length, 2);
    assert.equal(writes[0].payload.products[0].actual_quantities['101'], '0');
    assert.equal(writes[0].payload.products[1].actual_quantities['102'], '12');
    assert.equal(ui.nodes.scDraftList.children.length, 2);
    assert.equal(ui.nodes.scSaveAll.disabled, true);
    await ui.nodes.scSaveAll.on_click();
    assert.equal(ui.requests.filter(x => x.method === 'POST').length, 1);
});

test('bulk save includes counts hidden by live search and never fills blank quantities', async () => {
    const ui = fixture();
    await ui.nodes.scShow.on_click();
    ui.enter(ui.inputs()[0], '5');
    ui.nodes.scLiveSearch.value = 'filter';
    ui.nodes.scLiveSearch.on_input();
    await new Promise(resolve => setTimeout(resolve, 350));
    await until(() => ui.inputs().length === 1);
    await ui.nodes.scSaveAll.on_click();
    const payload = ui.requests.find(x => x.method === 'POST').payload;
    assert.equal(payload.products.length, 2);
    assert.equal(payload.products[0].actual_quantities['101'], '5');
    assert.deepEqual(Object.keys(payload.products[1].actual_quantities), []);
});

test('an invalid count hidden by live search blocks every bulk write', async () => {
    const ui = fixture();
    await ui.nodes.scShow.on_click();
    ui.enter(ui.inputs()[0], 'invalid');
    ui.nodes.scLiveSearch.value = 'filter';
    ui.nodes.scLiveSearch.on_input();
    await new Promise(resolve => setTimeout(resolve, 350));
    await until(() => ui.inputs().length === 1);
    ui.enter(ui.inputs()[0], '3');
    assert.equal(ui.nodes.scSaveAll.disabled, true);
    await ui.nodes.scSaveAll.on_click();
    assert.equal(ui.requests.filter(x => x.method === 'POST').length, 0);
});

test('failed atomic bulk save leaves entered counts editable and offers retry', async () => {
    const ui = fixture({rejectBulk: true});
    await ui.nodes.scShow.on_click();
    ui.enter(ui.inputs()[0], '8');
    await ui.nodes.scSaveAll.on_click();
    assert.equal(ui.inputs()[0].value, '8');
    assert.ok(ui.inputs().every(input => !input.disabled));
    assert.equal(ui.nodes.scSaveAll.disabled, false);
    assert.equal(ui.nodes.scDraftList.children.length, 0);
    assert.match(ui.nodes.scStatus.textContent, /Count rejected/);
});

test('a separately saved product is excluded from the subsequent bulk create request', async () => {
    const ui = fixture();
    await ui.nodes.scShow.on_click();
    ui.enter(ui.inputs()[0], '8');
    ui.nodes.scProducts.on_click({target: ui.button()});
    await until(() => ui.inputs()[0].disabled);
    await ui.nodes.scSaveAll.on_click();
    const bulk = ui.requests.find(x => x.url.endsWith('category-drafts'));
    assert.equal(bulk.payload.products.length, 1);
    assert.equal(bulk.payload.products[0].product_id, 8);
    assert.equal(ui.nodes.scDraftList.children.length, 2);
});


