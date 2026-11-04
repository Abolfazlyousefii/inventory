const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '../..');
const workspaceSource = fs.readFileSync(path.join(root, 'public/js/stocktake-category-workspace.js'), 'utf8');
const formSource = fs.readFileSync(path.join(root, 'resources/views/stocktake/form.blade.php'), 'utf8');

function loadProductsToSave() {
    const match = workspaceSource.match(/function productsToSave\([\s\S]*?\n    \}/);
    assert.ok(match, 'workspace must expose the pure productsToSave selection rule');
    const context = {};
    vm.runInNewContext(match[0] + '\nthis.productsToSave = productsToSave;', context);
    return context.productsToSave;
}

function loadIsBelowProtectedQuantity() {
    const match = workspaceSource.match(/function isBelowProtectedQuantity\([\s\S]*?\n    \}/);
    assert.ok(match, 'workspace must expose the protected-quantity validation rule');
    const context = {};
    vm.runInNewContext(match[0] + '\nthis.isBelowProtectedQuantity = isBelowProtectedQuantity;', context);
    return context.isBelowProtectedQuantity;
}

test('new product drafts are created only after at least one explicit count, including zero', () => {
    const productsToSave = loadProductsToSave();
    const known = new Map([[1, 'No count'], [2, 'Explicit zero'], [3, 'Positive']]);
    const entered = new Map([
        [1, new Map()],
        [2, new Map([[21, '0']])],
        [3, new Map([[31, '4'], [32, '']])],
    ]);

    assert.deepEqual(Array.from(productsToSave(known, entered, new Map(), new Set(), new Set())), [2, 3]);
});

test('dirty saved drafts remain saveable when the user clears every entered count', () => {
    const productsToSave = loadProductsToSave();
    const known = new Map([[1, 'Saved draft'], [2, 'Unchanged draft']]);
    const entered = new Map([
        [1, new Map([[11, ''], [12, '']])],
        [2, new Map([[21, '5']])],
    ]);
    const saved = new Map([[1, '/draft/1'], [2, '/draft/2']]);

    assert.deepEqual(Array.from(productsToSave(known, entered, saved, new Set([1]), new Set())), [1]);
});

test('finalized products are never selected for another draft save', () => {
    const productsToSave = loadProductsToSave();
    const known = new Map([[1, 'Finalized'], [2, 'New count']]);
    const entered = new Map([[1, new Map([[11, '3']])], [2, new Map([[21, '0']])]]);

    assert.deepEqual(Array.from(productsToSave(known, entered, new Map(), new Set(), new Set([1]))), [2]);
});

test('category counting is embedded in the product form and has an explicit return action', () => {
    assert.match(formSource, /id="stocktakeCategoryApp"[^>]*data-embedded="1"/s);
    assert.match(formSource, /id="scShow"/);
    assert.match(formSource, /id="scClose"/);
    assert.match(formSource, /id="scZeroAll"/);
    assert.match(workspaceSource, /async function loadAllRemaining\(/);
    assert.match(workspaceSource, /fa\(row\.bundle_free\)/);
    assert.match(workspaceSource, /fa\(row\.site_held\)/);
    assert.doesNotMatch(formSource, /id="stocktakeInlinePreview"/);
});

test('protected stock quantities block a physical count below reserved components', () => {
    const isBelowProtectedQuantity = loadIsBelowProtectedQuantity();
    const row = {active_reserved: 2, bundle_free: 3, bundle_reserved: 1, site_held: 4};
    assert.equal(isBelowProtectedQuantity(row, 9), true);
    assert.equal(isBelowProtectedQuantity(row, 10), false);
    assert.equal(isBelowProtectedQuantity(row, '0'), true);
    assert.equal(isBelowProtectedQuantity(row, ''), false);
});
