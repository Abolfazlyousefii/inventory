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
        const params = new URL(url).searchParams;
        const cursor = params.get('after_id');
        if (params.get('q') === 'White') {
            return {ok:true, json:async()=>({
                rows:[{product_id:1,product_name:'AirPods',variant_id:102,name:'White',sku:'X2',
                    system_available:3,active_reserved:0,expected_physical:3,queue_total:0}],
                next_cursor:102, has_more:false,
            })};
        }
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

    // Filtering must not silently discard input from a variant not in the results.
    const search = el('scLiveSearch');
    search.value = 'White';
    search.listeners.input();
    await new Promise(resolve => setTimeout(resolve, 400));
    const filtered = el('scProducts').querySelectorAll('.sc-actual');
    assert.equal(filtered.length,1, 'only the matching variant should remain visible');
    assert.equal(filtered[0].dataset.variant,'102');
    assert.equal(filtered[0].value,'5', 'the searched count must be preserved');

    search.value = '';
    search.listeners.input();
    await new Promise(resolve => setTimeout(resolve, 400));
    await el('scMore').listeners.click();
    const restored = el('scProducts').querySelectorAll('.sc-actual');
    assert.equal(restored.length,2, 'the full category should return');
    assert.equal(restored[0].value,'0', 'zero input survives filtering');
    assert.equal(restored[1].value,'5', 'positive input survives filtering');
    assert.equal(el('scFinalizeAll').disabled,true, 'filtering must not finalize stock');
    await el('scSaveAll').listeners.click();
    assert.equal(requests.filter(x=>x.method === 'POST').length,1,
        'saving a draft sends only one POST');
    assert.equal(requests.filter(x=>x.method !== 'GET' && x.method !== 'POST').length,0,
        'no finalization or stock write request may be sent');
    assert.ok(requests.filter(x=>x.method === 'GET').length >= 5,
        'pagination and search remain read-only requests');
    assert.equal(el('scFinalizeAll').disabled,true, 'saving a draft is not finalization');

    assert.equal(sentDraft.category_id,11);
    assert.equal(sentDraft.products.length,1);
    assert.equal(sentDraft.products[0].product_id,1);
    assert.deepEqual(JSON.parse(JSON.stringify(sentDraft.products[0].actual_quantities)),
        {'101':'0','102':'5'});
    assert.equal(root.hidden,false);
});


