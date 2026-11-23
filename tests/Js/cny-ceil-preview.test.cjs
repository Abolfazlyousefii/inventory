const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const blade = fs.readFileSync(path.resolve(__dirname, '../../resources/views/purchases/create.blade.php'), 'utf8');
const vm = require('node:vm');
const context = {window: {}};
vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '../../public/js/cny-pricing.js'), 'utf8'), context);
const {quote} = context.window.AriyaCny;

test('the CNY preview takes the selected margin and uses the same calculator as the backend', () => {
    assert.match(blade, /window\.AriyaCny\.quote\(input\.value, Number\(cnyRate\.rate_rial\), margin\.value\)/);
    assert.match(blade, /data-cny-margin/);
    assert.match(blade, /cny_margin: window\.AriyaCny\.latin/);
    assert.match(blade, /cny_margin:card\.querySelector/);
    assert.match(blade, /margin\.required = input\.required/);
    assert.doesNotMatch(blade, /const numerator = BigInt\(quote\.units\)/);
    assert.equal(quote('5', 400000, '20').sale, 2550000);
    assert.throws(() => quote('5', 400000, '-20'));
    assert.throws(() => quote('5', 400000, '0'));
    assert.match(blade, /درصد حاشیه سود مثبت/);
    assert.doesNotMatch(blade, /مثبت یا منفی|عدد منفی قیمت را کاهش/);
});

test('editing a yuan purchase lets the operator change both values and refreshes recorded prices on input only', () => {
    assert.doesNotMatch(blade, /input\.readOnly\s*=\s*IS_EDIT_PURCHASE/);
    assert.doesNotMatch(blade, /margin\.readOnly\s*=\s*IS_EDIT_PURCHASE/);
    assert.match(blade, /refreshCnyCard\(card, !IS_EDIT_PURCHASE \|\| target\.matches\('\[data-cny-buy\],\[data-cny-margin\]'\)\)/);
    assert.match(blade, /function refreshCnyCard\(card,\s*writePrices\s*=\s*!IS_EDIT_PURCHASE\)/);
    assert.match(blade, /if \(writePrices\)/);
});
