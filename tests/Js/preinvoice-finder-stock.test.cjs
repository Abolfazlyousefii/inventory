const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const {test} = require('node:test');
const source = fs.readFileSync('resources/views/preinvoice/create.blade.php', 'utf8');
const start = source.indexOf('async function openGroupPicker(');
const body = source.slice(start, source.indexOf('groupPickerModal.show();', start)) + '}';
function run(product, target) {
    const nodes = {};
    const ctx = vm.createContext({selectedMotherProduct: product, groupedSelections: {},
        document: {getElementById(id) {return nodes[id] ||= {value: '',checked:false,innerHTML:'',classList:{add(){},remove(){}}};}}});
    vm.runInContext(body, ctx);
    return ctx.openGroupPicker(target).then(() => nodes);
}
test('picker carries the finder model and defaults to available variants', async () => {
    const nodes = await run({id: 5, _finder_query:'a12'}, 5);
    assert.equal(nodes.pickerSearchInput.value, 'a12');
    assert.equal(nodes.onlyInStockToggle.checked, true);
});
test('editing a different product does not inherit the last finder model', async () => {
    const nodes = await run({id: 5, _finder_query:'a12'}, 6);
    assert.equal(nodes.pickerSearchInput.value, '');
    assert.equal(nodes.onlyInStockToggle.checked, true);
});
