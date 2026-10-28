const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const code = fs.readFileSync(path.join(__dirname, '../../public/js/product-stock-print.js'), 'utf8');

function fixture(fetchImpl, productIds = [11]) {
    const events = {};
    const makeNode = () => ({
        textContent: '',
        hidden: false,
        disabled: false,
        children: [],
        dataset: {},
        parentNode: null,
        get firstElementChild() { return this.children[0] || null; },
        classList: {toggle() {}},
        appendChild(node) {
            // A real DOM transfers a DocumentFragment's children to its parent.
            if (node.isFragment) {
                [...node.children].forEach(child => this.appendChild(child));
            } else {
                if (node.parentNode) node.parentNode.children = node.parentNode.children.filter(child => child !== node);
                node.parentNode = this;
                this.children.push(node);
            }
        },
        replaceChildren() { this.children = []; },
        addEventListener(name, fn) { this['on_' + name] = fn; },
    });
    const ids = ['printNow', 'cancelPrintLoad', 'closePrint', 'printStatus', 'printProgress',
        'printTotalRows', 'printLoadedRows', 'printTableBody'];
    const nodes = Object.fromEntries(ids.map(id => [id, makeNode()]));
    nodes.printNow.disabled = true;
    nodes.stockPrintApp = {
        dataset: {
            productIds: JSON.stringify(productIds),
            startUrl: '/products/selected-stock-export/start',
            batchUrl: '/products/selected-stock-export/batch',
            maxRows: '2000',
        },
    };
    const calls = {prints: 0, requests: []};
    const document = {
        addEventListener(name, fn) { events[name] = fn; },
        getElementById(id) { return nodes[id]; },
        querySelector() { return {content: 'csrf'}; },
        createElement() { return makeNode(); },
        createDocumentFragment() { return Object.assign(makeNode(), {isFragment: true}); },
    };
    const context = {
        document,
        window: {print() { calls.prints++; }, close() {}},
        fetch: async (url, options) => {
            calls.requests.push({url, body: JSON.parse(options.body)});
            const data = await fetchImpl(url, options);
            return {ok: true, async json() { return data; }};
        },
        AbortController,
        Number,
        JSON,
        Intl,
        setTimeout,
        clearTimeout,
        requestAnimationFrame: fn => queueMicrotask(fn),
    };
    vm.runInNewContext(code, context, {filename: 'product-stock-print.js'});
    events.DOMContentLoaded();
    return {nodes, calls};
}
async function tickUntil(predicate) {
    for (let attempt = 0; attempt < 50 && !predicate(); attempt++) {
        await new Promise(resolve => setImmediate(resolve));
    }
    assert.ok(predicate(), 'event loop did not reach expected state');
}

test('native print is locked until both stock batches are rendered and each variant is one row', async () => {
    let resolveSecond;
    const second = new Promise(resolve => { resolveSecond = resolve; });
    const row = id => ({
        product_name: 'کالا',
        variant_name: 'مدل ' + id,
        variant_code: 'SKU' + id,
        stock: 3, reserved: 1, sell_price_rial: 5000, sales_enabled: true,
    });
    const {nodes, calls} = fixture(async url => {
        if (url.endsWith('/start')) return {total_rows: 2};
        if (calls.requests.length === 2) return {rows: [row(1)], has_more: true, next_cursor: 11};
        return second;
    });
    nodes.printNow.on_click();
    assert.equal(calls.prints, 0);
    await tickUntil(() => calls.requests.length === 3);
    assert.equal(nodes.printNow.disabled, true);
    assert.equal(nodes.printTableBody.children.length, 1);
    resolveSecond({rows: [row(2)], has_more: false, next_cursor: 12});
    await tickUntil(() => nodes.printNow.disabled === false);
    assert.equal(nodes.printTableBody.children.length, 2);
    // Customer print is intentionally only five columns. No internal SKU,
    // variant code, reserved quantity or sales permission should be shown.
    const printed = nodes.printTableBody.children[0].children;
    assert.equal(printed.length, 5);
    assert.deepEqual(printed.map(cell => cell.textContent), [
        '۱', 'کالا', '—', '۳', '۵٬۰۰۰'
    ]);
    assert.equal(nodes.printProgress.value, 100);
    nodes.printNow.on_click();
    assert.equal(calls.prints, 1);
    assert.equal(calls.requests[0].body.product_ids[0], 11);
    assert.equal(calls.requests[2].body.after_id, 11);
});

