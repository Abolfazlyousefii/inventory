const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Execute the actual notification initializer with deterministic DOM/network doubles.
const source = fs.readFileSync(path.join(__dirname, '../../public/js/app-shell.js'), 'utf8');
const initializer = source.slice(source.indexOf('    function initNotifications()'), source.indexOf('    function initMoneyInputs()'));
const tick = () => new Promise(resolve => setImmediate(resolve));
const response = data => ({ ok: true, headers: { get: () => 'application/json' }, json: async () => data });
const failed = () => ({ ok: false, headers: { get: () => 'application/json' } });
const fa = count => new Intl.NumberFormat('fa-IR').format(count);

function element(dataset = {}) {
    const classes = new Set();
    const attrs = {};
    return {
        dataset, listeners: {}, attrs, classes, hidden: false, disabled: false, innerHTML: '', textContent: '',
        classList: { toggle(name, on) { if (on) classes.add(name); else classes.delete(name); }, contains: name => classes.has(name) },
        addEventListener(name, fn) { this.listeners[name] = fn; },
        setAttribute(name, value) { attrs[name] = value; }, getAttribute(name) { return attrs[name]; },
        focus() {}, contains() { return false; }, querySelectorAll() { return []; },
        querySelector() { return null; }, closest() { return null; },
    };
}

function boot(fetch) {
    const panel = element({ filter: 'unread', page: '1', perPage: '6' });
    const bell = element(), badge = element(), count = element(), close = element(), list = element();
    const pagination = element(), pageLabel = element(), previous = element({ notificationStep: '-1' }), next = element({ notificationStep: '1' });
    previous.tagName = next.tagName = 'BUTTON';
    pagination.querySelector = () => pageLabel;
    pagination.querySelectorAll = () => [previous, next];
    const error = element(), errorText = element(), readAll = element();
    error.querySelector = () => errorText;
    panel.querySelector = selector => ({ '[data-notification-list]': list, '[data-notification-pagination]': pagination, '[data-notification-error]': error })[selector];
    const document = {
        body: { dataset: { notificationsCountUrl: '/count', notificationsLatestUrl: '/latest' } },
        hidden: false, activeElement: null, listeners: {},
        querySelector: () => ({ content: 'token' }),
        querySelectorAll: selector => ({ '[data-notification-surface]': [panel], '[data-notification-count]': [count], '[data-notification-read-all] button': [readAll] })[selector] || [],
        getElementById: id => ({ notifPanel: panel, notifBell: bell, notifBadge: badge, notifCloseBtn: close })[id],
        addEventListener(name, fn) { this.listeners[name] = fn; },
    };
    const window = { location: { href: 'https://erp.example/', origin: 'https://erp.example', assign() {} }, history: { replaceState() {} }, setTimeout() {}, clearTimeout() {}, addEventListener() {} };
    vm.runInNewContext(initializer + '\ninitNotifications();', { document, window, fetch, URL, AbortController, Intl });
    const submit = () => panel.listeners.submit({ preventDefault() {}, target: { action: '/read-all', matches: () => true, hasAttribute: () => true, querySelector: () => readAll } });
    return { panel, bell, badge, count, list, error, errorText, readAll, pagination, previous, next, document, window, submit };
}
const row = id => ({ id, title: '<unsafe>', message: '<img onerror=alert(1)>', category: 'مالی', created_at_human: 'امروز', open_url: `/open/${id}`, read_url: `/read/${id}`, read_at: null });
const listing = (count, items = [], page = 1, last_page = 1) => ({ count, items, page, last_page });

