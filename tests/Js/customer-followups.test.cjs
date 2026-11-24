const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const {readFileSync}=require('node:fs');
function setup(fields){
    const listeners={},calls=[],emitted=[],status={textContent:''},buttons=[{disabled:false}];
    const form={action:'/commercial/my-customers/1/interactions',dataset:{},fields,querySelector:()=>status,querySelectorAll:()=>buttons};
    const document={addEventListener:(name,fn)=>listeners[name]=fn,querySelector:s=>s.includes('csrf-token')?{content:'csrf'}:null,dispatchEvent:e=>emitted.push(e)};
    let reply;
    vm.runInNewContext(readFileSync('public/js/customer-followups.js','utf8'),{document,window:{addEventListener(){}},location:{href:'/profile'},FormData:class {constructor(f){return Object.entries(f.fields)[Symbol.iterator]();}},CustomEvent:class {constructor(type){this.type=type;}},fetch:async(url,options)=>{calls.push({url,options});return await new Promise(resolve=>reply=resolve);}});
    return {form,calls,emitted,status,buttons,submit:button=>listeners.submit({target:{closest:()=>form},preventDefault(){},submitter:button}),reply:response=>reply(response)};
}
test('AJAX contact submission prevents duplicate clicks and keeps the same request key',async()=>{
    const s=setup({request_key:'stable-key',kind:'call',body:'Contact note'});
    const first=s.submit();await s.submit();assert.equal(s.calls.length,1);assert.equal(s.buttons[0].disabled,true);
    assert.equal(JSON.parse(s.calls[0].options.body).request_key,'stable-key');
    assert.equal(s.calls[0].options.headers['X-CSRF-TOKEN'],'csrf');
    s.reply({ok:true,json:async()=>({message:'Saved'})});await first;
    assert.equal(s.emitted[0].type,'customer-followup-saved');assert.equal(s.buttons[0].disabled,false);
});
test('failed validation retains the note and renders server errors as text',async()=>{
    const s=setup({request_key:'stable-key',body:'Keep this note'});const pending=s.submit();
    s.reply({ok:false,json:async()=>({errors:{due_date:['Invalid date <b>']}})});await pending;
    assert.equal(s.status.textContent,'Invalid date <b>');assert.equal(s.form.fields.body,'Keep this note');assert.equal(s.emitted.length,0);
});
test('closing a followup uses PATCH, version and the actual clicked status',async()=>{
    const s=setup({_method:'PATCH',version:'2',result:'Completed'});const pending=s.submit({name:'status',value:'done'});
    assert.equal(s.calls[0].options.method,'PATCH');assert.equal(JSON.parse(s.calls[0].options.body).status,'done');assert.equal(JSON.parse(s.calls[0].options.body).version,'2');
    s.reply({ok:true,json:async()=>({message:'Closed'})});await pending;
});