function zeroCountWorkspace({confirm = () => true, failAfter = null, snapshot = null} = {}) {
    const ids = new Map();
    const el = id => {
        if (!ids.has(id)) ids.set(id, new Element());
        return ids.get(id);
    };
    const date = new Element('input');
    date.value = '2026-11-15';
    el('category').value = '10';
    el('subcategory').value = '11';
    Object.assign(el('stocktakeCategoryApp').dataset, {
        embedded:'1', userId:'7', rowsUrl:'https://example.test/category-variants',
        bulkStoreUrl:'https://example.test/category-drafts', bulkFinalizeUrl:'https://example.test/category-finalize',
    });
    const rows = [
        {product_id:1, product_name:'Cable', variant_id:101, name:'Black'},
        {product_id:1, product_name:'Cable', variant_id:102, name:'White'},
        {product_id:1, product_name:'Cable', variant_id:103, name:'Green'},
        {product_id:2, product_name:'Charger', variant_id:104, name:'USB'},
        {product_id:2, product_name:'Charger', variant_id:105, name:'Type C'},
    ].map(row => ({...row, system_available:9, active_reserved:0, expected_physical:9, queue_total:0}));
    const storage = new Map();
    const storageKey = 'inventory.stocktake.workspace.v1:7';
    if (snapshot) storage.set(storageKey,JSON.stringify(snapshot(rows)));
    const requests = [], confirmations = [];
    let fail = failAfter, draft = null, beforeGet = null;
    const document = {
        createElement:tag => new Element(tag), getElementById:el, listeners:{},
        addEventListener(name, cb) {this.listeners[name] = cb;},
        querySelector(selector) {
            if (selector === '[name="document_date"]') return date;
            if (selector === 'meta[name=csrf-token]') return {content:'csrf'};
            return null;
        },
        querySelectorAll:() => [],
    };
    const context = {document, URL, AbortController, location:{origin:'https://example.test'},
        setTimeout, clearTimeout, crypto:{randomUUID:()=>'00000000-0000-4000-a000-000000000007'},
        sessionStorage:{getItem:key=>storage.get(key) ?? null, setItem:(key,value)=>storage.set(key,value), removeItem:key=>storage.delete(key)},
        window:{addEventListener(){},confirm(message){confirmations.push(message);return confirm(message);}},
        fetch:async (input, options={}) => {
            const url = new URL(input);
            requests.push({url,method:options.method || 'GET'});
            if (options.method === 'POST') {
                assert.match(url.pathname,/category-drafts$/, 'zeroing must not finalize stock');
                draft = JSON.parse(options.body);
                return {ok:true,json:async()=>({documents:draft.products.map(product=>({
                    product_id:product.product_id,document_id:90+product.product_id,
                    edit_url:'https://example.test/documents/'+product.product_id,
                    variants_count:rows.filter(row=>row.product_id === product.product_id).length,
                    counted_count:Object.keys(product.actual_quantities).length,review_token:'saved-token',
                }))})};
            }
            if (beforeGet) await beforeGet(url);
            const cursor = Number(url.searchParams.get('after_id'));
            if (fail !== null && cursor >= fail) throw new Error('network disconnected');
            const matching = rows.filter(row => row.variant_id > cursor &&
                (!url.searchParams.get('q') || row.name === url.searchParams.get('q')));
            const page = matching.slice(0,2);
            return {ok:true,json:async()=>({rows:page,has_more:matching.length>2,
                next_cursor:page.at(-1)?.variant_id || cursor})};
        }};
    vm.runInNewContext(script,context);
    document.listeners.DOMContentLoaded();
    const fields = () => el('scProducts').querySelectorAll('.sc-actual');
    return {el,requests,confirmations,storage,storageKey,fields,
        input(variant,value){const field=fields().find(field=>field.dataset.variant === String(variant));
            field.value=value;el('scProducts').listeners.input({target:field});},
        get draft(){return draft;}, setFailure(value){fail=value;}, beforeGet(cb){beforeGet=cb;},
    };
}

test('zero uncounted loads every category page, preserves existing counts and sends no stock write', async () => {
    const w = zeroCountWorkspace();
    assert.equal(w.el('scZeroUncounted').disabled,true);
    await w.el('scShow').listeners.click();
    w.input(101,'۵');
    w.input(102,'۰');
    await w.el('scZeroUncounted').listeners.click();
    assert.deepEqual(w.fields().map(field=>field.value),['5','0','0','0','0']);
    assert.deepEqual(w.requests.map(request=>request.method),['GET','GET','GET','GET']);
    assert.ok(w.requests.every(request=>request.url.searchParams.get('category_id') === '11'));
    assert.equal(w.confirmations.length,1);
    assert.match(w.confirmations[0],/۳/);
    assert.equal(w.el('scFinalizeAll').disabled,true);
    const stored = JSON.parse(w.storage.get(w.storageKey));
    assert.deepEqual(stored.entered,[[1,[[101,'5'],[102,'0'],[103,'0']]],[2,[[104,'0'],[105,'0']]]]);
    await w.el('scSaveAll').listeners.click();
    assert.deepEqual(w.draft.products.map(product=>product.actual_quantities),[
        {'101':'5','102':'0','103':'0'},{'104':'0','105':'0'},
    ]);
    assert.equal(w.el('scFinalizeAll').disabled,true,'saving still requires a separate review');
    assert.equal(w.requests.filter(request=>request.method === 'POST').length,1);
});

