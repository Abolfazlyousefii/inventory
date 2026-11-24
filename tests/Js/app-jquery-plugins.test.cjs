const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const {readFileSync} = require('node:fs');

const source = readFileSync('resources/js/app.js', 'utf8').replace(/^import .*;\r?\n/gm, '');

test('application boot preserves the layout jQuery instance and its Select2 plugin', () => {
    const select2 = () => {};
    const layoutJQuery = {fn:{select2}};
    const window = {jQuery:layoutJQuery};
    let dataTableJQuery;
    vm.runInNewContext(source, {window,jQuery:{fn:{}},DataTable:{use:value=>dataTableJQuery=value},Alpine:{start(){}}});
    assert.equal(window.jQuery, layoutJQuery);
    assert.equal(window.$, layoutJQuery);
    assert.equal(window.jQuery.fn.select2, select2);
    assert.equal(dataTableJQuery, layoutJQuery);
});

test('application boot uses bundled jQuery on pages without a layout instance', () => {
    const jQuery = {fn:{}}, window = {};
    let dataTableJQuery;
    vm.runInNewContext(source, {window,jQuery,DataTable:{use:value=>dataTableJQuery=value},Alpine:{start(){}}});
    assert.equal(window.jQuery, jQuery);
    assert.equal(window.$, jQuery);
    assert.equal(dataTableJQuery, jQuery);
});
