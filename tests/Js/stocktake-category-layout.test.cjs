const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const form = fs.readFileSync(path.join(__dirname, '../../resources/views/stocktake/form.blade.php'), 'utf8');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/stocktake-category-workspace.js'), 'utf8');

test('stocktake still shows category counting INSIDE the original product form', () => {
    assert.match(form, /id="stocktakeForm"/);
    assert.match(form, /id="stocktakeCategoryApp"[^>]*data-embedded="1"[^>]*hidden/s);
    assert.match(form, /id="scShow"/);
    assert.match(form, /id="scProducts"/);
    assert.match(form, /id="scClose"/);
    assert.doesNotMatch(form, /href="\{\{ route\('stock-count-documents.category-workspace'\) \}\}"/);
});

test('category cards use a bounded responsive layout without clipping the central inventory count', () => {
    assert.match(form, /\.stocktake-page__header\s*\{\s*display:grid;/);
    assert.match(form, /\.stocktake-page\s+\.sc-product-head\s*\{\s*display:grid;/);
    assert.match(form, /\.stocktake-page\s+\.sc-table-wrap\s*\{[^}]*overflow-x:auto/s);
    assert.match(form, /@media\s*\(max-width:720px\)/);
    assert.match(form, /\.stocktake-page\s+\.sc-product\s+tbody\s+tr\s*\{\s*display:grid;/);
    assert.match(form, /content:attr\(data-label\)/);
    assert.match(form, /\.stocktake-page\s+\.sc-actual[^}]*min-width:0/s);
});

test('every mobile counting cell has a label, including live queue and entered physical count', () => {
    assert.match(source, /description\.dataset\.label\s*=\s*'تنوع کالا'/);
    for (const label of ['موجودی انبار مرکزی', 'رزرو فعال', 'موجودی در صف جمع‌آوری', 'موجودی واقعی', 'اختلاف']) {
        assert.match(source, new RegExp('dataset\\\\.label\\\\s*=\\\\s*[\\'"]' + label + '[\\'"]'));
    }
    assert.doesNotThrow(() => new vm.Script(source));
});

test('layout changes preserve existing draft safety and never call stock finalization', () => {
    assert.match(source, /group\.button\.disabled = group\.saved \|\| group\.saving \|\| loading \|\| hasMore/);
    assert.match(source, /actual_quantities:Object\.fromEntries\(\[\.\.\.group\.quantities\]\.filter\(\(\[,v\]\)=>v!==''\)\)/);
    assert.doesNotMatch(source, /\/finalize|confirm_empty_as_zero/);
    assert.match(form, /عدد ۰ را صریحاً/);
});
