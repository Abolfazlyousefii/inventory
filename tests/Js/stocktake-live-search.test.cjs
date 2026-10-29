const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const script = fs.readFileSync(path.join(__dirname, '../../public/js/stocktake-category-workspace.js'), 'utf8');

function node(tag = 'div') {
    const children = [];
    const n = {
        tag, children, dataset: {}, value: '', textContent: '', hidden: false, disabled: false,
        classList: {toggle() {}, add() {}, remove() {}},
        append(...nodes) {children.push(...nodes);},
        appendChild(item) {children.push(item);},
        replaceChildren(...nodes) {children.splice(0,children.length,...nodes);},
        setAttribute() {},
        addEventListener(name,cb) {this['on_'+name] = cb;},
        querySelectorAll(selector) {
            const matches=[];
            const look = item => {
                if (selector === '.sc-actual' && item.className?.includes('sc-actual')) matches.push(item);
                if (selector === '[data-difference-for]' && item.dataset?.differenceFor) matches.push(item);
                for(const child of item.children || []) look(child);
            };
            look(this);
            return matches;
        },
    };
    return n;
}
function fixture() {
    const nodes = {};
    for(const id of ['stocktakeCategoryApp','category','subcategory','scShow','scMore','scStatus',
        'scProducts','scLiveSearch','scClose','stocktakeForm']) nodes[id] = node();
    nodes.stocktakeCategoryApp.dataset.embedded = '1';
    nodes.category.value = '3';
    nodes.subcategory.value = '';
    nodes.scMore.hidden = true;
    nodes.stocktakeForm.dataset = {};
    nodes.scLiveSearch.value = '';
    const date = node('input');
    date.value = '2026-10-29';
    const requests=[];
    const listeners={};
    const document = {
        addEventListener(name,fn) {listeners[name] = fn;},
        getElementById(id) {return nodes[id];},
        querySelector(selector) {
            if (selector === '[name="document_date"]') return date;
            if (selector === 'meta[name=csrf-token]') return {content:'token'};
            return null;
        },
        querySelectorAll() {return []},
        createElement(tag) {return node(tag)},
    };
    nodes.stocktakeCategoryApp.dataset.rowsUrl = '/stock-count-documents/category/variants';
    nodes.stocktakeCategoryApp.dataset.storeUrl = '/stock-count-documents';
    const row = (id, name) => ({
        product_id:id, product_name:name, variant_id:id*10, name:name+' مشکی', sku:'SKU'+id,
        system_available:5, active_reserved:0, expected_physical:5, queue_total:0,
        pending_collection:0, warehouse_received:0, collecting:0,
    });
    const items = [row(1,'ایرپاد'),row(2,'گارد')];
    const context = {
        document, location:{origin:'https://example.invalid'}, URL,AbortController,Intl,Number,
        setTimeout,clearTimeout,window:{addEventListener(){},confirm(){return true;}},
        fetch:async (url,opts={})=>{
            requests.push(url);
            if (opts.method === 'POST') return {ok:true,async json(){return {document_id:9,edit_url:'/draft/9'}}};
            const u=new URL(url,'https://example.invalid'), term=u.searchParams.get('q')||'';
            const rows=items.filter(x => !term || x.product_name.includes(term));
            return {ok:true,async json(){return {rows,has_more:false,next_cursor:rows.at(-1)?.variant_id||0}}};
        },
    };
    vm.runInNewContext(script,context);
    listeners.DOMContentLoaded();
    const findField = () => {
        const fields=nodes.scProducts.querySelectorAll('.sc-actual');
        return fields[0];
    };
    return {nodes,requests,findField};
}
async function until(predicate) {
    for(let i=0;i<75&&!predicate();i++) await new Promise(resolve=>setTimeout(resolve,12));
    assert.ok(predicate(),'expected live search state was not reached');
}
test('zero count is not lost when live search filters out then restores an item',async()=>{
    const {nodes,requests,findField}=fixture();
    await nodes.scShow.on_click();
    await until(()=>nodes.scProducts.children.length===2);
    let input=findField();
    input.value='۰';
    input.closest=()=>input;
    nodes.scProducts.on_input({target:input});
    assert.equal(input.value,'0');

    nodes.scLiveSearch.value='گارد';
    nodes.scLiveSearch.on_input();
    await until(()=>requests.some(u=>u.includes('q=%DA%AF%D8%A7%D8%B1%D8%AF')));
    await until(()=>nodes.scProducts.children.length===1);
    assert.equal(findField().dataset.product,'2');

    nodes.scLiveSearch.value='';
    nodes.scLiveSearch.on_input();
    await until(()=>nodes.scProducts.children.length===2);
    assert.equal(findField().value,'0');
    assert.equal(findField().disabled,false);
});
