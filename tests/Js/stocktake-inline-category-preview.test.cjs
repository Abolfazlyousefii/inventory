const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const read = file => fs.readFileSync(path.join(__dirname, '../..', file), 'utf8');

// package.json uses "type": "module", while this script is loaded as a classic
// browser script. Evaluate it as a classic script with an isolated CommonJS shim,
// so tests exercise the same implementation without relying on ESM require().
const previewModule = { exports: {} };
const browserSource = read('public/js/stocktake-inline-category-preview.js');
Function('module', browserSource)(previewModule);
const { validatePage, PAGE_SIZE } = previewModule.exports;

test('category preview uses bounded pages with an advancing cursor', () => {
    assert.equal(PAGE_SIZE, 60);
    assert.deepEqual(validatePage({
        rows: [{ variant_id: 10, product_id: 1 }],
        next_cursor: 10,
        has_more: true,
    }, 0), {
        rows: [{ variant_id: 10, product_id: 1 }],
        cursor: 10,
        hasMore: true,
    });
    assert.deepEqual(validatePage({ rows: [], next_cursor: 10, has_more: false }, 10), {
        rows: [], cursor: 10, hasMore: false,
    });
});

test('category preview rejects invalid or stalled paginated responses', () => {
    assert.throws(() => validatePage({ rows: [], next_cursor: 0, has_more: true }, 0));
    assert.throws(() => validatePage({ rows: [{ variant_id: 1 }], next_cursor: 9, has_more: true }, 10));
    assert.throws(() => validatePage({ rows: [], next_cursor: 'bad', has_more: false }, 0));
    assert.throws(() => validatePage({ rows: {}, next_cursor: 2, has_more: false }, 0));
    assert.throws(() => validatePage({ rows: [], next_cursor: 1, has_more: 0 }, 0));
});

test('October 29 inline stocktake supports editing and saving a draft for each product', () => {
    const form = read('resources/views/stocktake/form.blade.php');
    assert.match(form, /id="stocktakeForm"/);
    assert.match(form, /@if\(\$mode==='create'\)/);
    assert.match(form, /id="scShow"/);
    assert.match(form, /id="stocktakeCategoryApp" data-embedded="1"/);
    assert.match(form, /id="scProducts"/);
    assert.match(form, /data-store-url=/);
    assert.match(form, /stocktake-embedded-counting-oct29\.js/);
    assert.doesNotMatch(form, /stocktakeInlinePreview|stocktake-inline-category-preview\.js/);

    const script = read('public/js/stocktake-embedded-counting-oct29.js');
    assert.match(script, /ذخیره پیش‌نویس این محصول/);
    assert.match(script, /dataset\.saveProduct/);
    assert.match(script, /async function saveProduct\(id\)/);
    assert.match(script, /group\.button\.disabled\s*=\s*group\.saved/);
    assert.match(script, /method:\s*'POST'/);
    assert.match(script, /group\.quantities\.set\(Number\(field\.dataset\.variant\),normalized\)/);
    assert.match(script, /document\.getElementById\('stocktakeForm'\)\.dataset\.categoryMode/);
});

test('preview code reads category variant endpoint, never makes stock writes', () => {
    const script = read('public/js/stocktake-inline-category-preview.js');
    assert.match(script, /method:\s*'GET'/);
    assert.match(script, /url\.searchParams\.set\('category_id'/);
    assert.match(script, /url\.searchParams\.set\('after_id'/);
    assert.match(script, /url\.searchParams\.set\('limit'/);
    assert.match(script, /event\.preventDefault\(\)/);
    assert.match(script, /event\.stopImmediatePropagation\(\)/);
    assert.doesNotMatch(script, /method:\s*['"](?:POST|PATCH|PUT|DELETE)['"]/i);
    assert.doesNotMatch(script, /(?:bulkFinalize|saveCategoryDraft|stocktake_adjustment|warehouse_stocks)/);
});
