const test = require('node:test');
const assert = require('node:assert/strict');
const csv = require('../../public/js/product-stock-csv.js');

test('Excel CSV has UTF-8 BOM, explicit comma delimiter and row-level stock columns', () => {
    const header = csv.header();
    assert.ok(header.startsWith('\ufeffsep=,\r\n'));
    assert.match(header, /موجودی \(عدد\)/);
    assert.match(header, /کد تنوع \/ بارکد/);
    const data = csv.rowsCsv([{
        product_id: 12, product_name: 'قاب، آیفون',
        variant_id: 33, variant_name: 'مشکی "اصلی"',
        stock: 5, sell_price_rial: 400000, reserved: 2, sales_enabled: true,
    }]);
    assert.match(data, /"قاب، آیفون"/);
    assert.match(data, /"مشکی ""اصلی"""/);
    assert.match(data, /,5,2,400000,"بله"/);
    assert.ok(data.endsWith('\r\n'));
});

test('stock export escapes Excel formulas without breaking numeric amounts', () => {
    assert.equal(csv.cell('=HYPERLINK("https://evil")'), '\'"=HYPERLINK(""https://evil"")"');
    assert.equal(csv.cell(' +SUM(1,1)'), '"\'+SUM(1,1)"');
    assert.equal(csv.cell('@cmd'), '"\'@cmd"');
    assert.equal(csv.cell(-42), '-42');
    assert.equal(csv.cell('12\nNew row'), '"12 New row"');
});

test('zero rows can produce a header-only document without nonzero progress', () => {
    assert.equal(csv.rowsCsv([]), '');
    assert.ok(csv.header().includes('شناسه کالا'));
});
