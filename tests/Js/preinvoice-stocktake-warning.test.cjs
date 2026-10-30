const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const helperPath = path.join(__dirname, '../../public/js/preinvoice-stocktake-warning.js');
const source = fs.existsSync(helperPath) ? fs.readFileSync(helperPath, 'utf8') : '';
const form = fs.readFileSync(path.join(__dirname, '../../resources/views/preinvoice/create.blade.php'), 'utf8');

function fixture() {
    const make = () => ({hidden: true, textContent: '', children: [],
        append(item) {this.children.push(item);},
        replaceChildren(...items) {this.children = items;},
        set innerHTML(_value) {throw new Error('Unsafe HTML assignment');},
    });
    const nodes = Object.fromEntries(['preinvoiceStocktakeWarning', 'preinvoiceStocktakeWarningMessage', 'preinvoiceStocktakeWarningItems'].map(id => [id, make()]));
    const submit = {disabled: false};
    const initialItemNotice = {hidden: false};
    const selections = [{product_id: 1, quantity: 2}, {product_id: 2, quantity: 3}];
    const context = vm.createContext({document: {getElementById(id) {return nodes[id];}, createElement() {return make();}, querySelector() {return {content: 'csrf'};}, querySelectorAll() {return [initialItemNotice];}}, submit, selections});
    vm.runInContext(source, context);
    return {context, nodes, submit, selections, initialItemNotice};
}

test('renders the backend warning and all blocked names as inert text without changing sales selections', () => {
    const ui = fixture();
    assert.ok(ui.context.PreinvoiceStocktakeWarning, 'warning renderer exists');
    const before = JSON.stringify(ui.selections);
    ui.context.PreinvoiceStocktakeWarning.render({message: '<script>not executable</script> اقلام بسته را حذف کنید یا پیش‌نویس نگه دارید.', blocked_product_ids: [1], blocked_product_names: ['<img onerror=evil()> ایرپاد', 'هندزفری'], category_names: ['ایرپاد']});
    assert.equal(ui.nodes.preinvoiceStocktakeWarning.hidden, false);
    assert.match(ui.nodes.preinvoiceStocktakeWarningMessage.textContent, /^<script>/);
    assert.equal(ui.nodes.preinvoiceStocktakeWarningItems.children.length, 2);
    assert.equal(ui.nodes.preinvoiceStocktakeWarningItems.children[0].textContent, '<img onerror=evil()> ایرپاد');
    assert.equal(ui.submit.disabled, false);
    assert.equal(JSON.stringify(ui.selections), before);
});

test('explicit null clears stale warnings after manual removal while leaving the document intact', () => {
    const ui = fixture();
    assert.ok(ui.context.PreinvoiceStocktakeWarning, 'warning renderer exists');
    ui.context.PreinvoiceStocktakeWarning.render({message: 'Closed category', blocked_product_names: ['Airpod']});
    ui.context.PreinvoiceStocktakeWarning.render(null);
    assert.equal(ui.nodes.preinvoiceStocktakeWarning.hidden, true);
    assert.equal(ui.nodes.preinvoiceStocktakeWarningMessage.textContent, '');
    assert.equal(ui.nodes.preinvoiceStocktakeWarningItems.children.length, 0);
    assert.equal(ui.initialItemNotice.hidden, true, 'a server-rendered old blocked-item list must not remain after removal');
    assert.equal(ui.selections.length, 2);
    assert.equal(ui.submit.disabled, false);
});

test('both successful persistence paths consume the warning and unrelated reservation replies cannot clear it', () => {
    assert.match(form, /id="preinvoiceStocktakeWarning"/);
    assert.match(form, /preinvoice-stocktake-warning\.js/);
    for (const name of ['postReservation', 'persistDbAutosave']) {
        const start = form.indexOf('async function '+name+'(');
        assert.ok(start > 0);
        const end = form.indexOf('\n\t\tfunction ', start + 1);
        const body = form.slice(start, end);
        assert.match(body, /hasOwnProperty\.call\(json \|\| \{\}, 'stocktake_warning'\)/);
        assert.match(body, /PreinvoiceStocktakeWarning\?\.render\(json\.stocktake_warning\)/);
    }
});

test('actual reservation handler updates explicit warning/null and ignores replies without the warning key', async () => {
    const ui = fixture();
    const start = form.indexOf('async function postReservation(');
    const end = form.indexOf('\n\t\tfunction showReservationItemErrors(', start);
    vm.runInContext(form.slice(start, end), ui.context);
    let result = {ok: true, stocktake_warning: {message: 'Remove closed items or retain draft', blocked_product_names: ['Airpod']}};
    ui.context.fetchJson = async () => ({response: {ok: true}, json: result});
    await ui.context.postReservation('/sync', {});
    assert.equal(ui.nodes.preinvoiceStocktakeWarning.hidden, false);
    result = {ok: true};
    await ui.context.postReservation('/unrelated', {});
    assert.equal(ui.nodes.preinvoiceStocktakeWarning.hidden, false, 'absent field must preserve previous warning');
    result = {ok: true, stocktake_warning: null};
    await ui.context.postReservation('/sync', {});
    assert.equal(ui.nodes.preinvoiceStocktakeWarning.hidden, true);
    assert.equal(ui.selections.length, 2);
});

test('actual autosave handler renders warning then clears it without changing rows or acknowledged version handling', async () => {
    const ui = fixture();
    const start = form.indexOf('async function persistDbAutosave(');
    const end = form.indexOf('\n\t\tfunction scheduleDbAutosave(', start);
    Object.assign(ui.context, {isBootingPage: false, isHydratingLocalDraft: false, isSubmittingProgrammatically: false,
        hasAnyFormData: () => true, autosaveConflict: false, confirmedAutosavePayload: null,
        collectAutosavePayload: () => ({reservation_token: 'token', products: ui.selections}),
        updateLocalDraftStatus: () => {}, currentAutosaveUuid: 'draft-1', currentAutosaveVersion: 'v1',
        API: {autosave: '/autosave'}, IS_EDIT: true, getLocalDraft: () => null, structuredClone,
    });
    vm.runInContext(form.slice(start, end), ui.context);
    let json = {ok: true, uuid: 'draft-1', version: 'v2', stocktake_warning: {message: 'Closed row preserved', blocked_product_names: ['Airpod']}};
    ui.context.fetchJson = async () => ({response: {ok: true, status: 200}, json});
    const before = JSON.stringify(ui.selections);
    await ui.context.persistDbAutosave();
    assert.equal(ui.nodes.preinvoiceStocktakeWarning.hidden, false);
    assert.equal(ui.context.currentAutosaveVersion, 'v2');
    json = {ok: true, uuid: 'draft-1', version: 'v3', stocktake_warning: null};
    await ui.context.persistDbAutosave();
    assert.equal(ui.nodes.preinvoiceStocktakeWarning.hidden, true);
    assert.equal(ui.context.currentAutosaveVersion, 'v3');
    assert.equal(JSON.stringify(ui.selections), before);
});
