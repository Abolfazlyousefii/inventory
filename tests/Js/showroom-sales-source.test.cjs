const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('resources/views/preinvoice/create.blade.php','utf8');
function load(name, context) {
    const start=source.indexOf(`function ${name}(`);
    assert.notEqual(start,-1);
    const rest=source.slice(start);
    const next=rest.slice(1).search(/\n[\t ]+(?:async )?function /);
    vm.runInContext(rest.slice(0,next+1),context);
}
function context() {
    return vm.createContext({warehouseSources:new Map(),variantId:v=>Number(v.id),formatNum:String,
        groupedSelections:{1:{product:{id:1,title:'QA'},items:[{variant_id:11,quantity:2,price:100,source_warehouse_id:5},{variant_id:11,quantity:1,price:100}]}},toInt:Number});
}
test('central and showroom have independent selectable limits even when both have stock',()=>{
    const ctx=context();load('variantAvailability',ctx);
    const variant={id:11,free_stock:4,showroom_free_stock:5};
    assert.equal(ctx.variantAvailability({...variant,sales_source_warehouse_id:0}).maxSelectable,4);
    assert.equal(ctx.variantAvailability({...variant,sales_source_warehouse_id:5}).maxSelectable,5);
});
test('autosave final submission and reservation payloads retain the warehouse per line',()=>{
    const ctx=context();
    for(const name of ['collectProductsForAutosave','collectProductsForSubmit','reservationItemsFromGroups']) {
        load(name,ctx);
        const rows=ctx[name]();
        assert.equal(rows[0].source_warehouse_id,5);
        assert.equal(rows[1].source_warehouse_id,null);
        assert.equal(rows[0].quantity,2);
    }
});
test('the same variant renders as two independently addressable warehouse quantity rows',()=>{
    const ctx=context();const wrap={innerHTML:''};
    Object.assign(ctx,{document:{getElementById:id=>id==='groupPickerRows'?wrap:{value:''}},
        filteredModalItems:()=>[0,5].map(source=>({id:11,free_stock:4,showroom_free_stock:5,showroom_warehouse_id:5,sales_source_warehouse_id:source})),
        modalQuantities:new Map(),modalMaxQty:v=>v.sales_source_warehouse_id?5:4,variantPrice:()=>100,activeProduct:{},
        buildVariantTitle:()=> 'QA',bundlePillsHtml:()=>'',esc:String,formatMoney:String,normalize:String});
    load('pickerKey',ctx);load('variantAvailability',ctx);load('renderPickerRows',ctx);ctx.renderPickerRows();
    assert.match(wrap.innerHTML,/از انبار مرکزی/);
    assert.match(wrap.innerHTML,/از انبار شوروم/);
    assert.match(wrap.innerHTML,/data-row-variant="11:5"/);
    assert.doesNotMatch(wrap.innerHTML,/class="variant-row [^"]*row-empty-stock/);
    assert.match(wrap.innerHTML,/picker-qty[^>]*max="5"/);
    assert.match(wrap.innerHTML,/picker-qty[^>]*max="4"/);
});

test('changing showroom quantity never changes the central quantity of the same variant',()=>{
    const ctx=context();
    Object.assign(ctx,{activeModalItems:[0,5].map(source=>({id:11,free_stock:4,showroom_free_stock:5,sales_source_warehouse_id:source})),modalQuantities:new Map([[11,3],['11:5',2]]),
        document:{querySelector:()=>null},toEnglishDigits:String,updateModalSummary:()=>{},renderPickerRows:()=>{}});
    load('pickerKey',ctx);load('variantAvailability',ctx);ctx.modalMaxQty=v=>ctx.variantAvailability(v).maxSelectable;
    load('setModalQty',ctx);ctx.setModalQty('11:5','9');
    assert.equal(ctx.modalQuantities.get('11:5'),5);
    assert.equal(ctx.modalQuantities.get(11),3);
});
