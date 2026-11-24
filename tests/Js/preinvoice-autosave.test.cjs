const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../resources/views/preinvoice/create.blade.php'), 'utf8');

function loadEditorFunctions(context, names) {
    for (const name of names) {
        const found = new RegExp(`^([\t ]+)(?:async )?function ${name}\\(`, 'm').exec(source);
        assert.ok(found, `Missing editor function ${name}`);
        const bodyStart = found.index + found[0].length;
        const remaining = source.slice(bodyStart);
        const end = remaining.search(new RegExp(`\\n${found[1]}(?:async )?function `));
        assert.notEqual(end, -1, `Missing end of editor function ${name}`);
        vm.runInContext(source.slice(found.index, bodyStart + end), context);
    }
}

function editor(fetch, options = {}) {
    const values = {
        customer_id: '1', customer_name: 'Customer', customer_mobile: '09120000000',
        payment_terms_note: '', orderDiscountType: 'amount', orderDiscountValue: '0',
        discount: '0', discount_breakdown: '{}', autosave_uuid: ''
    };
    if (!options.isEdit) { delete values.orderDiscountType; delete values.orderDiscountValue; }
    const nodes = Object.fromEntries(Object.entries(values).map(([key, value]) => [key, {value}]));
    let pendingTimer;
    const context = vm.createContext({
        fetch, Headers, Response, console, JSON, Number, Object, Error, Promise,
        RialInput: {number: Number}, jsonErrorMessage: (_errors, fallback) => fallback,
        structuredClone, localStorage: {setItem: () => {}}, RESERVATION_TOKEN_KEY: "reservation", LOCAL_DRAFT_KEY: "draft",
        setTimeout: fn => { pendingTimer = fn; return 1; }, clearTimeout: () => {},
        document: {
            getElementById: id => nodes[id],
            querySelector: () => ({content: 'csrf'})
        },
        API: {autosave: '/preinvoice/autosave'},
        IS_EDIT: !!options.isEdit, ENABLE_SERVER_AUTOSAVE: !options.isEdit || options.status === 'draft', isBootingPage: false, isHydratingLocalDraft: false, isSubmittingProgrammatically: false,
        currentAutosaveUuid: '12345', currentAutosaveVersion: 'version-1',
        autosaveQueue: Promise.resolve(), confirmedAutosavePayload: null, autosaveConflict: false,
        autosaveTimer: null, localDraftSaveTimer: null, autosaveDirty: false,
        groupedSelections: {
            1: {product: {id: 1, title: 'First'}, items: [{variant_id: 11, quantity: 2, price: 100}]},
            2: {product: {id: 2, title: 'Second'}, items: [{variant_id: 22, quantity: 2, price: 100}]}
        },
        ensureReservationToken: () => 'token', currentIsInPerson: () => false, toInt: Number,
        hasAnyFormData: () => true, currentCsrfToken: () => 'csrf',
        preserveDraftAfterSessionChange: () => {}, sessionChangedMessage: () => 'session',
        CsrfMismatchError: Error, SessionChangedError: Error,
        getLocalDraft: () => null, updateLocalDraftStatus: () => {},
        syncDraftReservation: async () => {}, confirm: () => true, alert: () => {},
        showLocalDraftBanner: () => {},
        renderGroupSummary: () => {}, updateTotal: () => {}, scheduleLocalDraftSave: () => {},
        scheduleDbAutosave: () => {}, clearVisibleFormOnly: () => {}, setReservationMode: () => {},
        getProductDetails: async () => null, getProductVarieties: () => [],
        productTitle: () => '', productCode: () => '', updateSubmitState: () => {}
    });
    loadEditorFunctions(context, ['fetchJson', 'collectProductsForAutosave', 'collectAutosavePayload',
        'confirmAutosaveChanges', 'saveDbAutosaveNow', 'persistDbAutosave', 'scheduleDbAutosave',
        'saveLocalDraftNow', 'scheduleLocalDraftSave', 'deleteGroup', 'requiresDiscountEditor', 'applyDbAutosaveDraft']);
    context.nodes = nodes;
    context.runScheduledTimer = () => { assert.ok(pendingTimer, "Expected pending timer"); pendingTimer(); };
    return context;
}

function reply(data, status = 200) {
    return new Response(JSON.stringify(data), {status, headers: {'Content-Type': 'application/json'}});
}

test('serializes saves and uses the version acknowledged by the preceding request', async () => {
    const requests = [];
    let release;
    const context = editor(async (_url, options) => {
        requests.push(JSON.parse(options.body));
        if (requests.length === 1) await new Promise(resolve => { release = resolve; });
        return reply({ok: true, uuid: '12345', version: `version-${requests.length + 1}`});
    });
    const first = context.saveDbAutosaveNow();
    await new Promise(resolve => setImmediate(resolve));
    context.groupedSelections[1].items[0].quantity = 3;
    const second = context.saveDbAutosaveNow();
    assert.equal(requests.length, 1);
    release();
    await Promise.all([first, second]);
    assert.equal(requests[1].base_version, 'version-2');
    assert.equal(requests[1].products[0].quantity, 3);
});

