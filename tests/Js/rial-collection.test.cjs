const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
function fixture(){
 const moneyContext={module:{exports:{}}};vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../public/js/rial-inputs.js'),'utf8'),moneyContext);
 const field=(value,original=value)=>({value,dataset:{original}});
 const fields={id:field('4'),product_id:field('10'),variant_id:field('20'),quantity:field('2'),price:field('۱٬۲۵۰٬۰۰۰','1250000'),line_discount_amount:field('۲۵۰٬۰۰۰','250000'),_delete:field('0')};
 const line={textContent:''},summary={textContent:''};
 const row={classList:{contains:()=>false},querySelectorAll:()=>[fields.quantity,fields.price,fields.line_discount_amount],querySelector(selector){
  if(selector==='.js-item-quantity')return fields.quantity;if(selector==='.js-price')return fields.price;if(selector==='.js-discount')return fields.line_discount_amount;if(selector==='.js-line-total')return line;
  return Object.keys(fields).find(key=>selector.includes(`[${key}]`)) ? fields[Object.keys(fields).find(key=>selector.includes(`[${key}]`))] : null;
 }};
 const reasonSelect={required:false};
 const context={Number,String,Array,RialInput:moneyContext.module.exports,reasonSelect,window:{},document:{querySelectorAll:()=>[row],getElementById:()=>summary}};
 const source=fs.readFileSync(path.join(__dirname,'../../resources/views/vouchers/sales/edit.blade.php'),'utf8');
 vm.runInNewContext(source.slice(source.indexOf('function recalcTotals()'),source.indexOf('function prepareJsonPayloadForSubmit()')),context);
 return {context,fields,line,summary};
}
test('collection JSON contains actual amounts rather than NaN/null or grouped strings',()=>{
 const {context}=fixture();const payload=JSON.parse(JSON.stringify(context.buildItemsPayload()));
 assert.equal(payload[0].price,1250000);assert.equal(payload[0].line_discount_amount,250000);assert.equal(payload[0].quantity,2);assert.equal(payload[0]._delete,false);
});
test('collection preview handles grouped Persian amounts without marking unchanged rows as edited',()=>{
 const {context,fields,line,summary}=fixture();context.recalcTotals();
 assert.equal(line.textContent,(2250000).toLocaleString());assert.match(summary.textContent,/ردیف‌های تغییرکرده: 0/);
 fields.price.value='۲٬۰۰۰٬۰۰۰';context.recalcTotals();assert.equal(line.textContent,(3750000).toLocaleString());assert.match(summary.textContent,/ردیف‌های تغییرکرده: 1/);
});
