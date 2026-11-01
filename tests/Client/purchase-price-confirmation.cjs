const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

// Exercise the exact sale-change predicate that the Blade form executes.
const template = fs.readFileSync(path.join(__dirname, '../../resources/views/purchases/create.blade.php'), 'utf8');
const begin = template.indexOf('const findRow = item =>');
const end = template.indexOf("document.getElementById('salePriceChangeConfirmed')", begin);
assert.ok(begin >= 0 && end > begin, 'Sale-price confirmation predicate must exist');
const block = template.slice(begin, end);
const detect = new Function('rows', 'productCardsEl', 'IS_EDIT_PURCHASE', block + '\nreturn saleChanges;');

function changes({ prior = undefined, current, submitted, edit }) {
    const row = {
        dataset: { variantId: '1', originalPurchaseSellPrice: prior },
        querySelector: () => ({ dataset: { salePrice: String(current) } }),
    };
    const productCardsEl = { querySelectorAll: () => [row] };
    return detect([{ variant_id: 1, quantity: 1, sell_price: submitted }], productCardsEl, edit).length;
}

assert.equal(changes({ current: 0, submitted: 4_800_000, edit: false }), 1, 'Initial pricing must request approval');
assert.equal(changes({ current: 4_800_000, submitted: 4_800_000, edit: false }), 0);
assert.equal(changes({ current: 4_800_000, submitted: 5_100_000, edit: false }), 1);
assert.equal(changes({ prior: 5_000_000, current: 4_800_000, submitted: 5_000_000, edit: true }), 0);
assert.equal(changes({ prior: 5_000_000, current: 4_800_000, submitted: 5_100_000, edit: true }), 1);
console.log('OK: purchase form consent detection (5 cases)');
