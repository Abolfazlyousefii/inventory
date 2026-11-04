const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

// Exercise the exact persisted-price comparison used by the purchase form.
const template = fs.readFileSync(path.join(__dirname, '../../resources/views/purchases/create.blade.php'), 'utf8');
const begin = template.indexOf('function findVariantRow(item) {');
const end = template.indexOf('function salePriceChangeSignature(changes) {', begin);
assert.ok(begin >= 0 && end > begin, 'Purchase sale-price comparison functions must exist');

const block = template.slice(begin, end);
const detect = new Function(
    'rows',
    'productCardsEl',
    'IS_EDIT_PURCHASE',
    'escapeHtml',
    block + '\nreturn getSalePriceChanges(rows);'
);

function changes({ prior, current, submitted, edit }) {
    const row = {
        dataset: {
            variantId: '1',
            ...(prior === undefined ? {} : { originalPurchaseSellPrice: String(prior) }),
        },
        querySelector(selector) {
            if (selector === '[data-current-sale-price]') {
                return { dataset: { salePrice: String(current) } };
            }
            if (selector === '.variant-title') return { textContent: 'Test variant' };
            if (selector === '.mono') return { textContent: 'TEST-001' };
            return null;
        },
        closest() {
            return { querySelector: () => ({ textContent: 'Test product' }) };
        },
    };
    const productCardsEl = { querySelectorAll: () => [row] };
    return detect(
        [{ variant_id: 1, quantity: 1, sell_price: submitted }],
        productCardsEl,
        edit,
        value => String(value)
    );
}

// A normal edit must not ask for confirmation when persisted and submitted
// prices match, even when the current catalog price is different.
assert.equal(changes({ prior: 4_800_000, current: 4_800_000, submitted: 4_800_000, edit: true }).length, 0);
assert.equal(changes({ prior: 4_800_000, current: 5_000_000, submitted: 4_800_000, edit: true }).length, 0);

// After a validation redirect, old input may contain a changed submitted
// price, but the UI baseline must still come from the persisted DB snapshot.
const redirectedChange = changes({ prior: 4_800_000, current: 4_800_000, submitted: 5_100_000, edit: true });
assert.equal(redirectedChange.length, 1);
assert.equal(redirectedChange[0].originalPrice, 4_800_000);
assert.equal(redirectedChange[0].proposedPrice, 5_100_000);

// A genuinely new live-catalog price still requires deliberate approval.
assert.equal(changes({ current: 4_800_000, submitted: 4_800_000, edit: false }).length, 0);
assert.equal(changes({ current: 4_800_000, submitted: 5_100_000, edit: false }).length, 1);
console.log('OK: purchase persisted-price baseline and confirmation (5 scenarios)');
