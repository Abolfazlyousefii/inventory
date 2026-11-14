const {test}=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs');
const source=fs.readFileSync('public/js/purchase-draft-autosave.js','utf8');
class Storage{
 constructor(){this.map=new Map();this.fail=false;}get length(){return this.map.size;}key(i){return [...this.map.keys()][i];}getItem(k){return this.map.get(k)??null;}setItem(k,v){if(this.fail)throw Error('quota');this.map.set(k,v);}removeItem(k){this.map.delete(k);}
}
class Element{
 constructor(){this.events={};this.children=[];this.dataset={};this.inert=false;this.textContent='';}
 addEventListener(e,fn){(this.events[e]??=[]).push(fn);}emit(e,arg={}){for(const fn of this.events[e]||[])fn(arg);}
 appendChild(x){this.children.push(x);}replaceChildren(){this.children=[];}
}
const snapshot=()=>({version:1,fields:{note:'خرید فارسی',invoice_discount_value:'۵٬۰۰۰'},cards:[{product_id:1,buy:'۱٬۰۷۰٬۰۰۰',sell:'۲٬۰۰۰٬۰۰۰',buy_cny:'۲٫۵',items:[{variant_id:2,quantity:'۳'}]}]});
function harness({local=new Storage(),session=new Storage(),fetcher=async()=>({draft:null}),hasInitial=false}={}){
 const status=new Element(),actions=new Element(),form=new Element(),window=new Element(),docEvents=new Element();window.confirm=()=>true;
 const navigator={onLine:true};let token='new-token',body=snapshot(),restored=null,serial=0;
 const document={getElementById:id=>id==='purchaseDraftStatus'?status:actions,querySelector:()=>({content:'csrf'}),createElement:()=>new Element(),addEventListener:(...a)=>docEvents.addEventListener(...a)};
 const calls=[];const context={window,document,localStorage:local,sessionStorage:session,navigator,crypto:{randomUUID:()=>`uuid-${++serial}`},MutationObserver:class{observe(){}},AbortController,Date,console,setTimeout:()=>1,clearTimeout(){},setInterval:()=>1,clearInterval(){},fetch:async(url,opts)=>{calls.push({url,opts});const r=await fetcher(url,opts);return {ok:!r.status||r.status===200,status:r.status||200,json:async()=>r.body||r};}};
 vm.runInNewContext(source,context);
 const config={userId:5,form,cardsElement:{},baseUrl:'/drafts',saveUrl:'/drafts',latestUrl:'/drafts/latest',token:()=>token,setToken:t=>token=t,snapshot:()=>JSON.parse(JSON.stringify(body)),restore:async p=>{restored=p;body=p;},hasContent:p=>p.cards.length>0||!!p.fields.note,hasInitial};
 return {api:window.PurchaseDraftAutosave,config,local,session,status,actions,form,navigator,window,calls,get token(){return token;},get restored(){return restored;},set body(v){body=v;}};
}
test('preserves all fields immediately offline and restores them after a refresh',async()=>{
 const h=harness();const client=await h.api.start(h.config);h.navigator.onLine=false;h.form.emit('input');
 assert.equal(h.api.records(h.local,5).length,1);assert.equal(h.api.records(h.local,5)[0].payload.cards[0].buy_cny,'۲٫۵');
 const next=harness({local:h.local,session:h.session,fetcher:async()=>{throw Error('offline');}});await next.api.start(next.config);
 assert.match(next.status.textContent,/خرید ناتمام/);await next.actions.children[0].events.click[0]();
 assert.equal(next.restored.fields.invoice_discount_value,'۵٬۰۰۰');assert.equal(next.restored.cards[0].items[0].quantity,'۳');client.stop();
});
test('does not create blank drafts or replace a pending draft before the user chooses recovery',async()=>{
 const h=harness();h.body={version:1,fields:{note:''},cards:[]};await h.api.start(h.config);h.form.emit('input');assert.equal(h.local.length,0);
 h.local.setItem('ariya.purchase.draft.v1:5:older',JSON.stringify({token:'old',revision:0,payload:snapshot(),updatedAt:1}));
 const n=harness({local:h.local,fetcher:async()=>({status:404,body:{}})});await n.api.start(n.config);n.form.emit('input');assert.equal(n.local.length,1);assert.equal(n.form.inert,true);
});
test('syncs on reconnect and acknowledges only the snapshot actually saved',async()=>{
 let resolveSave;const h=harness({fetcher:async(url,opts)=>opts.method==='POST'?await new Promise(r=>resolveSave=r):{draft:null}});
 const client=await h.api.start(h.config);h.form.emit('input');const pending=client.sync();
 const changed=snapshot();changed.fields.note='تغییر هنگام ذخیره';h.body=changed;h.form.emit('input');
 resolveSave({draft:{revision:1}});await pending;
 const r=h.api.records(h.local,5)[0];assert.equal(r.revision,1);assert.equal(r.payload.fields.note,'تغییر هنگام ذخیره');
 assert.doesNotMatch(h.status.textContent,/روی دستگاه و سرور ذخیره شد/);
});
test('a lost final response is reconciled as already submitted instead of being offered for resubmission',async()=>{
 const local=new Storage();local.setItem('ariya.purchase.draft.v1:5:old',JSON.stringify({token:'completed',revision:2,submitting:true,payload:snapshot(),updatedAt:1}));
 const h=harness({local,fetcher:async()=>({draft:{status:'submitted',purchase_id:9,purchase_url:'/purchases/9'}})});await h.api.start(h.config);
 assert.equal(h.form.inert,true);assert.equal(h.local.length,0);assert.match(h.status.textContent,/قبلاً ثبت/);assert.equal(h.actions.children[0].href,'/purchases/9');
});
test('stale server revisions stop sync and require an explicit separate copy',async()=>{
 const h=harness({fetcher:async(url,opts)=>opts.method==='POST'?{status:409,body:{draft:{status:'active',revision:8}}}:{draft:null}});
 const client=await h.api.start(h.config);h.form.emit('input');await client.sync();assert.match(h.status.textContent,/صفحهٔ دیگری/);
 const before=h.calls.length;await client.sync();assert.equal(h.calls.length,before);const old=h.token;await h.actions.children[0].events.click[0]();assert.notEqual(h.token,old);
});
test('newer server work is not overwritten by an older local copy and accounts remain isolated',async()=>{
 const local=new Storage(),p=snapshot();local.setItem('ariya.purchase.draft.v1:5:old',JSON.stringify({token:'same',revision:1,payload:p,updatedAt:1}));local.setItem('ariya.purchase.draft.v1:6:other',JSON.stringify({token:'private',revision:1,payload:p,updatedAt:2}));
 const newer=snapshot();newer.fields.note='نسخه دستگاه دیگر';const h=harness({local,fetcher:async()=>({draft:{token:'same',status:'active',revision:3,payload:newer}})});await h.api.start(h.config);
 assert.match(h.actions.children[0].textContent,/خرید جدا/);await h.actions.children[0].events.click[0]();assert.notEqual(h.token,'same');assert.equal(h.api.records(local,6)[0].token,'private');
});
test('storage errors and failed restoration never claim that data was saved',async()=>{
 const h=harness();await h.api.start(h.config);h.local.fail=true;h.form.emit('input');assert.match(h.status.textContent,/ممکن نیست/);
 const local=new Storage();local.setItem('ariya.purchase.draft.v1:5:old',JSON.stringify({token:'old',revision:0,payload:snapshot(),updatedAt:1}));const n=harness({local,fetcher:async()=>{throw Error('offline');}});n.config.restore=async()=>{throw Error('missing variant');};await n.api.start(n.config);await n.actions.children[0].events.click[0]();assert.match(n.status.textContent,/بازیابی کامل نشد/);assert.equal(local.length,1);assert.equal(n.form.inert,true);
});
test('recovers a server-only draft when this device has no local copy',async()=>{
 const h=harness({fetcher:async()=>({draft:{token:'remote',revision:4,status:'active',payload:snapshot(),updated_at:'2026-11-14T10:00:00+03:30'}})});
 await h.api.start(h.config);await h.actions.children[0].events.click[0]();assert.equal(h.token,'remote');assert.equal(h.restored.cards[0].buy,'۱٬۰۷۰٬۰۰۰');
});
test('a validation redirect preserves its own submitted data rather than another draft on the device',async()=>{
 const local=new Storage();local.setItem('ariya.purchase.draft.v1:5:other',JSON.stringify({token:'different',revision:0,payload:snapshot(),updatedAt:1}));
 const h=harness({local,hasInitial:true,fetcher:async()=>({status:404,body:{}})});await h.api.start(h.config);
 assert.equal(h.form.inert,false);assert.equal(h.restored,null);assert.equal(h.token,'new-token');assert.equal(h.api.records(local,5).length,2);
});
test('server success does not falsely claim local durability when storage is full',async()=>{
 const h=harness({fetcher:async(url,opts)=>opts.method==='POST'?{draft:{revision:1}}:{draft:null}});const client=await h.api.start(h.config);h.local.fail=true;h.form.emit('input');await client.sync();assert.match(h.status.textContent,/روی سرور ذخیره شد/);assert.match(h.status.textContent,/روی این دستگاه ممکن نیست/);
});
test('back navigation after an uncertain submit checks the server before enabling the form',async()=>{
 let complete=false;const h=harness({fetcher:async()=>complete?{draft:{status:'submitted',purchase_url:'/purchases/9'}}:{draft:null}});await h.api.start(h.config);h.form.emit('input');h.form.emit('submit',{defaultPrevented:false});complete=true;
 await h.window.events.pageshow[0]({persisted:true});assert.equal(h.form.inert,true);assert.match(h.status.textContent,/قبلاً ثبت/);
});
