const {readFileSync} = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

// Exercise the real browser quote helpers, not a copy of their arithmetic.
const source = readFileSync('resources/views/products/partials/bundle-create.blade.php', 'utf8');
const start = source.indexOf('  const original=');
const end = source.indexOf('  function quote(){');
assert.ok(start > 0 && end > start);
const selected = new Map(Array.from({length:14}, (_, index) => [index + 1, {
    id:index+1, name:'Part '+index, stock:0, sell_price:1000, quantity:10,
    discount_type:'percent', discount_value:10,
}]));
const scope = {selected, rawValue:{value:'20'}, type:{value:'percent'}, value:{value:'20'}};
vm.createContext(scope);
vm.runInContext(source.slice(start, end) + ';this.helpers={original,discountedBase,final,perUnitDiscount,normalizeInteger};', scope);
const {original, discountedBase, final, normalizeInteger} = scope.helpers;
assert.equal(selected.size, 14);
assert.equal([...selected.values()].reduce((sum, part) => sum + part.quantity, 0), 140);
assert.equal(original(), 140000);
assert.equal(discountedBase(), 126000);
assert.equal(final(), 100800);
assert.equal(normalizeInteger('۱۴۰'), '140');
assert.equal(normalizeInteger('١٤٠'), '140');
scope.type.value='final'; scope.rawValue.value='100001';
assert.equal(final(),100001);
scope.rawValue.value='126001';
assert.ok(Number.isNaN(final()), 'Manual price may not exceed component-discounted subtotal');
selected.get(1).discount_type='fixed';selected.get(1).discount_value=1000;
assert.ok(Number.isNaN(discountedBase()), 'An entire unit price cannot be discounted');
assert.match(source,/data-kit-step="0"/);
assert.match(source,/data-kit-step="3"/);
assert.match(source,/id="bundleSelectedSearch"/);
assert.match(source,/id="bundleBulkUndo"/);
assert.match(source,/stock_filter/);
console.log('Bundle wizard pricing, Persian numbers, bulk controls and invalid prices passed.');