test('exact shared count, unread pagination, escaped content, and read-all without a manual refresh', async () => {
    let unread = 120;
    const calls = [];
    const ui = boot(async (url, options) => {
        const parsed = new URL(url); calls.push([parsed.pathname, options.method]);
        if (parsed.pathname === '/read-all') { assert.equal(options.headers['X-CSRF-TOKEN'], 'token'); unread = 0; return response({ count: unread }); }
        if (parsed.pathname === '/count') return response({ count: unread });
        const page = Number(parsed.searchParams.get('page'));
        return response(listing(unread, unread ? [row(page)] : [], page, unread ? 20 : 1));
    });
    await tick();
    assert.equal(ui.badge.textContent, fa(120));
    assert.equal(ui.count.textContent, `${fa(120)} خوانده‌نشده`);
    ui.bell.listeners.click(); await tick();
    assert.match(ui.list.innerHTML, /&lt;img/);
    assert.doesNotMatch(ui.list.innerHTML, /<img/);
    assert.equal(ui.pagination.hidden, false);
    ui.panel.listeners.click({ target: { closest: selector => selector === '[data-notification-step]' ? ui.next : null }, preventDefault() {} });
    await tick();
    assert.match(ui.list.innerHTML, /data-notification-id="2"/);
    ui.submit(); await tick();
    assert.equal(ui.badge.classes.has('d-none'), true);
    assert.equal(ui.count.textContent, `${fa(0)} خوانده‌نشده`);
    assert.match(ui.list.innerHTML, /همه اعلان‌ها را خوانده‌اید/);
    assert.equal(ui.readAll.disabled, true);
    assert.ok(calls.some(([url, method]) => url === '/read-all' && method === 'POST'));
});

test('a stale polling response cannot overwrite the result of a read-all write', async () => {
    let release;
    let pending = false;
    let count = 5;
    const ui = boot(async url => {
        const path = new URL(url).pathname;
        if (path === '/count') return response({ count });
        if (path === '/read-all') { count = 0; return response({ count }); }
        if (!pending) { pending = true; return new Promise(resolve => { release = () => resolve(response(listing(5, [row(99)]))); }); }
        return response(listing(count));
    });
    await tick(); ui.bell.listeners.click(); await tick();
    ui.submit(); await tick(); release(); await tick();
    assert.equal(ui.badge.textContent, fa(0));
    assert.doesNotMatch(ui.list.innerHTML, /data-notification-id="99"/);
});

test('failed mutations keep the original count and new events appear after refresh', async () => {
    let count = 1;
    const ui = boot(async url => {
        const path = new URL(url).pathname;
        if (path === '/count') return response({ count });
        if (path === '/read-all') return failed();
        return response(listing(count, [row(count)]));
    });
    await tick(); ui.bell.listeners.click(); await tick();
    ui.submit(); await tick();
    assert.equal(ui.badge.textContent, fa(1));
    assert.equal(ui.error.hidden, false);
    assert.match(ui.list.innerHTML, /data-notification-id="1"/);
    count = 2;
    ui.document.listeners.visibilitychange(); await tick();
    assert.equal(ui.badge.textContent, fa(2));
    assert.equal(ui.count.textContent, `${fa(2)} خوانده‌نشده`);
    assert.match(ui.list.innerHTML, /data-notification-id="2"/);
    assert.equal(ui.error.hidden, true);
});


test('opening one notification removes it from the unread list and uses the returned destination', async () => {
    const ui = boot(async (url, options) => {
        const path = new URL(url).pathname;
        if (path === '/count') return response({ count: 2 });
        if (path === '/read/7') {
            assert.equal(options.method, 'POST');
            return response({ count: 1, url: '/details/7' });
        }
        return response(listing(2, [row(7), row(8)]));
    });
    await tick(); ui.bell.listeners.click(); await tick();
    let removed = false;
    let destination;
    const form = { dataset: { notificationId: '7', readUrl: '/read/7' }, matches: () => true, hasAttribute: () => false, querySelector: () => element(), remove() { removed = true; } };
    ui.list.querySelectorAll = () => [form, { dataset: { notificationId: '8' }, remove() { assert.fail('Unselected row removed'); } }];
    ui.window.location.assign = url => { destination = url; };
    ui.panel.listeners.submit({ preventDefault() {}, target: form });
    await tick();
    assert.equal(removed, true);
    assert.equal(ui.badge.textContent, fa(1));
    assert.equal(destination, 'https://erp.example/details/7');
});


