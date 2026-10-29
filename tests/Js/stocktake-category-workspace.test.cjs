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


const form = fs.readFileSync(path.join(__dirname, '../../resources/views/stocktake/form.blade.php'), 'utf8');

test('category workspace integrates into the original form without nested forms or a separate page', () => {
    assert.match(form, /id="scShow"/);
    assert.match(form, /id="stocktakeCategoryApp"[^>]*data-embedded="1"/s);
    assert.match(form, /id="scClose"/);
    assert.match(form, /id="scProducts"/);
    assert.match(form, /data-stocktake-single/);
    assert.doesNotMatch(form, /href="\{\{ route\('stock-count-documents.category-workspace'\) \}\}"/);
    assert.match(source, /getElementById\(embedded \? 'category' : 'scCategory'\)/);
    assert.match(source, /getElementById\(embedded \? 'subcategory' : 'scSubcategory'\)/);
    assert.match(source, /document\.querySelector\('\[name="document_date"\]'\)/);
    assert.match(source, /'موجودی انبار مرکزی','رزرو فعال','موجودی در صف جمع‌آوری','موجودی واقعی','اختلاف'/);
    assert.match(source, /row\.expected_physical/);
    assert.match(source, /row\.pending_collection/);
    assert.match(source, /row\.warehouse_received/);
    assert.match(source, /row\.collecting/);
    assert.match(source, /updateDifference\(group,Number\(field\.dataset\.variant\)\)/);
    assert.match(source, /if \(!embedded\) category\.addEventListener/);
    assert.match(source, /if \(embedded\) \{/);
    assert.match(source, /originalForm\.addEventListener\('submit'/);
    assert.doesNotMatch(source, /\/finalize|stock-count-documents\.finalize/);
});
