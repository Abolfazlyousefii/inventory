const test=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const vm=require('node:vm');
const source=fs.readFileSync('public/js/shipping-incoming.js','utf8');
function fixture(fetcher){
 const node=()=>({children:[],textContent:'',hidden:false,className:'',classList:{add(){},remove(){}},events:{},addEventListener(n,f){this.events[n]=f;},appendChild(n){if(n.fragment)this.children.push(...n.children);else this.children.push(n);},replaceChildren(){this.children=[];}});
 const ids=['shippingIncomingModal','shippingIncomingList','shippingIncomingState','shippingIncomingIntro','shippingIncomingCount','shippingIncomingMore','shippingIncomingRetry'];const nodes=Object.fromEntries(ids.map(id=>[id,node()]));nodes.shippingIncomingModal.dataset={url:'/warehouse/shipping/incoming'};
 const requests=[];const document={getElementById:id=>nodes[id],createElement:()=>node(),createDocumentFragment:()=>Object.assign(node(),{fragment:true})};
 vm.runInNewContext(source,{document,location:{origin:'https://erp.test'},URL,AbortController,setTimeout,clearTimeout,fetch:async(url,opts)=>{requests.push({url:String(url),opts});return fetcher(url,opts);}});
 return {nodes,requests,open:()=>nodes.shippingIncomingModal.events['show.bs.modal'](),close:()=>nodes.shippingIncomingModal.events['hidden.bs.modal']()};
}
const response=data=>({ok:true,json:async()=>data});const row=name=>({uuid:'02172',customer_name:name,status:'collecting',status_label:'در حال جمع‌آوری',address:'آدرس سند'});
async function flush(){for(let i=0;i<8;i++)await new Promise(resolve=>setImmediate(resolve));}
test('opening the modal refreshes read-only data and renders text safely',async()=>{
 let count=0;const f=fixture(async()=>response(++count===1?{data:[row('<img onerror=alert(1)>')],total:1,next_page:null}:{data:[],total:0,next_page:null}));
 f.open();await flush();assert.equal(f.nodes.shippingIncomingList.children.length,1);assert.equal(f.nodes.shippingIncomingList.children[0].children[0].children[0].textContent,'<img onerror=alert(1)>');
 assert.equal(f.requests[0].opts.cache,'no-store');assert.equal(f.requests[0].opts.method,undefined);
 f.close();f.open();await flush();assert.equal(f.nodes.shippingIncomingList.children.length,0);assert.match(f.nodes.shippingIncomingState.textContent,/فعلاً/);assert.equal(f.requests.length,2);
});
test('a late response from a closed modal cannot replace a newer result',async()=>{
 let resolveFirst,n=0;const f=fixture(()=>++n===1?new Promise(resolve=>resolveFirst=resolve):Promise.resolve(response({data:[row('New')],total:1,next_page:null})));
 f.open();f.close();f.open();await flush();resolveFirst(response({data:[row('Old')],total:1,next_page:null}));await flush();
 assert.equal(f.nodes.shippingIncomingList.children[0].children[0].children[0].textContent,'New');assert.equal(f.requests[0].opts.signal.aborted,true);
});
test('failed requests show a retry and missing addresses are explicit',async()=>{
 let n=0;const f=fixture(async()=>++n===1?{ok:false,status:403}:response({data:[{...row('Customer'),address:null}],total:1,next_page:null}));f.open();await flush();assert.equal(f.nodes.shippingIncomingRetry.hidden,false);assert.match(f.nodes.shippingIncomingState.textContent,/دسترسی/);
 f.nodes.shippingIncomingRetry.events.click();await flush();assert.match(f.nodes.shippingIncomingList.children[0].children[2].textContent,/آدرس ارسال ثبت نشده/);
});
