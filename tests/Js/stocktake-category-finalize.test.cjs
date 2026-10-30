const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../../public/js/stocktake-category-finalize.js'),'utf8');
function fixture({pending=null,allow=true,post,storageFails=false,recovered=false}={}) {
    const key='inventory.stocktake.category.finalize:1:208';
    const storage=new Map(pending?[[key,JSON.stringify(pending)]]:[]);
    const writes=[],gets=[],navigation=[],confirmations=[];
    const button={disabled:false,textContent:'',addEventListener(_,fn){this.click=fn;}};
    const status={textContent:''};
    const form={dataset:{documentId:'208',userId:'1',reviewToken:'a'.repeat(64),zeroCount:'499',totalCount:'500',finalizeUrl:'/finalize/208'},querySelector(){return {value:'csrf'};}};
    let ready, beforeUnload;
    const result={document_id:208,view_url:'/view/208',document:{status:'finalized'}};
    const context={URL,crypto:require('node:crypto').webcrypto,
        document:{addEventListener(_,fn){ready=fn;},getElementById(id){return {categoryFinalizeForm:form,categoryFinalizeButton:button,categoryFinalizeStatus:status}[id];}},
        window:{addEventListener(name,fn){if(name==='beforeunload')beforeUnload=fn;}},location:{origin:'https://erp.example.test',assign(url){let blocked=false;beforeUnload?.({preventDefault(){blocked=true;}});if(!blocked)navigation.push(url);}},
        confirm(message){confirmations.push(message);return allow;},
        sessionStorage:{getItem(k){return storage.get(k)||null;},setItem(k,v){if(storageFails)throw Error('quota');storage.set(k,v);},removeItem(k){storage.delete(k);}},
        fetch:async(url,options={})=>{
            if(options.method==='POST'){const body=JSON.parse(options.body);writes.push(body);return post?post(body):{ok:true,json:async()=>result};}
            gets.push(url);return recovered?{ok:true,status:200,json:async()=>result}:{ok:false,status:404};
        }};
    vm.runInNewContext(source,context);ready();
    return {button,status,writes,gets,navigation,storage,confirmations};
}
async function settle(predicate){for(let i=0;i<50&&!predicate();i++)await new Promise(setImmediate);assert.ok(predicate());}
test('whole-category confirmation names zero rows and sends one reviewed request only after explicit click',async()=>{
    const ui=fixture();assert.equal(ui.writes.length,0);
    await ui.button.click();assert.equal(ui.writes.length,1);
    assert.match(ui.confirmations[0],/500.*499/);
    assert.equal(ui.writes[0].expected_review_token,'a'.repeat(64));
    assert.equal(ui.navigation.length,1);
    await ui.button.click();assert.equal(ui.writes.length,1,'navigation cannot reopen finalization');
});
test('declining whole-category confirmation sends no mutation',async()=>{
    const ui=fixture({allow:false});await ui.button.click();assert.equal(ui.writes.length,0);
});
test('lost final response retries the exact immutable request identity',async()=>{
    const ui=fixture({post:async()=>{throw Error('network');}});
    await ui.button.click();await ui.button.click();
    assert.equal(ui.writes.length,2);assert.deepEqual(ui.writes[0],ui.writes[1]);
    assert.equal(ui.confirmations.length,1);assert.equal(ui.navigation.length,0);
});
test('refresh recovers an already committed finalization with GET and never POST',async()=>{
    const ui=fixture({pending:{request_id:'9c7af632-bdb1-4024-8a16-3bfa68a3a4db',expected_review_token:'b'.repeat(64)},recovered:true});
    await settle(()=>ui.navigation.length===1);
    assert.equal(ui.writes.length,0);assert.equal(ui.gets.length,1);assert.match(ui.gets[0],/operation=unified_finalize/);
});
test('storage failure blocks an unrecoverable final write',async()=>{
    const ui=fixture({storageFails:true});await ui.button.click();assert.equal(ui.writes.length,0);assert.match(ui.status.textContent,/quota/);
});
