const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
function boot(){const context={module:{exports:{}}};vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../public/js/rial-inputs.js'),'utf8'),context);return context.module.exports;}
test('formats rial integers without rounding or losing Persian and Arabic digits',()=>{
 const r=boot();
 assert.equal(r.format('۱۲۳۴۵۶۷۸۹۰'),'1,234,567,890');
 assert.equal(r.format('١٢٣٬٤٥٦'),'123,456');
 assert.equal(r.format('900719925474099312345'),'900,719,925,474,099,312,345');
 assert.equal(r.format(''),''); assert.equal(r.format('۰'),'0');
 assert.equal(r.raw('۱٬۲۵۰٬۰۰۰'),'1250000');
 assert.equal(r.number('۱٬۲۵۰٬۰۰۰'),1250000);
});
test('signed adjustments and decimal percentages keep their meaning',()=>{
 const r=boot();assert.equal(r.format('-۵۰۰۰۰۰'),'-500,000');
 assert.equal(r.format('۱۲٫۵','percent'),'12.5');
 assert.equal(r.raw('−۱٬۲۰۰'),'-1200');
 assert.equal(r.number('12.5'),12.5);
 assert.equal(r.format('12.5'),'12.5'); // Never silently turn an invalid fractional rial into 125.
});
test('caret position follows digits when grouping or deleting in the middle',()=>{
 const r=boot(); assert.equal(r.caret('1234567',4,'1,234,567'),5);
 assert.equal(r.caret('1,234,567',3,'1,234,567'),3);
 assert.equal(r.caret('۱۲۳۴',4,'1,234'),5);
});

test('installed formatter keeps quantities alone and posts raw amounts including external form fields',()=>{
 const listeners={};let observe;
 const element=(value,marked=true,type='number')=>({value,type,name:'',min:'',max:'',dataset:marked?{rial:''}:{},style:{},selectionStart:0,
  matches(selector){return selector.includes('input')&&marked;},hasAttribute(){return false;},closest(){return null;},
  setCustomValidity(message){this.error=message;},setSelectionRange(start,end){this.selectionStart=start;this.selectionEnd=end;}});
 const price=element('۱۲۵۰۰۰۰'),quantity=element('2',false),disabled=element('3000'),external=element('۴۵۰۰۰۰۰');
 price.name='price';disabled.name='ignored';disabled.disabled=true;external.name='external';quantity.name='quantity';
 const doc={readyState:'complete',activeElement:price,documentElement:{},querySelectorAll:()=>[price,disabled],
  addEventListener:(name,handler)=>listeners[name]=handler,querySelector(){return null;}};
 const context={window:{},document:doc,MutationObserver:class{constructor(callback){observe=callback;}observe(){}},WeakSet,Number,String};
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../public/js/rial-inputs.js'),'utf8'),context);
 assert.equal(price.type,'text');assert.equal(price.value,'1,250,000');assert.equal(quantity.type,'number');
 external.nodeType=1;external.querySelectorAll=()=>[];observe([{addedNodes:[external]}]);assert.equal(external.value,'4,500,000');
 let blocked=false;listeners.keydown({target:price,key:'ArrowUp',preventDefault(){blocked=true;}});assert.equal(blocked,true);
 blocked=false;listeners.keydown({target:quantity,key:'ArrowUp',preventDefault(){blocked=true;}});assert.equal(blocked,false);
 const data=new Map([['price',price.value],['quantity','2'],['external',external.value]]);
 listeners.formdata({target:{elements:[price,quantity,disabled,external]},formData:data});
 assert.equal(data.get('price'),'1250000');assert.equal(data.get('external'),'4500000');assert.equal(data.get('quantity'),'2');assert.equal(data.has('ignored'),false);
 price.min='1';price.value='0';listeners.input({target:price});assert.equal(price.error,'مبلغ کمتر از حد مجاز است.');
 price.value='۱۰۰۰';listeners.input({target:price});assert.equal(price.error,'');assert.equal(price.value,'1,000');
});
