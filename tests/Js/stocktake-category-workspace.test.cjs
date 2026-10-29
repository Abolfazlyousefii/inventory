const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../public/js/stocktake-category-workspace.js'), 'utf8');
const blade = fs.readFileSync(path.join(__dirname, '../../resources/views/stocktake/category-workspace.blade.php'), 'utf8');

test('category counting JavaScript is valid and uses bounded 60-row GET pages', () => {
    assert.doesNotThrow(() => new vm.Script(source));
    assert.match(source, /searchParams\.set\('limit','60'\)/);
    assert.match(source, /searchParams\.set\('after_id',String\(afterId\)\)/);
    assert.match(source, /searchParams\.set\('category_id',String\(selectionId\)\)/);
    assert.match(source, /new AbortController\(\)/);
});

test('group save uses a single product draft with only its non-empty quantities', () => {
    assert.match(source, /product_id:id,document_date:date\.value/);
    assert.match(source, /actual_quantities:Object\.fromEntries\(\[\.\.\.group\.quantities\]\.filter\(\(\[,v\]\)=>v!==''\)\)/);
    assert.match(source, /group\.saved = true/);
    assert.match(source, /group\.button\.disabled = group\.saved \|\| group\.saving \|\| loading \|\| hasMore/);
    assert.doesNotMatch(source, /\/finalize|stock-count-documents\.finalize|confirm_empty_as_zero/);
    assert.match(blade, /هر محصول سند جداگانه/);
    assert.match(blade, /نمایش محصولات دسته/);
    assert.match(blade, /ذخیره پیش‌نویس/);
});
