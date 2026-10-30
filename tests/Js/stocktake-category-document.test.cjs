const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const file = path.join(__dirname,'../../public/js/stocktake-category-document.js');
const source = fs.existsSync(file) ? fs.readFileSync(file,'utf8') : '';

function node(tag='div') {
    const n={tag,children:[],dataset:{},value:'',textContent:'',hidden:false,disabled:false,validationMessage:'',
        classList:{add(){},remove(){},toggle(){}},
        append(...children){this.children.push(...children);},replaceChildren(...children){this.children=children;},
        setAttribute(){},setCustomValidity(value){this.validationMessage=value;},focus(){},
        addEventListener(name,fn){this['on_'+name]=fn;},
        closest(selector){return selector==='[data-count]' && this.dataset.count ? this : null;},
        querySelectorAll(selector){const found=[]; const walk=x=>{if(selector==='[data-count]'&&x.dataset?.count)found.push(x);for(const c of x.children||[])walk(c);};walk(this);return found;},
    };
    return n;
}
function fixture({seed={},storage=new Map(),postHook,getHook,paged=false,reject=false,confirm=true}={}) {
    const ids=['categoryCountApp','categoryDocumentSeed','cdCategory','cdSubcategory','cdShow','cdSearch','cdRows','cdMore','cdLoadAll','cdSave','cdStatus','cdSummary','cdRecovery','cdRestore','cdDiscard'];
    const nodes=Object.fromEntries(ids.map(id=>[id,node()]));
    const data={id:null,category_id:null,category_name:'Airpod',review_token:null,items:[],user_id:1,
        rows_url:'/stock-count-documents/category-variants',save_url:'/stock-count-documents/category-document',...seed};
    nodes.categoryDocumentSeed.textContent=JSON.stringify(data);
    nodes.categoryCountApp.dataset.subcategoriesUrl='/stock-count-documents/subcategories';
    nodes.cdCategory.value='3';
    const requests=[],navigations=[],listeners={},unloadListeners=[],navigationPrompts=[];
    const rows=[{variant_id:101,product_id:7,product_name:'Airpod',name:'Black',sku:'A1',system_available:8,active_reserved:0,expected_physical:8,queue_total:0},
        {variant_id:102,product_id:8,product_name:'Cable',name:'White',sku:'C1',system_available:8,active_reserved:0,expected_physical:8,queue_total:0}];
    const ctx={document:{getElementById:id=>nodes[id],querySelector:()=>({content:'csrf'}),createElement:node,addEventListener:(name,fn)=>listeners[name]=fn},
        window:{confirm:()=>confirm,addEventListener(name,handler){if(name==='beforeunload')unloadListeners.push(handler);}},
        location:{origin:'https://erp.example.test',assign:url=>{
            const event={preventDefault(){navigationPrompts.push(url);},returnValue:undefined};
            for(const handler of unloadListeners)handler(event);
            navigations.push(url);
        }},
        URL,AbortController,setTimeout,clearTimeout,crypto:require('node:crypto').webcrypto,
        sessionStorage:{getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value),removeItem:key=>storage.delete(key)},
        fetch:async(url,options={})=>{
            const request={url:String(url),method:options.method||'GET',payload:options.body?JSON.parse(options.body):null};requests.push(request);
            if(request.method==='POST') {
                const intercepted=await postHook?.(request);if(intercepted)return intercepted;
                if(reject)return {ok:false,status:422,json:async()=>({message:'Invalid scope'})};
                return {ok:true,status:200,json:async()=>({document_id:55,view_url:'/stock-count-documents/55/view',review_token:'new-token',document:{id:55}})};
            }
            const intercepted=await getHook?.(request);if(intercepted)return intercepted;
            const parsed=new URL(url,'https://erp.example.test');
            const cursor=Number(parsed.searchParams.get('after_id')||0);
            const q=parsed.searchParams.get('q')||'';
            const matches=rows.filter(row=>(!q||row.product_id===8)&&(!paged||row.variant_id>cursor));
            const page=paged?matches.slice(0,1):matches;
            return {ok:true,status:200,json:async()=>({rows:page,has_more:paged&&matches.length>1,next_cursor:page.at(-1)?.variant_id||cursor})};
        }};
    vm.runInNewContext(source,ctx);
    assert.ok(listeners.DOMContentLoaded,'category document application exists');listeners.DOMContentLoaded();
    const inputs=()=>nodes.cdRows.querySelectorAll('[data-count]');
    const enter=(input,value)=>{input.value=value;nodes.cdRows.on_input({target:input});};
    return {nodes,requests,navigations,navigationPrompts,inputs,enter,storage};
}
async function settle(predicate){for(let i=0;i<100&&!predicate();i++)await new Promise(resolve=>setImmediate(resolve));assert.ok(predicate());}

