const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.join(__dirname, '../../public/js/stocktake-category-workspace.js'), 'utf8');

class Element {
    constructor(tag = 'div') {
        this.tagName = tag;
        this.children = [];
        this.dataset = {};
        this.listeners = {};
        this.hidden = false;
        this.disabled = false;
        this.value = '';
        this.textContent = '';
        this.className = '';
        this.classList = {add() {}, remove() {}, toggle() {}};
    }
    append(...nodes) { this.children.push(...nodes); }
    appendChild(node) { this.append(node); }
    replaceChildren(...nodes) { this.children = [...nodes]; }
    setAttribute(name, value) { this[name] = value; }
    setCustomValidity(message) { this.validationMessage = message; }
    addEventListener(name, listener) { this.listeners[name] = listener; }
    matches(query) { return query === '.sc-actual' && this.className.split(' ').includes('sc-actual'); }
    closest(query) { return this.matches(query) ? this : null; }
    querySelectorAll(query) {
        const result = [];
        const visit = node => {
            for (const child of node.children || []) {
                if (query === '.sc-actual' && child.matches?.(query)) result.push(child);
                if (query === '[data-difference-for]' && child.dataset?.differenceFor !== undefined) result.push(child);
                visit(child);
            }
        };
        visit(this);
        return result;
    }
}

test('embedded category selector renders editable counts and saves 0 and positive values as a draft', async () => {
    const ids = new Map();
    const el = id => {
        if (!ids.has(id)) ids.set(id, new Element());
        return ids.get(id);
    };

    const originalForm = el('stocktakeForm');
    const pageCategory = el('category');
    const pageSubcategory = el('subcategory');
    const date = new Element('input');
    const singleProductControls = [new Element(), new Element()];
    pageCategory.value = '10';
    pageSubcategory.value = '11';
    date.value = '2026-11-05';

    const root = el('stocktakeCategoryApp');
    root.hidden = true;
    root.dataset.embedded = '1';
    root.dataset.rowsUrl = 'https://example.test/stock-count-documents/category-variants';
    root.dataset.bulkStoreUrl = 'https://example.test/stock-count-documents/category-drafts';
    root.dataset.bulkFinalizeUrl = 'https://example.test/stock-count-documents/category-finalize';
    root.dataset.storeUrl = 'https://example.test/stock-count-documents';
    root.dataset.userId = '';

    const document = {
        createElement: tag => new Element(tag),
        getElementById: el,
        listeners: {},
        addEventListener(name, cb) { this.listeners[name] = cb; },
        querySelector(selector) {
            if (selector === '[name="document_date"]') return date;
            if (selector === 'meta[name=csrf-token]') return {content:'csrf-test'};
            return null;
        },
        querySelectorAll(selector) { return selector === '[data-stocktake-single]' ? singleProductControls : []; },
    };
    let sentDraft;
    const requests = [];
    const fetch = async (input, options = {}) => {
        requests.push({url:String(input),method:options.method || 'GET'});
        const url = String(input);
        if (options.method === 'POST') {
            assert.match(url, /category-drafts/);
            sentDraft = JSON.parse(options.body);
            return {ok:true, json:async()=>({documents:[{
                product_id:1, document_id:90, edit_url:'https://example.test/stock-count-documents/90/edit',
                variants_count:2, counted_count:2, review_token:'token',
            }]})};
        }
        assert.match(url, /category-variants/);
        const cursor = new URL(url).searchParams.get('after_id');
        return {ok:true, json:async()=> cursor === '0' ? {
            rows:[{product_id:1,product_name:'AirPods',variant_id:101,name:'Black',sku:'X1',
                system_available:6,active_reserved:0,expected_physical:6,queue_total:0}],
            next_cursor:101, has_more:true,
        } : {
            rows:[{product_id:1,product_name:'AirPods',variant_id:102,name:'White',sku:'X2',
                system_available:3,active_reserved:0,expected_physical:3,queue_total:0}],
            next_cursor:102, has_more:false,
        }};
    };
    const context = {document, window:{addEventListener(){},confirm:()=>true},
        fetch, location:{origin:'https://example.test'}, URL, AbortController,
        crypto:{randomUUID:()=> '00000000-0000-4000-a000-000000000000'},
        setTimeout, clearTimeout};
    vm.runInNewContext(script, context);
    document.listeners.DOMContentLoaded();

    await el('scShow').listeners.click();
    assert.equal(root.hidden,false);
    assert.equal(originalForm.dataset.categoryMode,'1');
    assert.equal(singleProductControls.every(x=>x.hidden),true);
    assert.equal(el('scSaveAll').disabled,true, 'cannot save a partially loaded category');
    assert.equal(el('scFinalizeAll').disabled,true);
    assert.deepEqual(requests.map(x=>x.method), ['GET'], 'opening category is read-only');

    // The original product form is not allowed to submit a category count.
    const prevented = [];
    originalForm.listeners.submit({
        preventDefault:()=>prevented.push('prevented'),
        stopImmediatePropagation:()=>prevented.push('stopped'),
    });
    assert.deepEqual(prevented,['prevented','stopped']);

    await el('scMore').listeners.click();
    const inputs = el('scProducts').querySelectorAll('.sc-actual');
    assert.equal(inputs.length,2);
    assert.equal(inputs[0].type,'text');
    assert.equal(inputs[0].inputMode,'numeric');
    assert.equal(inputs[0].disabled,false);
    assert.equal(el('scSaveAll').disabled,false);
    assert.deepEqual(requests.map(x=>x.method), ['GET','GET'],
        'loading more variants must not save or finalize stock');

    // Negative counts must not leave the draft button enabled.
    inputs[0].value = '-1';
    el('scProducts').listeners.input({target:inputs[0]});
    assert.equal(el('scSaveAll').disabled,true, 'invalid number blocks saving');

    for (const [index, value] of [['0', 0], ['5', 1]]) {
        const field = inputs[value];
        field.value = index;
        el('scProducts').listeners.input({target:field});
    }
    assert.equal(el('scSaveAll').disabled,false, 'correcting the count permits saving');
    await el('scSaveAll').listeners.click();
    assert.deepEqual(requests.map(x=>x.method), ['GET','GET','POST'],
        'saving a draft sends only one POST, never a finalization request');
    assert.equal(el('scFinalizeAll').disabled,true, 'saving a draft is not finalization');

    assert.equal(sentDraft.category_id,11);
    assert.equal(sentDraft.products.length,1);
    assert.equal(sentDraft.products[0].product_id,1);
    assert.deepEqual(JSON.parse(JSON.stringify(sentDraft.products[0].actual_quantities)),
        {'101':'0','102':'5'});
    assert.equal(root.hidden,false);
});
