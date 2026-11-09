const test = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { runInNewContext } = require('node:vm');
const script = readFileSync(require('node:path').join(__dirname,'../../public/js/invoices-index.js'),'utf8');

class Element {
    constructor(){this.value='';this.dataset={};this.hidden=false;this.disabled=true;this.textContent='';this.innerHTML='';this.listeners={};this.classList={toggle(){}};}
    addEventListener(name,fn){this.listeners[name]=fn;}
    fire(name){return this.listeners[name]?.({target:this});}
    setAttribute(){} focus(){} insertAdjacentHTML(where,html){this.innerHTML+=html;}
    querySelectorAll(){return [];}
}
function mount(){
    const nodes=new Map(), get=id=>{if(!nodes.has(id))nodes.set(id,new Element());return nodes.get(id);};
    const app=get('invoiceLiveApp');app.dataset={endpoint:'/invoices/data',indexUrl:'/invoices',customersEndpoint:'/invoices/customers/search',printUrl:'/invoices/list/print',excelUrl:'/invoices/list/excel'};
    app.querySelector=key=>get(key);get('invoiceInitialState').textContent=JSON.stringify({filters:{customer_id:'14',seller_id:'3',date_from:'1405/08/01',date_to:'1405/08/18'}});
    const downloads=[],prints=[],requests=[],timers=[];let pending;
    const location={origin:'https://example.test',search:'',assign:url=>downloads.push(url)};
    runInNewContext(script,{
        document:{getElementById:get,addEventListener(){}},URL,URLSearchParams,AbortController,
        location,history:{replaceState(){}},window:{location,open:(...args)=>prints.push(args),addEventListener(){}},
        setTimeout:fn=>{timers.push(fn);return timers.length;},clearTimeout(){},
        fetch:url=>{requests.push(url);return new Promise(resolve=>pending=resolve);},
    });
    return {get,prints,downloads,requests,timers,reply:async(ok=true)=>{pending({ok,status:ok?200:422,json:async()=>({desktop_html:'<tr></tr>',mobile_html:'',summary_html:'',has_more:false,filters:{},errors:{date_from:['تاریخ نامعتبر']}})});await new Promise(setImmediate);}};
}

test('both reports use all current filters and omit screen pagination',async()=>{
    const ui=mount();assert.equal(ui.get('invoicePrintList').disabled,true);await ui.reply();
    assert.equal(ui.get('invoicePrintList').disabled,false);ui.get('invoicePrintList').fire('click');ui.get('invoiceExportExcel').fire('click');
    for(const target of [ui.prints[0][0],ui.downloads[0]]){
        const url=new URL(target);assert.equal(url.searchParams.get('customer_id'),'14');assert.equal(url.searchParams.get('seller_id'),'3');
        assert.equal(url.searchParams.get('date_from'),'1405/08/01');assert.equal(url.searchParams.get('date_to'),'1405/08/18');
        assert.equal(url.searchParams.has('cursor'),false);assert.equal(url.searchParams.has('limit'),false);
    }
    assert.equal(ui.prints[0][2],'noopener');
});

test('changing filters disables reports until their results validate; a stale response cannot enable them',async()=>{
    const ui=mount();ui.get('invoiceDateFrom').value='bad';ui.get('invoiceDateFrom').fire('input');await ui.reply();
    assert.equal(ui.get('invoiceExportExcel').disabled,true);
    ui.timers.at(-1)();await ui.reply(false);assert.equal(ui.get('invoiceExportExcel').disabled,true);
    ui.get('invoiceDateFrom').value='1405/08/02';ui.get('invoiceDateFrom').fire('input');ui.timers.at(-1)();await ui.reply();
    assert.equal(ui.get('invoiceExportExcel').disabled,false);
});
