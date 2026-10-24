const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('resources/views/preinvoice/create.blade.php','utf8');
const filter=source.slice(source.indexOf('function filteredModalItems()'),source.indexOf('function modalMaxQty('));
const input={value:''};
const rows=[{id:1,model:'POCO X3 Pro',name:'قاب شيائومي يخى',max:4},{id:2,model:'آيفون ۱۳ پرو',name:'مشکی',max:0},{id:3,model:'POCO X5 Pro',name:'یخی',max:5,barcode:'03074534004'}];
const context=vm.createContext({window:{},document:{getElementById:()=>input},normalize:v=>String(v??'').trim(),activeModalItems:rows,modalOnlyInStock:false,modalQuantities:new Map(),variantAvailability:v=>({maxSelectable:v.max}),variantId:v=>v.id,variantModel:v=>v.model,variantDesign:()=>'',variantName:v=>v.name});
if(fs.existsSync('public/js/variant-search.js'))vm.runInContext(fs.readFileSync('public/js/variant-search.js','utf8'),context);
vm.runInContext(filter,context);
for(const term of ['x ۳ PRO یخی','یخی pro x٣','poco x3pro']){input.value=term;assert.deepEqual(Array.from(vm.runInContext('filteredModalItems()',context),v=>v.id),[1],term)}
input.value='آیفون 13';assert.deepEqual(Array.from(vm.runInContext('filteredModalItems()',context),v=>v.id),[2]);
context.modalOnlyInStock=true;assert.equal(vm.runInContext('filteredModalItems().length',context),0);
context.modalQuantities.set(2,1);assert.equal(vm.runInContext('filteredModalItems().length',context),1);
input.value='';context.modalOnlyInStock=false;assert.deepEqual(Array.from(vm.runInContext('filteredModalItems()',context),v=>v.id),[1,2,3]);
console.log('Actual preinvoice filter: normalized query/name, model spacing, all tokens, stock/selected rows and existing order passed.');
