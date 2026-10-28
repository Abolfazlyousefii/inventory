const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const script = fs.readFileSync(path.join(__dirname, '../../public/js/warehouse-stock-flow.js'),'utf8');

function fixture(fetchResult, options = {}) {
    const events = {};
    const node = () => ({
        children:[],textContent:'',disabled:false,hidden:false,
        classList:{toggle() {}},
        append(...xs) { this.children.push(...xs); },
        appendChild(x) { this.children.push(...(x.fragment ? x.children : [x])); },
        replaceChildren(...xs) { this.children = xs; },
        focus() {},
        setAttribute(name, value) { this[name] = value; },
        addEventListener(name,fn) { this['on'+name]=fn; },
    });
    const ids=['stockFlowRows','stockFlowMessage','stockFlowLoaded','stockFlowMore',
        'stockFlowPrint','stockFlowCancel','stockFlowApp',
        'flowDetailBackdrop','flowDetailPanel','flowDetailHeading',
        'flowDetailDescription','flowDetailMessage','flowDetailRows',
        'flowDetailMore','flowDetailClose'];
    const dom=Object.fromEntries(ids.map(id=>[id,node()]));
    dom.flowDetailBackdrop.hidden=true;
    dom.stockFlowApp.dataset={
        rowsUrl:'/inventory-stock-flow/rows',
        detailsUrl:'/inventory-stock-flow/details/__VARIANT__',
        q:'',onlyInFlow:'1',print: options.print === false ? '0' : '1',
    };
    const calls={prints:0,queries:[]};
    const document={
        addEventListener(name,fn){events[name]=fn},
        getElementById(id){return dom[id]},
        createElement(){return node()},
        createDocumentFragment(){return Object.assign(node(),{fragment:true})},
    };
    const context={
        document,window:{print(){calls.prints++}},
        location:{origin:'https://example.test'},URL,AbortController,
        fetch:async (url)=>{
            calls.queries.push(url);
            const body=await fetchResult(url);
            return {ok:true,json:async()=>body};
        },
        Intl,Number,Promise,requestAnimationFrame:fn=>queueMicrotask(fn),
    };
    vm.runInNewContext(script,context);
    events.DOMContentLoaded();
    return {dom,calls};
}

async function poll(predicate) {
    for(let n=0;n<90&&!predicate();n++) await new Promise(resolve=>setImmediate(resolve));
    assert.ok(predicate(),'state was not reached');
}

test('PDF remains disabled until every queue batch is complete',async()=>{
    let finish;
    const later=new Promise(resolve=>{finish=resolve});
    const {dom,calls}=fixture(async url=>{
        const after=new URL(url).searchParams.get('after_id');
        if(after==='0') return {
            rows:[{variant_id:1,product_name:'گارد',available:200,official_reserved:0,
                pending_collection:100,warehouse_received:0,collecting:0,queue_total:100}],
            next_cursor:1,has_more:true,
        };
        return later;
    });
    dom.stockFlowPrint.onclick();
    assert.equal(calls.prints,0);
    await poll(()=>calls.queries.length===2);
    assert.equal(dom.stockFlowPrint.disabled,true);
    finish({rows:[{variant_id:2,product_name:'محافظ',available:20,official_reserved:2,
        pending_collection:1,warehouse_received:1,collecting:0,queue_total:2}],next_cursor:2,has_more:false});
    await poll(()=>dom.stockFlowPrint.disabled===false);
    assert.equal(dom.stockFlowRows.children.length,2);
    assert.equal(dom.stockFlowRows.children[0].children.length,9);
    assert.equal(dom.stockFlowRows.children[0].children[5].textContent,'۱۰۰');
    dom.stockFlowPrint.onclick();
    assert.equal(calls.prints,1);
});

test('invalid pagination disables print without using incomplete data',async()=>{
    const {dom,calls}=fixture(async()=>({
        rows:[],has_more:true,next_cursor:0,
    }));
    await poll(()=>dom.stockFlowMessage.classList !== undefined &&
        dom.stockFlowMessage.textContent.includes('صفحه‌بندی'));
    dom.stockFlowPrint.onclick();
    assert.equal(calls.prints,0);
    assert.equal(dom.stockFlowPrint.disabled,true);
});


test('opening queue number fetches document sources only on demand and shows customer and seller', async () => {
    const {dom,calls}=fixture(async url => {
        const address = new URL(url);
        if (address.pathname.endsWith('/rows')) return {
            rows:[{variant_id:10,product_name:'قاب سامسونگ',variant_name:'مشکی',
                available:200,official_reserved:4,pending_collection:30,
                warehouse_received:0,collecting:10,queue_total:40}],
            next_cursor:10,has_more:false,
        };
        assert.equal(address.pathname,'/inventory-stock-flow/details/10');
        assert.equal(address.searchParams.get('kind'),'queue');
        return {rows:[{document_id:7,document_number:'00087',
            document_url:'https://example.test/invoices/00087',
            customer_name:'مشتری نمونه',seller_name:'فروشنده نمونه',
            status_label:'در صف جمع‌آوری',quantity:40}],
            next_cursor:7,has_more:false};
    }, {print:false});
    await poll(()=>dom.stockFlowRows.children.length===1);
    assert.equal(calls.queries.length,1,'opening the report must not query invoices per row');
    const tr=dom.stockFlowRows.children[0];
    const queueCell=tr.children[8];
    assert.equal(queueCell.children.length,1);
    queueCell.children[0].onclick();
    await poll(()=>dom.flowDetailRows.children.length===1);
    assert.equal(calls.queries.length,2);
    assert.equal(dom.flowDetailBackdrop.hidden,false);
    const details=dom.flowDetailRows.children[0].children;
    assert.equal(details[0].textContent,'00087');
    assert.equal(details[1].textContent,'مشتری نمونه');
    assert.equal(details[2].textContent,'فروشنده نمونه');
    assert.equal(details[3].textContent,'در صف جمع‌آوری');
    assert.equal(details[4].textContent,'۴۰');
    assert.equal(details[5].children[0].href,'https://example.test/invoices/00087');
    dom.flowDetailClose.onclick();
    assert.equal(dom.flowDetailBackdrop.hidden,true);
});

test('official reservation opens a separate source and ignores untrusted document domains',async()=>{
    const {dom,calls}=fixture(async url=>{
        const address=new URL(url);
        if (address.pathname.endsWith('/rows')) return {
            rows:[{variant_id:20,product_name:'محافظ',available:10,
                official_reserved:3,pending_collection:0,warehouse_received:0,
                collecting:0,queue_total:0}],next_cursor:20,has_more:false,
        };
        assert.equal(address.searchParams.get('kind'),'official');
        return {rows:[{document_id:9,document_number:'PI-9',
            document_url:'https://untrusted.example/phish',
            customer_name:'مشتری دوم',seller_name:'فروشنده دوم',
            status_label:'در انتظار تایید مالی',quantity:3}],next_cursor:9,has_more:false};
    },{print:false});
    await poll(()=>dom.stockFlowRows.children.length===1);
    const tr=dom.stockFlowRows.children[0];
    assert.equal(tr.children[8].children.length,0);
    tr.children[4].children[0].onclick();
    await poll(()=>dom.flowDetailRows.children.length===1);
    assert.equal(calls.queries.length,2);
    assert.equal(dom.flowDetailRows.children[0].children[4].textContent,'۳');
    assert.equal(dom.flowDetailRows.children[0].children[5].children.length,0);
});