test('ordinary autosave never follows a reduction challenge automatically', async () => {
    let count = 0;
    const context = editor(async () => {
        count++;
        return reply({ok: false, code: 'snapshot_reduction', confirmation_token: 'proof', message: 'preserved'}, 422);
    });
    await assert.rejects(context.saveDbAutosaveNow(), /preserved/);
    assert.equal(count, 1);
    assert.equal(context.currentAutosaveVersion, 'version-1');
});

test('the actual delete button authorizes only the exact snapshot challenged by the server', async () => {
    const requests = [];
    const context = editor(async (_url, options) => {
        const request = JSON.parse(options.body);
        requests.push(request);
        return request.confirmation_token
            ? reply({ok: true, uuid: '12345', version: 'version-2'})
            : reply({ok: false, code: 'snapshot_reduction', confirmation_token: 'proof'}, 422);
    });
    await context.deleteGroup(2);
    await context.saveDbAutosaveNow();
    assert.equal(requests.length, 2);
    assert.equal(requests[0].action, 'confirm_changes');
    assert.equal(requests[1].confirmation_token, 'proof');
    assert.deepEqual(requests[1].products, requests[0].products);
    assert.equal(requests[1].products.length, 1);
    assert.equal(context.confirmedAutosavePayload, null);
});

test('a payload changed after the user action does not inherit its confirmation', async () => {
    const requests = [];
    const context = editor(async (_url, options) => {
        requests.push(JSON.parse(options.body));
        return reply({ok: false, code: 'snapshot_reduction', confirmation_token: 'proof'}, 422);
    });
    await context.deleteGroup(2);
    context.nodes.customer_name.value = '';
    await assert.rejects(context.saveDbAutosaveNow());
    assert.equal(requests.length, 1);
    assert.equal(requests[0].action, 'autosave');
});

test('a conflict blocks further writes without adopting a newer server version', async () => {
    let count = 0;
    const context = editor(async () => {
        count++;
        return reply({message: 'conflict'}, 409);
    });
    await assert.rejects(context.saveDbAutosaveNow(), /conflict/);
    await assert.rejects(context.saveDbAutosaveNow());
    assert.equal(count, 1);
    assert.equal(context.currentAutosaveVersion, 'version-1');
    assert.equal(Object.keys(context.groupedSelections).length, 2);
});

test('malformed selections fail instead of silently dropping rows', async () => {
    let count = 0;
    const context = editor(async () => { count++; });
    context.groupedSelections[2].items[0].quantity = 0;
    await assert.rejects(context.saveDbAutosaveNow());
    assert.equal(count, 0);
});

test('deleting the final item is saved even when all customer fields are empty', async () => {
    const requests = [];
    const context = editor(async (_url, options) => {
        requests.push(JSON.parse(options.body));
        return reply({ok: true, uuid: '12345', version: 'version-2'});
    });
    context.hasAnyFormData = () => false;
    context.nodes.customer_id.value = '';
    context.nodes.customer_name.value = '';
    context.nodes.customer_mobile.value = '';
    delete context.groupedSelections[2];
    await context.deleteGroup(1);
    await context.saveDbAutosaveNow();
    assert.equal(requests.length, 1);
    assert.equal(requests[0].products.length, 0);
    assert.equal(requests[0].action, 'confirm_changes');
});

test('edit recovery preserves quantities, zero prices, discounts and the server version', async () => {
    const context = editor(async () => { throw new Error('Unexpected network write'); }, {isEdit: true, status: 'draft'});
    await context.applyDbAutosaveDraft({
        uuid: 'recovered', version: 'recovered-version',
        customer: {name: 'Saved customer', mobile: '09120000000'},
        discount: {type: 'amount', value: 5},
        discount_breakdown: {groups: [{product_id: 1, discount_type: 'percent', discount_value: 10}]},
        items: [
            {product_id: 1, variant_id: 11, quantity: 7, price: 100, line_discount_amount: 70},
            {product_id: 2, variant_id: 22, quantity: 3, price: 0}
        ]
    });
    assert.equal(context.currentAutosaveVersion, 'recovered-version');
    assert.equal(context.currentAutosaveUuid, 'recovered');
    assert.equal(context.groupedSelections[1].items[0].quantity, 7);
    assert.equal(context.groupedSelections[1].items[0].line_discount_amount, 70);
    assert.equal(context.groupedSelections[1].discount_value, 10);
    assert.equal(context.groupedSelections[2].items[0].price, 0);
});


test('timer-based autosave never auto-confirms a reduction', async () => {
    const requests = [];
    const context = editor(async (_url, options) => {
        requests.push(JSON.parse(options.body));
        return reply({ok: false, code: 'snapshot_reduction', confirmation_token: 'proof', message: 'preserved'}, 422);
    });
    context.scheduleDbAutosave(0);
    context.runScheduledTimer();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(requests.length, 1, 'A timer must not automatically retry a destructive update');
    assert.equal(requests[0].action, 'autosave');
    assert.equal(requests[0].confirmation_token, undefined);
});

