const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const vm = require('node:vm');

function setup() {
    const listeners = {}, rootListeners = {}, nodes = {};
    for (const key of ['[data-sale-code-panel]', '[data-sale-status]', '[data-sale-owner]', '[data-sale-request]', '[data-sale-verify]', '#saleApprovalCode']) {
        nodes[key] = {hidden:true, value:'', dataset:{}, addEventListener(){}, matches:s=>s===key};
    }
    const root = {hidden:true, dataset:{request:'/request',verifyBase:'/approvals',customerBase:'/customers'}, querySelector:s=>nodes[s],querySelectorAll:()=>Object.values(nodes),addEventListener:(name,fn)=>rootListeners[name]=fn};
    const emitted = [], requests = [], responses = [];
    const document = {
        getElementById:id=>id==='customerSaleApproval'?root:{value:'context'},
        querySelector:()=>({content:'csrf'}), addEventListener:(name,fn)=>listeners[name]=fn,
        dispatchEvent:e=>emitted.push(e),
    };
    class CustomEvent {constructor(type,options){this.type=type;this.detail=options.detail;}}
    vm.runInNewContext(readFileSync('public/js/customer-sale-approval.js','utf8'), {document,URLSearchParams,CustomEvent,fetch:(url,options)=>{requests.push({url,options});return new Promise(resolve=>responses.push(resolve));}});
    return {root,nodes,emitted,requests,
        choose:id=>listeners['customer-sale-approval-required']({detail:{id,can_request:true,owner_name:'Owner'}}),
        clear:()=>listeners['customer-sale-selection-cleared'](),
        click:key=>rootListeners.click({target:{closest:()=>nodes[key]}}),
        respond:json=>responses.shift()({ok:true,json:async()=>json}),
    };
}
const tick = () => new Promise(resolve=>setImmediate(resolve));

test('selecting another customer ignores a delayed code request',async()=>{
    const s=setup();s.choose(1);
    const pending=s.click('[data-sale-request]');s.choose(2);
    s.respond({id:'old',message:'old request'});await pending;
    assert.equal(s.nodes['[data-sale-code-panel]'].hidden,true);
    assert.notEqual(s.nodes['[data-sale-status]'].textContent,'old request');
});

test('clearing selection during verification never selects the former customer',async()=>{
    const s=setup();s.choose(1);
    let pending=s.click('[data-sale-request]');s.respond({id:'approval',message:'sent'});await pending;
    s.nodes['#saleApprovalCode'].value='123456';
    pending=s.click('[data-sale-verify]');s.clear();s.respond({ok:true});await pending;
    assert.equal(s.requests.length,2);
    assert.equal(s.emitted.length,0);
    assert.equal(s.root.hidden,true);
});

test('verification fetches approved details and emits selection only after both succeed',async()=>{
    const s=setup();s.choose(1);
    let pending=s.click('[data-sale-request]');s.respond({id:'approval',message:'sent'});await pending;
    s.nodes['#saleApprovalCode'].value='۱۲۳۴۵۶';
    pending=s.click('[data-sale-verify]');
    assert.equal(JSON.parse(s.requests[1].options.body).code,'123456');
    s.respond({ok:true});await tick();
    assert.equal(s.emitted.length,0);
    s.respond({data:{customer:{id:1,name:'Approved'}}});await pending;
    assert.equal(s.emitted[0].type,'customer-sale-approved');
    assert.equal(s.emitted[0].detail.id,1);
    assert.equal(s.nodes['[data-sale-code-panel]'].hidden,true);
});