test('whole category is visibly zero by default and save opens ONE document review without finalizing',async()=>{
    const ui=fixture({paged:true});await ui.nodes.cdShow.on_click();
    assert.equal(ui.inputs()[0].value,'0');assert.equal(ui.nodes.cdSave.disabled,false);
    await ui.nodes.cdSave.on_click();
    const writes=ui.requests.filter(r=>r.method==='POST');assert.equal(writes.length,1);
    assert.equal(writes[0].payload.category_id,3);assert.equal(writes[0].payload.actual_quantities['101'],'0');
    assert.match(writes[0].payload.request_id,/^[0-9a-f-]{36}$/);
    assert.equal(ui.navigations[0],'https://erp.example.test/stock-count-documents/55/view');
    assert.ok(!ui.requests.some(r=>r.url.includes('finalize')));
});
test('blank means explicit zero in this category-only form and hidden positive counts survive live search',async()=>{
    const ui=fixture();await ui.nodes.cdShow.on_click();ui.enter(ui.inputs()[0],'۵');ui.enter(ui.inputs()[1],'');
    assert.equal(ui.inputs()[1].value,'0');ui.nodes.cdSearch.value='Cable';ui.nodes.cdSearch.on_input();
    await new Promise(resolve=>setTimeout(resolve,350));await settle(()=>ui.inputs().length===1);
    await ui.nodes.cdSave.on_click();const payload=ui.requests.find(r=>r.method==='POST').payload;
    assert.equal(payload.actual_quantities['101'],'5');assert.equal(payload.actual_quantities['102'],'0');
});
test('server-resumable edit seed retains all quantities and original document identity/version without browser storage',async()=>{
    const ui=fixture({seed:{id:19,category_id:3,review_token:'server-version',items:[{variant_id:101,actual_quantity:6},{variant_id:102,actual_quantity:0}]}});
    await settle(()=>ui.inputs().length===2);assert.equal(ui.inputs()[0].value,'6');
    assert.equal(ui.nodes.cdCategory.disabled,true);ui.enter(ui.inputs()[1],'2');await ui.nodes.cdSave.on_click();
    const payload=ui.requests.find(r=>r.method==='POST').payload;assert.equal(payload.document_id,19);
    assert.equal(payload.expected_review_token,'server-version');assert.equal(payload.actual_quantities['101'],'6');
});
test('invalid count blocks save even when live search hides the invalid row',async()=>{
    const ui=fixture();await ui.nodes.cdShow.on_click();ui.enter(ui.inputs()[0],'-2');
    ui.nodes.cdSearch.value='Cable';ui.nodes.cdSearch.on_input();await new Promise(resolve=>setTimeout(resolve,350));
    assert.equal(ui.nodes.cdSave.disabled,true);await ui.nodes.cdSave.on_click();assert.equal(ui.requests.filter(r=>r.method==='POST').length,0);
});
test('lost save reply locks inputs and retry carries the identical UUID/payload before review navigation',async()=>{
    let calls=0;const ui=fixture({postHook:async()=>{if(++calls===1)throw new Error('Response lost');}});
    await ui.nodes.cdShow.on_click();ui.enter(ui.inputs()[0],'2');await ui.nodes.cdSave.on_click();
    assert.ok(ui.inputs().every(x=>x.disabled));await ui.nodes.cdSave.on_click();
    const writes=ui.requests.filter(r=>r.method==='POST');assert.deepEqual(writes[0].payload,writes[1].payload);assert.equal(ui.navigations.length,1);
});
test('refresh recovery makes only a status GET and then explicitly retries the original single-document save',async()=>{
    const storage=new Map();const first=fixture({storage,postHook:async()=>{throw new Error('lost');}});
    await first.nodes.cdShow.on_click();first.enter(first.inputs()[0],'7');await first.nodes.cdSave.on_click();
    const original=first.requests.find(r=>r.method==='POST').payload;
    const next=fixture({storage,getHook:async r=>r.url.includes('/requests/')?{ok:false,status:404,json:async()=>({})}:undefined});
    assert.equal(next.nodes.cdRecovery.hidden,false);assert.equal(next.requests.length,0);
    await next.nodes.cdRestore.on_click();assert.equal(next.requests.filter(r=>r.method==='POST').length,0);
    assert.ok(next.requests.some(r=>r.url.includes('operation=unified_save')));
    await next.nodes.cdSave.on_click();assert.deepEqual(next.requests.find(r=>r.method==='POST').payload,original);
});
test('a definite server rejection preserves counts and does not navigate or finalize',async()=>{
    const ui=fixture({reject:true});await ui.nodes.cdShow.on_click();ui.enter(ui.inputs()[0],'4');await ui.nodes.cdSave.on_click();
    assert.equal(ui.inputs()[0].value,'4');assert.ok(ui.inputs().every(x=>!x.disabled));assert.equal(ui.navigations.length,0);
});
test('load all fetches sequential bounded pages and preserves entered counts',async()=>{
    const ui=fixture({paged:true});await ui.nodes.cdShow.on_click();ui.enter(ui.inputs()[0],'3');await ui.nodes.cdLoadAll.on_click();
    assert.equal(ui.inputs().length,2);assert.equal(ui.inputs()[0].value,'3');
    const gets=ui.requests.filter(r=>r.method==='GET');assert.ok(gets.every(r=>new URL(r.url,'https://erp.example.test').searchParams.get('limit')==='60'));
    assert.equal(new URL(gets[1].url,'https://erp.example.test').searchParams.get('after_id'),'101');
});
test('successful save cannot be submitted again while review navigation is pending',async()=>{
    const ui=fixture();await ui.nodes.cdShow.on_click();await ui.nodes.cdSave.on_click();
    await ui.nodes.cdSave.on_click();assert.equal(ui.requests.filter(r=>r.method==='POST').length,1);
});
test('successful save review navigation bypasses the unsaved-or-saving beforeunload guard',async()=>{
    const ui=fixture();await ui.nodes.cdShow.on_click();ui.enter(ui.inputs()[0],'3');
    await ui.nodes.cdSave.on_click();assert.equal(ui.navigations.length,1);
    assert.equal(ui.navigationPrompts.length,0,'the completed save must navigate without a leave-page prompt');
});
test('initial server edit load cannot overwrite an unsaved browser recovery snapshot before a restore choice',async()=>{
    const storage=new Map(),seed={id:19,category_id:3,review_token:'v1',items:[{variant_id:101,actual_quantity:6},{variant_id:102,actual_quantity:0}]};
    const first=fixture({seed,storage});await settle(()=>first.inputs().length===2);first.enter(first.inputs()[0],'3');
    const stored=[...storage.values()][0];const next=fixture({seed,storage});await settle(()=>next.inputs().length===2);
    assert.equal([...storage.values()][0],stored);assert.ok(next.inputs().every(input=>input.disabled));
    await next.nodes.cdRestore.on_click();assert.equal(next.inputs()[0].value,'3');
    assert.equal(next.requests.filter(r=>r.method==='POST').length,0);
});
