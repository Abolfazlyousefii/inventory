// Run against server-rendered, read-only HTML fixtures. All HTTP requests are intercepted.
// node tests/browser/personnel-voucher.cjs <fixture-directory>
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixtures = path.resolve(process.argv[2]);
const publicRoots = [path.resolve('public'), process.env.VOUCHER_PUBLIC_FALLBACK].filter(Boolean);
(async () => {
    const browser = await chromium.launch({headless: true, channel: 'chrome'});
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', e => errors.push(e.message));
        let failVariants = true;
        let failStage = '';
        let noChildren = false;
        let delayVariants = false;
        let variantCalls = 0;
        let categoryCalls = 0;
        let mutations = 0;
        let restore = false;
        await page.route('**/*', async route => {
            const request = route.request();
            const url = new URL(request.url());
            if (request.method() !== 'GET') { mutations++; return route.abort(); }
            if (url.pathname === '/fixture' || url.pathname === '/hub') {
                let body = fs.readFileSync(path.join(fixtures, `voucher-candidate-${url.pathname === '/hub' ? 'hub' : 'create'}.html`), 'utf8');
                body = body.replace(/const rootCategories = (.*);/, (_, json) => {
                    const roots = JSON.parse(json);
                    for (const root of roots) {
                        assert(Array.isArray(root.children), 'The rendered form must contain its subcategories');
                        root.children = noChildren ? [] : [{id: 901, name: 'آزمایشی'}];
                    }
                    return `const rootCategories = ${JSON.stringify(roots)};`;
                });
                if (restore) body = body.replace(/const initialItems = .*;/, `const initialItems = ${JSON.stringify([{root_category_id: root, category_id: 901, product_id: 902, product_label: 'کالای اول', variant_id: 904, variant_label: 'مدل اول', quantity: 2, personnel_asset_code: '2039'}])};`);
                return route.fulfill({contentType: 'text/html', body});
            }
            if (url.pathname.includes('/categories/')) { categoryCalls++; return route.fulfill({status: 503, json: {}}); }
            if (failStage === 'products' && url.pathname.endsWith('/products/search')) return route.fulfill({status: 503, json: {}});
            if (url.pathname.endsWith('/products/search')) return route.fulfill({json: {results: [{id: 902, text: 'کالای اول'}, {id: 903, text: 'کالای دوم'}]}});
            if (url.pathname.endsWith('/variants')) {
                variantCalls++;
                if (delayVariants && url.pathname.includes('/902/')) await new Promise(resolve => setTimeout(resolve, 500));
                if (failVariants) return route.fulfill({status: 500, json: {message: 'Simulated failure'}});
                const second = url.pathname.includes('/903/');
                return route.fulfill({json: {results: [{id: second ? 905 : 904, text: second ? 'مدل دوم' : 'مدل اول', central_stock: 8, previous_quantity: 2, available_for_edit: 10}]}});
            }
            for (const root of publicRoots) {
                const file = path.resolve(root, '.' + decodeURIComponent(url.pathname));
                if (file.startsWith(root + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) return route.fulfill({path: file});
            }
            return route.fulfill({json: {}});
        });
        await page.goto('http://localhost/fixture');
        if (!restore) await page.locator('#addItemBtn').click();
        await page.waitForSelector('.root-cat');
        const root = await page.locator('.root-cat option').nth(1).getAttribute('value');
        await page.selectOption('.root-cat', root);
        await page.waitForFunction(() => document.querySelector('.sub-cat').options.length > 1);
        await page.selectOption('.sub-cat', '901');
        await page.waitForFunction(() => document.querySelector('.product-select').options.length > 1);
        await page.selectOption('.product-select', '902');
        await page.locator('.lookup-error button').waitFor();
        assert(!(await page.locator('.variant-select').textContent()).includes('در حال دریافت'));
        assert(await page.locator('.quantity-input').isDisabled());
        failVariants = false;
        await page.locator('.lookup-error button').click();
        await page.waitForFunction(() => document.querySelector('.variant-select').options.length > 1);
        await page.selectOption('.variant-select', '904');
        assert.equal(await page.locator('.quantity-input').getAttribute('max'), '10');
        await page.fill('.quantity-input', '11');
        assert.equal(await page.locator('.quantity-input').inputValue(), '11', 'Never silently change a quantity');
        assert((await page.locator('.row-error').textContent()).includes('حداکثر'));
        delayVariants = true;
        await page.selectOption('.product-select', '903');
        await page.selectOption('.product-select', '902');
        await page.selectOption('.product-select', '903');
        await page.waitForTimeout(700);
        assert((await page.locator('.variant-select').textContent()).includes('مدل دوم'));
        assert(!(await page.locator('.variant-select').textContent()).includes('مدل اول'));
        await page.selectOption('.variant-select', '905');
        await page.fill('.quantity-input', '2');
        await page.fill('.asset-code-input', '2039');
        const invalidPrevented = await page.evaluate(() => {
            const event = new Event('submit', {cancelable: true});
            document.getElementById('personnelVoucherForm').dispatchEvent(event);
            return event.defaultPrevented;
        });
        assert(invalidPrevented, 'Receiver is required');
        await page.locator('.save-item').click();
        assert.equal(await page.locator('.item-toggle').getAttribute('aria-expanded'), 'false');
        await page.locator('#addItemBtn').click();
        assert.equal(await page.locator('#itemsTable tbody tr').count(), 2);
        assert.equal(await page.locator('.item-toggle').first().getAttribute('aria-expanded'), 'false');
        assert(!(await page.locator('.product-select').first().isVisible()));
        assert((await page.locator('.item-summary-name').first().textContent()).includes('کالای دوم'));
        await page.locator('.item-toggle').first().click();
        assert.equal(await page.locator('.quantity-input').first().inputValue(), '2');
        assert.equal(await page.locator('.asset-code-input').first().inputValue(), '2039');
        await page.locator('.remove-row').first().click();
        assert.equal(await page.locator('.product-select').getAttribute('name'), 'items[0][product_id]');
        // Edit/old-input restoration must retain quantity, asset code and selected model.
        restore = true;
        delayVariants = false;
        await page.goto('http://localhost/fixture');
        if (!restore) await page.locator('#addItemBtn').click();
        await page.waitForFunction(() => document.querySelector('.variant-select').value === '904');
        if (await page.locator('.item-toggle').getAttribute('aria-expanded') === 'false') await page.locator('.item-toggle').click();
        assert.equal(await page.locator('.quantity-input').inputValue(), '2');
        assert.equal(await page.locator('.asset-code-input').inputValue(), '2039');
        const receiver = await page.locator('#receiverUserSelect option').nth(1).getAttribute('value');
        await page.selectOption('#receiverUserSelect', receiver);
        const valid = await page.evaluate(() => {
            const event = new Event('submit', {cancelable: true});
            document.getElementById('personnelVoucherForm').dispatchEvent(event);
            return !event.defaultPrevented;
        });
        assert(valid, 'A complete, restored voucher passes client validation');
        if (await page.locator('.item-toggle').getAttribute('aria-expanded') === 'false') await page.locator('.item-toggle').click();
        await page.locator('.save-item').click();
        for (const width of [1366, 390]) {
            await page.setViewportSize({width, height: 1000});
            await page.goto('http://localhost/fixture');
            await page.waitForFunction(() => document.querySelector('.item-toggle')?.getAttribute('aria-expanded') === 'false');
            await page.selectOption('#receiverUserSelect', receiver);
            assert(!(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)));
            await page.screenshot({path: path.join(fixtures, `voucher-saved-${width}.png`), fullPage: true});
        }
        const payload = await page.evaluate(() => Object.fromEntries(new FormData(document.getElementById('personnelVoucherForm'))));
        assert.equal(payload['items[0][quantity]'], '2', 'Collapsed fields remain in the submitted form');
        assert.equal(payload['items[0][variant_id]'], '904');
        await page.evaluate(() => {
            document.querySelector('.asset-code-input').value = '';
            document.getElementById('personnelVoucherForm').dispatchEvent(new Event('submit', {cancelable: true}));
        });
        assert.equal(await page.locator('.item-toggle').getAttribute('aria-expanded'), 'true', 'Validation errors reveal the row');
        assert(!(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)));
        await page.screenshot({path: path.join(fixtures, 'voucher-editor-390.png'), fullPage: true});
        restore = false;
        // Both upstream selectors recover too, including root categories with no children.
        await page.goto('http://localhost/fixture');
        if (!restore) await page.locator('#addItemBtn').click();
        await page.selectOption('.root-cat', root);
        await page.waitForFunction(() => document.querySelector('.sub-cat').options.length > 1);
        assert.equal(categoryCalls, 0, 'Subcategory selection must work even when its endpoint is unavailable');
        failStage = 'products';
        await page.selectOption('.sub-cat', '901');
        await page.locator('.lookup-error button').waitFor();
        failStage = '';
        await page.locator('.lookup-error button').click();
        await page.waitForFunction(() => document.querySelector('.product-select').options.length > 1);
        noChildren = true;
        await page.goto('http://localhost/fixture');
        await page.locator('#addItemBtn').click();
        await page.selectOption('.root-cat', root);
        await page.waitForFunction(() => document.querySelector('.product-select').options.length > 1);
        assert.equal(await page.locator('.cat-hidden').inputValue(), root);
        noChildren = false;
        for (const width of [1366, 768, 390]) {
            await page.setViewportSize({width, height: 1000});
            for (const screen of ['fixture', 'hub']) {
                await page.goto(`http://localhost/${screen}`);
                await page.waitForTimeout(150);
                const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
                assert(!overflow, `${screen} overflows at ${width}px`);
                await page.screenshot({path: path.join(fixtures, `voucher-${screen}-${width}.png`), fullPage: true});
            }
        }
        assert.equal(categoryCalls, 0);
        assert.equal(mutations, 0);
        assert.deepEqual(errors, []);
        console.log(JSON.stringify({passed: true, variantCalls, browserErrors: errors.length, mutations, widths: [1366, 768, 390]}));
    } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exit(1);});
