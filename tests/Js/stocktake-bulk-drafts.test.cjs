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

function fixture({allowConfirm = true, customRows, rejectBulk = false, rejectFinalize = false, paged = false} = {}) {
    const nodes = Object.fromEntries(
        ['stocktakeCategoryApp', 'category', 'subcategory', 'scShow', 'scMore',
            'scStatus', 'scProducts', 'scLiveSearch', 'scClose', 'stocktakeForm', 'scSaveAll', 'scDraftList',
            'scFinalizeAll', 'scReviewSummary', 'scToolbarStatus', 'scReview', 'scBulkToolbar']
            .map(id => [id, element()])
    );
    nodes.stocktakeCategoryApp.dataset = {
        embedded: '1', rowsUrl: '/stock-count-documents/category/variants',
        storeUrl: '/stock-count-documents', bulkStoreUrl: '/stock-count-documents/category-drafts',
        bulkFinalizeUrl: '/stock-count-documents/category-finalize',
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
                if (String(url).endsWith('category-finalize')) {
                    if (rejectFinalize) return {ok: false, async json() {return {message: 'No changes applied', conflicts: [{reason: 'Reservation changed'}]};}};
                    return {ok: true, async json() {return {documents: payload.document_ids.map(id => ({document_id: id, product_id: id - 50, view_url: '/view/'+id}))};}};
                }
                if (String(url).endsWith('category-drafts')) {
                    if (rejectBulk) return {ok: false, async json() {return {errors: {products: ['Count rejected']}};}};
                    return {ok: true, async json() {return {documents: payload.products.map(p => ({
                        product_id: p.product_id, product_name: 'Product '+p.product_id,
                        document_id: p.product_id+50, edit_url: '/draft/'+p.product_id,
                        counted_count: Object.values(p.actual_quantities).filter(value => value !== null).length,
                        variants_count: rows.filter(row => row.product_id === p.product_id).length,
                        total_before: 220, total_actual: 100, total_increase: 0, total_decrease: 120,
                        review_token: 'review-'+p.product_id,
                    }))};}};
                }
                return {ok: true, async json() {return {document_id: 55, edit_url: '/stock-count-documents/55/edit'};}};
            }
            requests.push({method: 'GET', url: String(url)});
            const cursor = Number(new URL(url).searchParams.get('after_id') || 0);
            const matches = rows.filter(r => (!new URL(url).searchParams.get('q') || r.product_id === 8) && (!paged || r.variant_id > cursor));
            const page = paged ? matches.slice(0, 1) : matches;
            return {ok: true, async json() {return {
                rows: page, has_more: paged && matches.length > 1, next_cursor: page.at(-1)?.variant_id || cursor,
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
    return {nodes, requests, inputs, button, enter, date};
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

test('saved draft remains editable and resave updates existing document without duplicating it', async () => {
    const ui = fixture();
    await ui.nodes.scShow.on_click();
    ui.enter(ui.inputs()[0], '8');
    await ui.nodes.scSaveAll.on_click();
    ui.enter(ui.inputs()[0], '9');
    await ui.nodes.scSaveAll.on_click();
    const bulk = ui.requests.filter(x => x.url.endsWith('category-drafts')).at(-1);
    assert.equal(bulk.payload.products.length, 1);
    assert.equal(bulk.payload.products[0].product_id, 7);
    assert.equal(bulk.payload.products[0].document_id, 57);
    assert.equal(bulk.payload.products[0].actual_quantities['101'], '9');
    assert.equal(ui.nodes.scDraftList.children.length, 2);
});

test('explicit bulk save then review enables one confirmed atomic finalization with reviewed tokens', async () => {
    const ui = fixture();
    await ui.nodes.scShow.on_click();
    ui.enter(ui.inputs()[0], '0'); ui.enter(ui.inputs()[1], '12');
    assert.equal(ui.nodes.scFinalizeAll.disabled, true);
    await ui.nodes.scSaveAll.on_click();
    assert.equal(ui.nodes.scFinalizeAll.disabled, true, 'review must be opened explicitly before finalization');
    ui.nodes.scReview.on_click();
    assert.equal(ui.nodes.scFinalizeAll.disabled, false);
    assert.match(ui.nodes.scReviewSummary.textContent, /پیش‌نویس/);
    assert.equal(ui.requests.filter(r => r.url.endsWith('category-finalize')).length, 0);
    await ui.nodes.scFinalizeAll.on_click();
    const writes = ui.requests.filter(r => r.url.endsWith('category-finalize'));
    assert.equal(writes.length, 1);
    assert.deepEqual(writes[0].payload.document_ids, [57, 58]);
    assert.deepEqual(writes[0].payload.review_tokens, {'57': 'review-7', '58': 'review-8'});
    assert.equal(ui.nodes.scFinalizeAll.disabled, true);
    assert.ok(ui.inputs().every(input => input.disabled));
    assert.equal(ui.date.disabled, true);
    assert.match(ui.nodes.scStatus.textContent, /نهایی/);
});

test('incomplete saved drafts and edits after review keep bulk finalization blocked', async () => {
    const ui = fixture();
    await ui.nodes.scShow.on_click();
    ui.enter(ui.inputs()[0], '0');
    await ui.nodes.scSaveAll.on_click();
    assert.equal(ui.nodes.scFinalizeAll.disabled, true);
    ui.enter(ui.inputs()[1], '12');
    assert.equal(ui.nodes.scFinalizeAll.disabled, true);
    await ui.nodes.scSaveAll.on_click();
    ui.nodes.scReview.on_click();
    assert.equal(ui.nodes.scFinalizeAll.disabled, false);
    ui.enter(ui.inputs()[0], '');
    assert.equal(ui.nodes.scFinalizeAll.disabled, true);
    await ui.nodes.scSaveAll.on_click();
    assert.equal(ui.nodes.scFinalizeAll.disabled, true);
    assert.equal(ui.requests.filter(r => r.url.endsWith('category-drafts')).at(-1).payload.products[0].actual_quantities['101'], null);
});

test('changing document date invalidates review and resaves all existing drafts with the new date', async () => {
    const ui = fixture();
    await ui.nodes.scShow.on_click();
    ui.enter(ui.inputs()[0], '1'); ui.enter(ui.inputs()[1], '2');
    await ui.nodes.scSaveAll.on_click();
    ui.nodes.scReview.on_click();
    assert.equal(ui.nodes.scFinalizeAll.disabled, false);
    ui.date.value = '2026-10-30';
    ui.date.on_change();
    assert.equal(ui.nodes.scFinalizeAll.disabled, true);
    assert.equal(ui.nodes.scSaveAll.disabled, false);
    await ui.nodes.scSaveAll.on_click();
    const payload = ui.requests.filter(r => r.url.endsWith('category-drafts')).at(-1).payload;
    assert.equal(payload.document_date, '2026-10-30');
    assert.deepEqual(payload.products.map(p => p.document_id), [57, 58]);
    assert.equal(ui.nodes.scFinalizeAll.disabled, true, 'date resave requires a fresh review');
    ui.nodes.scReview.on_click();
    assert.equal(ui.nodes.scFinalizeAll.disabled, false);
});

test('declined final confirmation and server conflicts never mark drafts finalized', async () => {
    for (const options of [{allowConfirm: false}, {rejectFinalize: true}]) {
        const ui = fixture(options);
        await ui.nodes.scShow.on_click();
        ui.enter(ui.inputs()[0], '1'); ui.enter(ui.inputs()[1], '2');
        await ui.nodes.scSaveAll.on_click();
        ui.nodes.scReview.on_click();
        await ui.nodes.scFinalizeAll.on_click();
        assert.ok(ui.inputs().every(input => !input.disabled));
        assert.equal(ui.nodes.scFinalizeAll.disabled, false);
        if (options.rejectFinalize) assert.match(ui.nodes.scStatus.textContent, /Reservation changed/);
        else assert.equal(ui.requests.filter(r => r.url.endsWith('category-finalize')).length, 0);
    }
});

test('toolbar pagination stays accessible and uses bounded cursor requests before saving', async () => {
    const ui = fixture({paged: true});
    await ui.nodes.scShow.on_click();
    assert.equal(ui.nodes.scSaveAll.disabled, true);
    assert.equal(ui.nodes.scMore.hidden, false);
    await ui.nodes.scMore.on_click();
    assert.equal(ui.inputs().length, 2);
    assert.equal(ui.nodes.scSaveAll.disabled, false);
    const gets = ui.requests.filter(r => r.method === 'GET');
    assert.equal(new URL(gets[1].url).searchParams.get('after_id'), '101');
    assert.ok(gets.every(r => new URL(r.url).searchParams.get('limit') === '60'));
});

test('category page has one floating toolbar with bulk save, review, finalize and no per-product save controls', () => {
    for (const name of ['form', 'category-workspace']) {
        const blade = fs.readFileSync(path.join(__dirname, '../../resources/views/stocktake/'+name+'.blade.php'), 'utf8');
        assert.match(blade, /id="scBulkToolbar"/);
        assert.match(blade, /sc-bulk-toolbar[^}]*position:fixed/s);
        assert.match(blade, /id="scFinalizeAll"/);
        assert.match(blade, /sc-bulk-toolbar[^}]*background:var\(--app-surface/s);
        assert.match(blade, /sc-review-summary[^}]*color:var\(--app-text/s);
    }
    assert.doesNotMatch(script, /data-save-product|saveProduct\(|ذخیره پیش‌نویس این محصول/);
});