test('legacy array responses render safely with a real unread count and no invented pages', async () => {
    const ui = boot(async url => {
        const path = new URL(url).pathname;
        if (path === '/count') return response({ count: 18 });
        return response([
            { id: 7, title: 'New invoice', type: 'invoice_ready_to_ship', open_url: '/notifications/7/open', url: '/details/7' },
            { id: 8, title: 'Already read', read_at: '2026-11-25', open_url: '/notifications/8/open' },
        ]);
    });
    await tick(); ui.bell.listeners.click(); await tick();
    assert.match(ui.list.innerHTML, /New invoice/);
    assert.match(ui.list.innerHTML, /انبار/);
    assert.match(ui.list.innerHTML, /data-read-url="https:\/\/erp.example\/notifications\/7\/read"/);
    assert.doesNotMatch(ui.list.innerHTML, /Already read/);
    assert.equal(ui.badge.textContent, fa(18));
    assert.equal(ui.pagination.hidden, true);
    assert.equal(ui.error.hidden, true);
});

test('legacy read-all acknowledgement refreshes the count rather than treating a successful write as an error', async () => {
    let count = 2;
    const ui = boot(async url => {
        const path = new URL(url).pathname;
        if (path === '/count') return response({ count });
        if (path === '/read-all') { count = 0; return response({ ok: true }); }
        return response(count ? [row(1), row(2)] : []);
    });
    await tick(); ui.bell.listeners.click(); await tick();
    ui.submit(); await tick();
    assert.equal(ui.badge.textContent, fa(0));
    assert.match(ui.list.innerHTML, /همه اعلان‌ها را خوانده‌اید/);
    assert.equal(ui.error.hidden, true);
});

test('previous and next remain visible with disabled boundaries on a single page and after reading all', async () => {
    let count = 3;
    const ui = boot(async url => {
        const path = new URL(url).pathname;
        if (path === '/count') return response({ count });
        if (path === '/read-all') { count = 0; return response({ count }); }
        return response(listing(count, count ? [row(1), row(2), row(3)] : []));
    });
    await tick(); ui.bell.listeners.click(); await tick();
    assert.equal(ui.pagination.hidden, false);
    assert.equal(ui.previous.disabled, true);
    assert.equal(ui.next.disabled, true);
    ui.submit(); await tick();
    assert.equal(ui.pagination.hidden, false);
    assert.equal(ui.previous.disabled, true);
    assert.equal(ui.next.disabled, true);
});

test('previous and next enforce first and last page boundaries and navigate both directions', async () => {
    const ui = boot(async url => {
        const parsed = new URL(url);
        if (parsed.pathname === '/count') return response({ count: 7 });
        const page = Number(parsed.searchParams.get('page'));
        return response(listing(7, [row(page)], page, 2));
    });
    const click = control => ui.panel.listeners.click({ target: { closest: selector => selector === '[data-notification-step]' ? control : null }, preventDefault() {} });
    await tick(); ui.bell.listeners.click(); await tick();
    assert.equal(ui.previous.disabled, true);
    assert.equal(ui.next.disabled, false);
    click(ui.previous); await tick();
    assert.match(ui.list.innerHTML, /data-notification-id="1"/);
    click(ui.next); await tick();
    assert.equal(ui.previous.disabled, false);
    assert.equal(ui.next.disabled, true);
    assert.match(ui.list.innerHTML, /data-notification-id="2"/);
    click(ui.next); await tick();
    assert.match(ui.list.innerHTML, /data-notification-id="2"/);
    click(ui.previous); await tick();
    assert.match(ui.list.innerHTML, /data-notification-id="1"/);
});
