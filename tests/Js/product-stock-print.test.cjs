const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const code = fs.readFileSync(path.join(__dirname, '../../public/js/product-stock-print.js'), 'utf8');

function fixture(fetchImpl) {
    const events = {};
    const makeNode = () => ({
        textContent: '',
        hidden: false,
        disabled: false,
        children: [],
        classList: {toggle() {}},
        appendChild(node) { this.children.push(node); },
        replaceChildren() { this.children = []; },
        addEventListener(name, fn) { this['on_' + name] = fn; },
    });
    const ids = ['printNow', 'cancelPrintLoad', 'closePrint', 'printStatus', 'printProgress',
        'printTotalRows', 'printLoadedRows', 'printTableBody'];
    const nodes = Object.fromEntries(ids.map(id => [id, makeNode()]));
    nodes.printNow.disabled = true;
    nodes.stockPrintApp = {
        dataset: {
            productIds: '[11]',
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
        createDocumentFragment() { return makeNode(); },
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