test('cancelled zero confirmation does not overwrite blanks or entered counts', async () => {
    const w = zeroCountWorkspace({confirm:()=>false});
    await w.el('scShow').listeners.click();w.input(101,'8');
    await w.el('scZeroUncounted').listeners.click();
    assert.deepEqual(w.fields().map(field=>field.value),['8','','','','']);
    assert.ok(w.requests.every(request=>request.method === 'GET'));
});

test('failed category fetch does not partially zero anything and retry keeps previous input', async () => {
    const w = zeroCountWorkspace({failAfter:102});
    await w.el('scShow').listeners.click();w.input(101,'4');
    await w.el('scZeroUncounted').listeners.click();
    assert.deepEqual(w.fields().map(field=>field.value),['4','']);
    assert.equal(w.confirmations.length,0);
    assert.equal(w.el('scZeroUncounted').disabled,false);
    assert.match(w.el('scStatus').textContent,/هیچ مقدار خالی صفر نشد/);
    w.setFailure(null);
    await w.el('scZeroUncounted').listeners.click();
    assert.deepEqual(w.fields().map(field=>field.value),['4','0','0','0','0']);
});

test('zeroing from a search includes hidden uncounted variants while keeping hidden entered values', async () => {
    const w = zeroCountWorkspace();
    await w.el('scShow').listeners.click();w.input(101,'7');
    w.el('scLiveSearch').value='White';w.el('scLiveSearch').listeners.input();
    await new Promise(resolve=>setTimeout(resolve,400));
    assert.deepEqual(w.fields().map(field=>field.dataset.variant),['102']);
    await w.el('scZeroUncounted').listeners.click();
    assert.equal(w.el('scLiveSearch').value,'');
    assert.deepEqual(w.fields().map(field=>field.value),['7','0','0','0','0']);
});

test('zeroing leaves invalid entered values visible and keeps saving blocked', async () => {
    const w = zeroCountWorkspace();
    await w.el('scShow').listeners.click();w.input(101,'-1');
    await w.el('scZeroUncounted').listeners.click();
    assert.deepEqual(w.fields().map(field=>field.value),['-1','0','0','0','0']);
    assert.equal(w.el('scSaveAll').disabled,true);
});

test('zeroing skips finalized products recovered from the existing browser workspace', async () => {
    const w = zeroCountWorkspace({snapshot:rows=>({version:1,ownerId:'7',selectionId:11,
        scopeCategory:'10',scopeSubcategory:'11',date:'2026-11-15',rows:rows.slice(0,3),
        entered:[[1,[[101,'6']]]],metadata:[],knownProducts:[[1,'Cable']],
        finalized:[1],dirtyProducts:[],invalidCounts:[],saved:[],pendingOperation:null,
    })});
    await w.el('scRestore').listeners.click();
    await w.el('scZeroUncounted').listeners.click();
    assert.deepEqual(w.fields().map(field=>field.value),['6','','','0','0']);
    assert.ok(w.fields().slice(0,3).every(field=>field.disabled));
});

test('double clicks and edits cannot interleave with full-category zeroing', async () => {
    const w = zeroCountWorkspace();
    await w.el('scShow').listeners.click();
    let release;const waiting=new Promise(resolve=>{release=resolve;});
    let intercepted=false;
    w.beforeGet(async () => {if(!intercepted){intercepted=true;await waiting;}});
    const operation=w.el('scZeroUncounted').listeners.click();
    assert.equal(w.el('scZeroUncounted').disabled,true);
    assert.equal(w.el('scSaveAll').disabled,true);
    assert.equal(w.el('scShow').disabled,true);
    await w.el('scZeroUncounted').listeners.click();
    await w.el('scShow').listeners.click();
    release();await operation;
    assert.equal(w.confirmations.length,1);
    assert.deepEqual(w.fields().map(field=>field.value),['0','0','0','0','0']);
    const count=w.requests.length;
    await w.el('scZeroUncounted').listeners.click();
    assert.equal(w.requests.length,count,'no requests when there are no remaining blanks');
});
