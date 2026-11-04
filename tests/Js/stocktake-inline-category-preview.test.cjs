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
const { validatePage, PAGE_SIZE, normalizeActualCount, summarizeCounts } = previewModule.exports;

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

test('preview renders inside the original stocktake create form and nowhere else', () => {
    const form = read('resources/views/stocktake/form.blade.php');
    assert.match(form, /id="stocktakeForm"/);
    assert.match(form, /id="stocktakeInlinePreviewShow"/);
    assert.match(form, /id="stocktakeInlinePreview"/);
    assert.match(form, /id="stocktakeInlinePreviewMore"/);
    assert.match(form, /stocktake-inline-category-preview\.js/);
    assert.match(form, /@if\(\$mode==='create'\)/);
    assert.match(form, /در این مرحله می‌توانی موجودی واقعی تنوع‌ها را وارد کنی/);
    assert.doesNotMatch(form, /id="stocktakeInlinePreviewSave"/);
    assert.doesNotMatch(form, /id="stocktakeInlinePreviewFinalize"/);
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


test('physical count treats explicit zero differently from blank and validates digits', () => {
    assert.equal(normalizeActualCount(''), null);
    assert.equal(normalizeActualCount('   '), null);
    assert.equal(normalizeActualCount('۰'), '0');
    assert.equal(normalizeActualCount('٠٠١۲'), '12');
    assert.equal(normalizeActualCount('0000'), '0');
    assert.equal(normalizeActualCount('2147483647'), '2147483647');
    assert.throws(() => normalizeActualCount('-1'));
    assert.throws(() => normalizeActualCount('2.5'));
    assert.throws(() => normalizeActualCount('2147483648'));
    assert.throws(() => normalizeActualCount('abc'));
});

test('counts persist by variant key and include zero in counted total', () => {
    const countValues = new Map([[100, '0'], [101, '5'], [102, '']]);
    assert.deepEqual(summarizeCounts(countValues, new Set([100, 101, 102, 103])), {
        seen: 4, counted: 2, zero: 1, uncounted: 2,
    });
});

test('in-page counting has search, numeric inputs and no write endpoint', () => {
    const view = read('resources/views/stocktake/form.blade.php');
    const script = read('public/js/stocktake-inline-category-preview.js');
    assert.match(view, /id="stocktakeInlinePreviewSearch"/);
    assert.match(view, /id="stocktakeInlinePreviewSummary"/);
    assert.match(script, /className\s*=\s*'form-control stocktake-inline-actual'/);
    assert.match(script, /const entered = new Map\(\)/);
    assert.match(script, /entered\.set\(variantId,/);
    assert.match(script, /const stored = entered\.get\(variantId\)/);
    assert.match(script, /searchInput\.addEventListener\('input'/);
    assert.doesNotMatch(script, /method:\s*['"](?:POST|PATCH|PUT|DELETE)['"]/i);
});
