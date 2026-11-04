const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { validatePage, PAGE_SIZE } = require('../../public/js/stocktake-inline-category-preview.js');

const read = file => fs.readFileSync(path.join(__dirname, '../..', file), 'utf8');

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
    assert.match(form, /این مرحله فقط پیش‌نمایش خواندنی/);
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
