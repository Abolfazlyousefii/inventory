import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const view = readFileSync(new URL('../../resources/views/finance/payment-calculator/index.blade.php', import.meta.url), 'utf8');
const app = readFileSync(new URL('../../public/js/finance-payment-quick.js', import.meta.url), 'utf8');
const dateCode = readFileSync(new URL('../../public/js/finance-payment-date-utils.js', import.meta.url), 'utf8');

test('the new shared Jalali calendar has date-only controls in all three tabs', () => {
    assert.match(view, /id="calendarDialog"/);
    assert.match(view, /id="baseDateInput"/);
    assert.match(view, /id="calendarDays"/);
    assert.match(view, /data-calendar-for="baseDateInput"/);
    const calendar = view.split('id="calendarDialog"')[1].split('</dialog>')[0];
    assert.doesNotMatch(calendar, /type=["'](?:time|datetime-local)["']/i);
    assert.doesNotMatch(calendar, /(?:ساعت|دقیقه|ثانیه)/);
    assert.match(app, /function openCalendar\(/);
    assert.match(app, /function chooseDate\(/);
    assert.doesNotMatch(app, /jalaliDatepicker\.startWatch/);
});

test('a due date only 10 days after the base date is supported', () => {
    const root = {};
    runInNewContext(dateCode, { window: root, Intl, Date, Math, Number, String, Error });
    const converter = root.FinanceDate;
    const first = '1405/08/14';
    const due = converter.jalaliAfterDays(first, 10);
    assert.equal(converter.dayDifference(first, due), 10);
    assert.throws(() => converter.dayDifference(first, '1405/08/12'), /سررسید/);
});
