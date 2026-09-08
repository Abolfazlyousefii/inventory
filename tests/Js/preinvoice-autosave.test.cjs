const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../resources/views/preinvoice/create.blade.php'), 'utf8');

function editor(fetch) {
    const values = {
        customer_id: '1', customer_name: 'Customer', customer_mobile: '09120000000',
        payment_terms_note: '', orderDiscountType: 'amount', orderDiscountValue: '0',
        discount: '0', discount_breakdown: '{}', autosave_uuid: ''
    };
    const nodes = Object.fromEntries(Object.entries(values).map(([key, value]) => [key, {value}]));
    const context = vm.createContext({
        fetch, Headers, Response, console, JSON, Number, Object, Error, Promise,
        document: {
            getElementById: id => nodes[id],
            querySelector: () => ({content: 'csrf'})
        },
        API: {autosave: '/preinvoice/autosave'},
        IS_EDIT: false, isBootingPage: false, isHydratingLocalDraft: false, isSubmittingProgrammatically: false,
        currentAutosaveUuid: '12345', currentAutosaveVersion: 'version-1',
        autosaveQueue: Promise.resolve(), confirmedAutosavePayload: null, autosaveConflict: false,
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
        renderGroupSummary: () => {}, updateTotal: () => {}, scheduleLocalDraftSave: () => {},
        scheduleDbAutosave: () => {}, clearVisibleFormOnly: () => {}, setReservationMode: () => {},
        getProductDetails: async () => null, getProductVarieties: () => [],
        productTitle: () => '', productCode: () => '', updateSubmitState: () => {}
    });
    for (const name of ['fetchJson', 'collectProductsForAutosave', 'collectAutosavePayload',
        'confirmAutosaveChanges', 'saveDbAutosaveNow', 'persistDbAutosave', 'deleteGroup', 'applyDbAutosaveDraft']) {
        const start = source.search(new RegExp(`    (?:async )?function ${name}\\(`));
        assert.notEqual(start, -1, `Missing editor function ${name}`);
        const rest = source.slice(start + 1);
        const end = rest.search(/\n    (?:async )?function /);
        vm.runInContext(source.slice(start, start + 1 + end), context);
    }
    context.nodes = nodes;
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

test('recovery preserves quantities, zero prices, discounts and the server version', async () => {
    const context = editor(async () => { throw new Error('Unexpected network write'); });
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
