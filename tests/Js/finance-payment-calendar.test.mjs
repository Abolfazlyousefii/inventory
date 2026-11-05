import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const view = readFileSync(
    new URL('../../resources/views/finance/payment-calculator/index.blade.php', import.meta.url),
    'utf8',
);

test('every Jalali date input in the finance calculator is date-only', () => {
    const inputs = view.match(/<input\b[^>]*\bdata-jdp\b[^>]*>/g) ?? [];

    // The base date and both template due-date fields must stay date-only,
    // including cloned cheque rows appended at runtime.
    assert.equal(inputs.length, 3, 'expected the base date, fixed due date, and weighted due date inputs');
    for (const input of inputs) {
        assert.match(
            input,
            /\bdata-jdp-only-date\b/,
            'a finance date field is missing data-jdp-only-date and inherits the global time picker',
        );
    }
});
