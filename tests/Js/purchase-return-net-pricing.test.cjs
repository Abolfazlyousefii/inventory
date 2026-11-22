const {test} = require('node:test');
const assert = require('node:assert/strict');
require('../../public/js/purchase-returns.js');
const {netRefund} = globalThis.PurchaseReturnPricing;

test('allocates both invoice and line discounts over successive partial returns', () => {
    assert.equal(netRefund('8500', 10, 0, '0', 4), 3400n);
    assert.equal(netRefund('8500', 10, 4, '3400', 6), 5100n);
});
test('does not refund a rounding rial again when an earlier return was cancelled', () => {
    assert.equal(netRefund('10', 3, 2, '7', 1), 3n);
});
test('retains exact large integer monetary amounts', () => {
    assert.equal(netRefund('9000000000000001', 3, 0, '0', 1), 3000000000000000n);
    assert.equal(netRefund('9000000000000001', 3, 1, '3000000000000000', 2), 6000000000000001n);
});
test('rejects inconsistent historical refunds and quantity above purchase capacity', () => {
    assert.throws(() => netRefund('10', 3, 2, '11', 1), RangeError);
    assert.throws(() => netRefund('10', 3, 2, '7', 2), RangeError);
});

test('normalizes Persian and Arabic digits in quantity inputs', () => {
    const {latin, quantityError} = globalThis.PurchaseReturnPricing;
    assert.equal(latin('۱۲٣٤'), '1234');
    assert.equal(quantityError('۳', 5, 3, 5), '');
    assert.equal(quantityError('۱۲', 10, 12, 20), 'تعداد از حداکثر قابل برگشت بیشتر است.');
    assert.notEqual(quantityError('۳.۵', 10, 0, 20), '');
    assert.notEqual(quantityError('-1', 10, 0, 20), '');
});
test('validates combined free stock for repeated variants', () => {
    const {quantityError} = globalThis.PurchaseReturnPricing;
    // Two source lines each individually fit; their total does not.
    assert.notEqual(quantityError('۴', 6, 8, 6), '');
    assert.notEqual(quantityError('۴', 6, 8, 6), '');
    assert.equal(quantityError('۳', 6, 6, 6), '');
});
