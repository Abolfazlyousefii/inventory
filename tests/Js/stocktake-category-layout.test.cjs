const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const form = fs.readFileSync(path.join(__dirname, '../../resources/views/stocktake/form.blade.php'), 'utf8');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/stocktake-category-workspace.js'), 'utf8');

test('counting form removes document metadata inputs and groups the category-first workflow', () => {
    assert.doesNotMatch(form, /type="date"|stocktake-compact mb-3|value="انبارگردانی محصولی" readonly/);
    assert.match(form, /type="hidden" name="document_date"/);
    assert.match(form, /now\('Asia\/Tehran'\)->format\('Y-m-d'\)/);
    assert.match(form, /stocktake-selector__primary/);
    assert.match(form, /stocktake-selector__alternative/);
    assert.match(form, /stocktake-workspace\.css/);
    const legacy = fs.readFileSync(path.join(__dirname, '../../resources/views/stocktake/category-workspace.blade.php'), 'utf8');
    assert.doesNotMatch(legacy, /type="date"/);
    assert.match(legacy, /type="hidden" id="scDate"/);
});

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
        assert.ok(source.includes("dataset.label = '" + label + "'"), "missing mobile label: " + label);
    }
    assert.doesNotThrow(() => new vm.Script(source));
});

test('layout preserves reviewed complete-only explicit bulk finalization', () => {
    assert.match(source, /if \(!canFinalize\(\)\) return/);
    assert.match(source, /if \(!window\.confirm\(/);
    assert.match(source, /value === '' \? null : value/);
    assert.doesNotMatch(source, /confirm_empty_as_zero|data-save-product/);
    assert.match(form, /عدد ۰ را صریحاً/);
});
