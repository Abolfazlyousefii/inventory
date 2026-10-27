const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const script = fs.readFileSync(path.join(__dirname, '../../public/js/warehouse-stock-flow.js'),'utf8');

function fixture(fetchResult) {
    const events = {};
    const node = () => ({
        children:[],textContent:'',disabled:false,hidden:false,
        classList:{toggle() {}},
        append(...xs) { this.children.push(...xs); },
        appendChild(x) { this.children.push(...(x.fragment ? x.children : [x])); },
        addEventListener(name,fn) { this['on'+name]=fn; },
    });
    const ids=['stockFlowRows','stockFlowMessage','stockFlowLoaded','stockFlowMore',
        'stockFlowPrint','stockFlowCancel','stockFlowApp'];
    const dom=Object.fromEntries(ids.map(id=>[id,node()]));
    dom.stockFlowApp.dataset={
        rowsUrl:'/inventory-stock-flow/rows',q:'',onlyInFlow:'1',print:'1',
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
