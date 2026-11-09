const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const viewPath = path.join(__dirname, '../../resources/views/purchases/create.blade.php');
const viewSource = fs.readFileSync(viewPath, 'utf8');

function inlinePurchaseScript() {
    const script = [...viewSource.matchAll(/<script>([\s\S]*?)<\/script>/g)]
        .map((match) => match[1])
        .find((candidate) => candidate.includes('const products = @json'));

    assert.ok(script, 'purchase form inline script should exist');

    let normalized = script;
    const bindings = {
        cnyRate: 'null',
        products: '[]',
        categories: '[]',
        initialItems: '[]',
        IS_EDIT_PURCHASE: 'false',
        CAN_CHANGE_SALE_PRICE: 'false',
        variantsUrlTemplate: '""',
    };

    for (const [name, value] of Object.entries(bindings)) {
        const pattern = new RegExp('^(\\s*const ' + name + ' = ).*;$','m');
        assert.match(normalized, pattern, 'Blade binding for ' + name + ' should exist');
        normalized = normalized.replace(pattern, (_match, prefix) => prefix + value + ';');
    }

    return normalized;
}

test('purchase form inline JavaScript parses after server-side Blade bindings are resolved', () => {
    assert.doesNotThrow(() => new vm.Script(inlinePurchaseScript(), {
        filename: 'resources/views/purchases/create.blade.php',
    }));
});

test('sale-price approval compares against the persisted purchase snapshot and uses the in-app modal', () => {
    const script = inlinePurchaseScript();

    assert.match(viewSource, /'original_sell_price'\s*=>\s*\\App\\Support\\Currency::toRial\(\$it->sell_price\)/);
    assert.match(script, /item\.original_sell_price/);
    assert.match(script, /salePriceChangeReasonHidden\.value\.trim\(\)\s*!==\s*''/);
    assert.match(script, /purchaseForm\.requestSubmit\(\)/);
    assert.doesNotMatch(script, /prompt\('دلیل تغییر قیمت فروش/);
    assert.doesNotMatch(script, /confirm\('تغییر قیمت فروش جاری/);
});