test('edit-field autosave scheduling never counts as explicit confirmation', () => {
    const context = editor(async () => { throw new Error('unexpected request'); });
    context.IS_EDIT = true;
    context.scheduleLocalDraftSave();
    assert.equal(context.confirmedAutosavePayload, null);
    context.saveLocalDraftNow();
    assert.equal(context.confirmedAutosavePayload, null);
});


test('returned seller preinvoices use normal form submission and never call draft-only autosave', async () => {
    let requests = 0;
    const context = editor(async () => { requests++; throw new Error('Returned document must not autosave'); },
        {isEdit: true, status: 'returned_to_sales'});
    context.scheduleDbAutosave(0);
    await context.saveDbAutosaveNow();
    assert.equal(requests, 0);
    assert.equal(context.autosaveDirty, false);
    assert.equal(context.autosaveConflict, false);
    // The existing submitGuard continues to the official PUT form submission.
    assert.match(source, /await saveDbAutosaveNow\(\);[\s\S]*?document\.getElementById\('orderForm'\)\.submit\(\)/);
});

test('manual edits to ordinary drafts still use the protected versioned autosave', async () => {
    let requests = 0;
    const context = editor(async () => { requests++; return reply({ok: true, uuid: '12345', version: 'version-2'}); },
        {isEdit: true, status: 'draft'});
    await context.saveDbAutosaveNow();
    assert.equal(requests, 1);
});

test('edit boot data contains document status so returned orders can skip draft autosave', () => {
    assert.match(source, /orderStatus:\s*@json\(\$order->status\s*\?\?\s*null\)/);
    assert.match(source, /const ENABLE_SERVER_AUTOSAVE\s*=\s*!IS_EDIT\s*\|\|\s*window\.PREINVOICE_BOOT\.orderStatus\s*===\s*'draft'/);
});


test('create recovery preserves a discounted server draft rather than silently removing its discounts', async () => {
    const context = editor(async () => { throw new Error('Unexpected network write'); });
    let bannerShown = false;
    context.showLocalDraftBanner = () => { bannerShown = true; };
    await context.applyDbAutosaveDraft({uuid: 'discounted', version: 'new-version',
        discount: {type: 'percent', value: 10}, items: []});
    assert.equal(bannerShown, true);
    assert.equal(context.currentAutosaveUuid, '12345');
    assert.equal(context.currentAutosaveVersion, 'version-1');
    assert.equal(context.groupedSelections[1].items[0].quantity, 2);
    assert.equal(context.requiresDiscountEditor({discount_breakdown: {groups: [{discount_value: 25}]}}), true);
    assert.equal(context.requiresDiscountEditor({items: [{line_discount_amount: 25}]}), true);
});

test('create recovery without discounts works without discount input elements', async () => {
    const context = editor(async () => { throw new Error('Unexpected network write'); });
    await context.applyDbAutosaveDraft({uuid: 'recovered', version: 'new-version',
        discount: {type: 'percent', value: 0}, items: [{product_id: 1, variant_id: 11, quantity: 3, price: 100}]});
    assert.equal(context.currentAutosaveUuid, 'recovered');
    assert.equal(context.groupedSelections[1].items[0].quantity, 3);
    assert.equal(context.collectAutosavePayload().invoice_discount_value, 0);
});

test('create totals and picker summary do not use discount math or discount controls', () => {
    const context = editor(async () => { throw new Error('Unexpected network write'); });
    Object.assign(context.nodes, Object.fromEntries(['total_price', 'modalTotalAmount', 'modalSelectedRows', 'modalTotalQty', 'saveGroupSelectionBtn'].map(id => [id, {}])));
    Object.assign(context, {formatMoney: String, formatNum: String,
        modalQuantities: new Map([[11, 3]]), pickerSourceRows: () => [{id: 11}], pickerKey: v => v.id,
        variantPrice: () => 100, activeProduct: {}, scheduleLocalDraftSave: () => {},
        calcDiscount: () => { throw new Error('Create must not calculate discounts'); }});
    // Load the actual functions from the shared Blade template, with no discount DOM nodes.
    loadEditorFunctions(context, ['groupRawSubtotal', 'groupDiscountTotal', 'groupFinalAmount', 'buildDiscountBreakdown', 'updateTotal', 'updateModalSummary']);
    context.groupedSelections[1].discount_value = 50;
    context.updateTotal();
    context.updateModalSummary();
    assert.equal(context.nodes.total_price.value, '400');
    assert.equal(context.nodes.modalTotalAmount.textContent, '300');
    assert.equal(context.nodes.discount.value, '0');
    const breakdown = JSON.parse(context.nodes.discount_breakdown.value);
    assert.deepEqual(breakdown.groups, []);
    assert.equal(breakdown.order_discount_value, 0);
    assert.equal(breakdown.total_discount_amount, 0);
});
