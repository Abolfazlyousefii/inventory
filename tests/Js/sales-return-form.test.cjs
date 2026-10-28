const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const view = fs.readFileSync('resources/views/vouchers/return-from-sale/create.blade.php', 'utf8');
const script = [...view.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(match => match[1]).find(source => source.includes("document.addEventListener('DOMContentLoaded'"));

test('the complete return form script parses, including nested block declarations', () => {
    assert.ok(script);
    new vm.Script(script.replace(/@json\([^)]*\)/g, 'null'));
});

test('Persian grouped prices work and negative or decimal amounts never become another positive price', () => {
    const start = script.indexOf("const faDigits=");
    const end = script.indexOf('const moneyText=', start);
    const normalize = vm.runInNewContext(`(()=>{${script.slice(start,end)}return moneyToInteger;})()`);
    assert.equal(normalize('۳٬۵۱۰٬۰۰۰'), 3510000);
    assert.equal(normalize('٣,٥١٠,٠٠٠'), 3510000);
    assert.equal(normalize('3,510,000'), 3510000);
    for (const invalid of ['-100', '12.50', '1,25']) assert.equal(normalize(invalid), null);
});

test('refund and catalog sale price remain separate in the actual compact submission', () => {
    const start = script.indexOf('function buildCompactItemsPayload()');
    const end = script.indexOf('function syncCompactItemsPayload', start);
    const state = {items: [{item_source:'existing_product',product_variant_id:3,return_quantity:2,refund_unit_price:100,sell_price:350}],defaultDestinationWarehouseId:1};
    const result = vm.runInNewContext(`${script.slice(start,end)}buildCompactItemsPayload()`, {state});
    assert.equal(result.items[0].refund_unit_price, 100);
    assert.equal(result.items[0].sell_price, 350);
    assert.equal(result.items[0].return_quantity, 2);
});
