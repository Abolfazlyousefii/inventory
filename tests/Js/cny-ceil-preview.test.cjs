const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

// Tests the integer-safe formula in the Blade preview (no public JS file was
// shipped in the user's ZIP, so its original asset is left alone).
const blade = fs.readFileSync(path.resolve(__dirname,
    '../../resources/views/purchases/create.blade.php'), 'utf8');

test('the CNY purchase preview computes the same ceil formula as the server', () => {
    const formula = blade.match(/const numerator = BigInt\(quote\.units\)[^\n]*\n\s*const denominator =[^\n]*\n\s*quote\.sale =[^\n]*/);
    assert.ok(formula, 'Blade must compute the selling price with bigint and ceil');
    const sale = (units, rate) => {
        const quote = { units };
        const cnyRate = { rate_rial: rate };
        eval(formula[0]); // a tested excerpt of the trusted local Blade source
        return quote.sale;
    };
    assert.equal(sale(50000, 400000), 2920000);
    assert.equal(sale(50000, 390000), 2850000);
    assert.equal(sale(25125, 400000), 1470000);
});
