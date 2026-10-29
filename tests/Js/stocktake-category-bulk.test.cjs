const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../public/js/stocktake-category-workspace.js'), 'utf8');

function createNode(tag='div') {
    const children=[];
    return {
        tag,children,dataset:{},value:'',textContent:'',disabled:false,hidden:false,
        className:'',
        classList:{toggle(){},add(){},remove(){}},
        append(...elements){children.push(...elements)},
        appendChild(element){children.push(element)},
        replaceChildren(...elements){children.splice(0,children.length,...elements)},
        setAttribute(){},
        checkValidity(){return true},
        addEventListener(name,cb){this['on_'+name]=cb},
        querySelectorAll(selector){
            const found=[];
            const walk = element => {
                if(selector==='.sc-actual' && element.className?.includes('sc-actual')) found.push(element);
                if(selector==='[data-difference-for]' && element.dataset?.differenceFor) found.push(element);
                for(const child of element.children||[]) walk(child);
            };
            walk(this);
            return found;
        },
    };
}
function setup() {
    const ids=['stocktakeCategoryApp','category','subcategory','scShow','scMore','scStatus',
        'scProducts','scLiveSearch','scClose','stocktakeForm','scSaveAll','scFinalizeAll','scBulkStatus'];
    const nodes=Object.fromEntries(ids.map(id=>[id,createNode()]));
    const date=createNode('input');date.value='2026-10-29';
    const app=nodes.stocktakeCategoryApp;
    app.dataset.embedded='1';
    app.dataset.bulkStoreUrl='/category-bulk';
    app.dataset.bulkUpdateTemplate='/category/__DOC__';
    app.dataset.bulkFinalizeTemplate='/category/__DOC__/finalize';
    app.dataset.indexUrl='/stock-count-documents';
    app.dataset.rowsUrl='/category-rows';
    app.dataset.canFinalize='1';
    app.dataset.bulkDocumentId='';
    nodes.category.value='5';
    nodes.stocktakeForm.dataset={};
    const events={};
    const requests=[];
    const navigations=[];
    const document={
        addEventListener(name,cb){events[name]=cb},
        getElementById(id){return nodes[id]||null},
        querySelector(selector){
            if(selector==='[name="document_date"]')return date;
            if(selector==='meta[name="csrf-token"]')return {content:'csrf'};
            return null;
        },
        querySelectorAll(){return []},
        createElement(tag){return createNode(tag)},
    };
    const rows=[
        {product_id:1,variant_id:11,product_name:'ایرپاد',name:'مدل اول',
            expected_physical:5,system_available:5,active_reserved:0,queue_total:0},
        {product_id:2,variant_id:22,product_name:'ایرپاد پرو',name:'مدل دوم',
            expected_physical:8,system_available:8,active_reserved:0,queue_total:0},
    ];
    const context={
        document,window:{addEventListener(){},confirm(){return true}},
        location:{origin:'https://example.invalid',assign(url){navigations.push(url)}},
        history:{replaceState(...args){navigations.push(args.at(-1))}},
        crypto:{randomUUID(){return 'ed9981c5-dc02-43bf-a4a9-a4f021bf3925'}},
        URL,AbortController,Intl,Number,Map,Promise,setTimeout,clearTimeout,
        fetch:async(url,options={})=>{
            requests.push({url,method:options.method||'GET',data:options.body?JSON.parse(options.body):null});
            if(options.method==='POST') return {ok:true,async json(){return {
                document_id:123,status:'draft',edit_url:'/category/123/resume',
            }}};
            if(options.method==='PATCH') return {ok:true,async json(){return {
                document_id:123,status:'finalized',
            }}};
            return {ok:true,async json(){return {
                rows,has_more:false,next_cursor:22,
            }}};
        },
    };
    vm.runInNewContext(source,context);
    events.DOMContentLoaded();
    return {nodes,requests,navigations};
}
async function waitFor(predicate) {
    for(let i=0;i<50&&!predicate();i++)await new Promise(resolve=>setImmediate(resolve));
    assert.ok(predicate(),'state did not reach expected value');
}

test('one bulk draft request and one final confirmation cover two distinct products',async()=>{
    const {nodes,requests,navigations}=setup();
    assert.equal(nodes.scSaveAll.disabled,false); // state changes after list loads
    await nodes.scShow.on_click();
    await waitFor(()=>nodes.scProducts.querySelectorAll('.sc-actual').length===2);
    const [first,second]=nodes.scProducts.querySelectorAll('.sc-actual');
    first.value='۷'; first.closest=()=>first;
    nodes.scProducts.on_input({target:first});
    second.value='۰'; second.closest=()=>second;
    nodes.scProducts.on_input({target:second});
    assert.equal(nodes.scSaveAll.disabled,false);
    assert.equal(nodes.scFinalizeAll.disabled,true);
    await nodes.scSaveAll.on_click();
    await waitFor(()=>requests.some(r=>r.method==='POST'));
    const saved=requests.find(r=>r.method==='POST');
    assert.equal(saved.url,'/category-bulk');
    assert.equal(saved.data.category_id,5);
    assert.equal(saved.data.actual_quantities[11],7);
    assert.equal(saved.data.actual_quantities[22],0);
    assert.equal(requests.filter(r=>r.method==='POST').length,1);
    assert.equal(nodes.scFinalizeAll.disabled,false);
    await nodes.scFinalizeAll.on_click();
    await waitFor(()=>requests.some(r=>r.method==='PATCH'));
    const finalized=requests.find(r=>r.method==='PATCH');
    assert.equal(finalized.url,'/category/123/finalize');
    assert.equal(finalized.data.actual_quantities[22],0);
    assert.ok(navigations.includes('/stock-count-documents'));
    assert.equal(requests.filter(r=>r.method==='POST').length,1);
});

test('category view has no per-product save button when embedded in the main form',async()=>{
    const {nodes}=setup();
    await nodes.scShow.on_click();
    await waitFor(()=>nodes.scProducts.children.length===2);
    const buttons=nodes.scProducts.children.flatMap(card=>card.children)
        .flatMap(wrapper=>wrapper.children||[])
        .filter(child=>child?.dataset?.saveProduct);
    assert.equal(buttons.length,0);
});
