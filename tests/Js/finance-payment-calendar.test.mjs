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

test('RTL month arrows use explicit SVG geometry instead of bidirectionally mirrored text', () => {
    const nav=view.match(/<div class="month-nav"[\s\S]*?<\/div>/)?.[0];
    assert.ok(nav, 'month navigation must exist');
    assert.match(nav, /class="month-nav" dir="rtl"/);
    assert.match(nav, /id="calPrev"[^>]*aria-label="ماه قبل"[^>]*><svg[^>]*><path d="m9 18 6-6-6-6"/);
    assert.match(nav, /id="calNext"[^>]*aria-label="ماه بعد"[^>]*><svg[^>]*><path d="m15 18-6-6 6-6"/);
    assert.doesNotMatch(nav, /(?:›|‹)/, 'RTL-mirroring characters must not be used for month direction');
    assert.match(app, /'calPrev'\)\.addEventListener\('click',\(\)=>moveMonth\(-1\)\)/);
    assert.match(app, /'calNext'\)\.addEventListener\('click',\(\)=>moveMonth\(1\)\)/);
});

test('calendar month metadata handles month transitions and leap Esfand accurately', () => {
    const root = {};
    runInNewContext(dateCode, { window:root, Intl, Date, Math, Number, String, Error });
    const date=root.FinanceDate;
    for(const month of [1,6,7,11,12]) {
        const info=date.jalaliMonthInfo(1405,month);
        assert.ok(info.days>=29&&info.days<=31);
        assert.ok(info.firstWeekday>=0&&info.firstWeekday<=6);
        assert.equal(info.firstWeekday,(new Date(info.firstDay*86400000).getUTCDay()+1)%7);
        const next=month===12?'1406/01/01':'1405/'+String(month+1).padStart(2,'0')+'/01';
        assert.equal(info.days,date.toGregorianDay(next)-date.toGregorianDay('1405/'+String(month).padStart(2,'0')+'/01'));
    }
    assert.equal(date.jalaliMonthInfo(1403,12).days,30);
    assert.equal(date.jalaliMonthInfo(1404,12).days,29);
    assert.throws(()=>date.jalaliMonthInfo(1405,13),/ماه یا سال/);
});

test('calendar is legible, supports Persian input, blocks out-of-range cheque dates and restores focus', () => {
    assert.match(app, /yearNumber=new Intl.NumberFormat\('fa-IR',\{useGrouping:false\}\)/);
    assert.match(app, /yearNumber\.format\(calYear\)/);
    assert.match(app, /toLatin\(input\.value\.trim\(\)\)/);
    assert.match(app, /btn\.disabled=!inRange/);
    assert.match(app, /const slots=42/);
    assert.match(app, /document\.createDocumentFragment\(\)/);
    assert.match(app, /pickedInput\.focus\(\{preventScroll:true\}\)/);
});