test('a server failure keeps print disabled and shows an error instead of a partial PDF', async () => {
    const {nodes, calls} = fixture(async () => { throw Error('Server down'); });
    await tickUntil(() => nodes.cancelPrintLoad.textContent === 'بارگیری مجدد');
    assert.equal(nodes.printNow.disabled, true);
    nodes.printNow.on_click();
    assert.equal(calls.prints, 0);
    assert.match(nodes.printStatus.textContent, /Server down/);
});


test('full 2696-row print remains available without the old 2000-row cap', async () => {
    const total = 2696;
    const {nodes, calls} = fixture(async (url, options) => {
        if (url.endsWith('/start')) return {total_rows: total};
        const afterId = JSON.parse(options.body).after_id;
        const batchCount = Math.min(300, total - afterId);
        return {
            rows: Array.from({length: batchCount}, (_, i) => ({
                product_name: 'گارد آیفون',
                variant_name: 'تنوع ' + (afterId + i + 1),
                stock: 1, reserved: 0, sell_price_rial: 1000, sales_enabled: true,
            })),
            has_more: afterId + batchCount < total,
            next_cursor: afterId + batchCount,
        };
    });
    await tickUntil(() => nodes.printNow.disabled === false);
    assert.equal(nodes.printProgress.value, 100);
    assert.equal(calls.requests.length, 10); // 1 count + 9 batches (8 × 300 + 296)
    assert.match(nodes.printLoadedRows.textContent, /۲٬۶۹۶/);
    nodes.printNow.on_click();
    assert.equal(calls.prints, 1);
});


test('customer row contains model/color/design but omits SKU, reservation, variant code and sales status', async () => {
    const {nodes} = fixture(async url => url.endsWith('/start')
        ? {total_rows: 1}
        : {rows: [{
            product_name: 'قاب سامسونگ', product_code: 'PRIVATE-12', product_sku: 'INTERNAL-SKU',
            variant_code: 'V-SECRET', variant_name: 'توضیح داخلی',
            model_name: 'Galaxy A05', color_name: 'مشکی', variety_name: 'مات',
            brand_name: 'InternalBrand', stock: 17, reserved: 9,
            sell_price_rial: 325000, sales_enabled: false,
        }], has_more: false, next_cursor: 100});
    await tickUntil(() => nodes.printNow.disabled === false);
    const cols = nodes.printTableBody.children[0].children.map(x => x.textContent);
    assert.equal(cols.length, 5);
    assert.deepEqual(cols, ['۱', 'قاب سامسونگ', 'Galaxy A05 / مشکی / مات', '۱۷', '۳۲۵٬۰۰۰']);
    for (const hidden of ['PRIVATE-12', 'INTERNAL-SKU', 'V-SECRET', 'توضیح داخلی', 'InternalBrand', '۹', 'غیرفعال']) {
        assert.ok(!cols.includes(hidden), 'exposed private field: ' + hidden);
    }
});

test('product order is preserved across interleaved cursor batches without duplicate rows', async () => {
    const row=(product_id,model_name)=>({product_id,product_name:'Product '+product_id,model_name,stock:1,sell_price_rial:100});
    let batch=0;
    const {nodes}=fixture(async url=>{
        if(url.endsWith('/start'))return {total_rows:4};
        batch++;
        return batch===1 ? {rows:[row(11,'A'),row(22,'B')],has_more:true,next_cursor:2}
            : {rows:[row(11,'C'),row(22,'D')],has_more:false,next_cursor:4};
    },[22,11]);
    await tickUntil(()=>nodes.printNow.disabled===false);
    const rows=nodes.printTableBody.children;
    assert.equal(rows.length,4);
    assert.deepEqual(rows.map(row=>row.children[1].textContent),['Product 22','Product 22','Product 11','Product 11']);
    assert.deepEqual(rows.map(row=>row.children[0].textContent),['۱','۲','۳','۴']);
    assert.deepEqual(rows.map(row=>row.children[2].textContent),['B','D','A','C']);
});
