import { stageKeys, formatDuration, compactDuration, elapsedSeconds, diffDocuments, normalizeSnapshot } from './core.js?v=20261105-hallv3';
import { buildReport } from './reports.js?v=20261105-hallv3';
import { createSeenTracker, HALL_SEEN_STORAGE_KEY } from './seen.js?v=20261105-hallv3';
import { arrivalKeys, visibleQueueDocuments } from './motion.js?v=20261105-hall-motion';

const root = document.getElementById('hall-dashboard');
const byId = id => document.getElementById(id);
const fa = new Intl.NumberFormat('fa-IR');
const timeFormat = new Intl.DateTimeFormat('fa-IR', { timeZone: 'Asia/Tehran', hour: '2-digit', minute: '2-digit', second: '2-digit' });
const dateFormat = new Intl.DateTimeFormat('fa-IR', { timeZone: 'Asia/Tehran', weekday: 'long', month: 'long', day: 'numeric' });
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const lanes = new Map(stageKeys.map(key => [key, root.querySelector(`[data-stage="${key}"]`)]));
const positions = new Map(stageKeys.map(key => [key, 0]));
const detail = byId('document-detail');
const reportDialog = byId('management-report');
const seenTracker = createSeenTracker((() => { try { return window.localStorage; } catch { return null; } })());
let historyController = null, historyPage = 1, historyKey = null;
let selectedReport = null;
let snapshot = null, receivedAt = 0, pollTimer, controller, fetching = false, disposed = false;
let refreshSeconds = Math.max(5, Number(document.querySelector('meta[name="hall-refresh-seconds"]').content) || 15);
let paused = reducedMotion || window.matchMedia('(pointer: coarse)').matches, moving = false, selectedKey = null, visibleSlots = 4, flights = [], lastServerEventId = null;
let arrivingKeys = new Set();

function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
}

function queueDocuments(key) { return snapshot?.documents.filter(doc => doc.stage === key) ?? []; }
function isTabletLayout() { return window.matchMedia('(max-width: 1099px)').matches; }
function currentSeconds(doc, name) { return elapsedSeconds(doc[name], receivedAt, performance.now()); }

function card(doc) {
    const button = element('button', 'order-card');
    button.type = 'button'; button.dataset.key = doc.key;
    if (arrivingKeys.has(doc.key)) button.classList.add('is-arrival');
    const main = element('span');
    const customer = element('span', 'card-customer', doc.customer); customer.title = doc.customer;
    main.append(customer, element('span', 'card-number', `${doc.type === 'preinvoice' ? 'پیش‌فاکتور' : 'فاکتور'} ${doc.number}`));
    if (seenTracker.isNew(doc)) { button.classList.add('is-new'); main.append(element('span', 'card-new-tag', 'جدید')); }
    const timer = element('span', 'card-timer');
    timer.append(element('span', 'timer-label', 'زمان صف'), element('span', 'timer-value'), element('span', 'timer-remainder'), element('span', 'timer-total'));
    if (!doc.stage_time_known) timer.title = 'سابقهٔ معتبر ورود این سند به صف در دسترس نیست.';
    const bottom = element('span', 'card-bottom');
    const amount = element('span', 'card-amount', fa.format(doc.amount_rial)); amount.append(element('small', '', 'ریال'));
    bottom.append(amount, element('span', 'card-items', `${fa.format(doc.item_count)} قلم`));
    button.append(main, timer, bottom);
    button.setAttribute('aria-label', `${doc.customer}، سند ${doc.number}، ${fa.format(doc.amount_rial)} ریال، ${fa.format(doc.item_count)} قلم؛ نمایش جزئیات`);
    button.addEventListener('click', () => openDocument(doc));
    updateCardTimer(button, doc);
    return button;
}

function updateCardTimer(node, doc) {
    const seconds = currentSeconds(doc, 'stage_seconds');
    const value = node.querySelector('.timer-value');
    const hasDays = seconds != null && seconds >= 86400;
    value.textContent = hasDays ? `${fa.format(Math.floor(seconds / 86400))} روز` : formatDuration(seconds);
    value.classList.toggle('timer-days', seconds != null && seconds >= 86400);
    value.title = formatDuration(seconds);
    const remainder = node.querySelector('.timer-remainder');
    remainder.hidden = !hasDays;
    remainder.textContent = hasDays ? formatDuration(seconds % 86400) : '';
    const total = currentSeconds(doc, 'total_seconds');
    const totalNode = node.querySelector('.timer-total');
    totalNode.textContent = `کل ${total != null && total >= 86400 ? `${fa.format(Math.floor(total / 86400))}روز ${formatDuration(total % 86400, false)}` : formatDuration(total, false)}`;
    totalNode.title = formatDuration(total);
    const limit = snapshot?.stages.find(s => s.key === doc.stage)?.warning_minutes ?? 45;
    node.classList.toggle('is-late', seconds != null && seconds >= limit * 60);
}

function renderLane(key) {
    const lane = lanes.get(key), docs = queueDocuments(key), track = lane.querySelector('.lane-track');
    const focusedCard = track.contains(document.activeElement) ? document.activeElement.closest('.order-card') : null;
    const focusedKey = focusedCard?.dataset.key ?? null;
    const offset = docs.length ? positions.get(key) % docs.length : 0;
    positions.set(key, offset);
    // Touch/tablet layouts expose the complete list in a scrollable lane.
    // Large wallboards retain the existing economical rotating window.
    const tablet = isTabletLayout();
    const visible = visibleQueueDocuments(docs, offset, visibleSlots, tablet);
    track.replaceChildren(...visible.map((doc, index) => {
        const node = card(doc);
        if (!tablet && index >= visibleSlots) { node.tabIndex = -1; node.setAttribute('aria-hidden', 'true'); }
        return node;
    }));
    if (focusedKey) [...track.children].find(node => node.dataset.key === focusedKey && node.tabIndex !== -1)?.focus({preventScroll:true});
    const counter = lane.querySelector('[data-count]');
    const nextCount = fa.format(docs.length);
    counter.classList.toggle('count-updated', counter.textContent !== '—' && counter.textContent !== nextCount);
    counter.textContent = nextCount;
    lane.querySelector('.lane-empty').hidden = docs.length > 0;
    lane.querySelector('.lane-empty').textContent = 'سفارشی در این صف نیست';
    lane.querySelector('[data-visible]').textContent = docs.length ? `${fa.format(tablet ? docs.length : Math.min(visibleSlots, docs.length))} از ${fa.format(docs.length)}` : '';
    lane.querySelector('[data-rotation]').textContent = tablet ? (docs.length > 3 ? 'برای مشاهده بیشتر، پیمایش کنید' : '') : (docs.length > visibleSlots ? 'مرور آرام ↓' : '');
    updateAverage(key);
}

function updateAverage(key) {
    const docs = queueDocuments(key).filter(doc => doc.stage_seconds != null);
    const average = docs.length ? Math.round(docs.reduce((sum, doc) => sum + currentSeconds(doc, 'stage_seconds'), 0) / docs.length) : null;
    const target = lanes.get(key).querySelector('[data-average]');
    target.textContent = compactDuration(average);
    target.title = docs.length ? `بر اساس ${fa.format(docs.length)} سند دارای سابقهٔ معتبر ورود به صف` : 'زمان معتبر برای محاسبه در دسترس نیست';
}

function renderDetail() {
    const doc = snapshot?.documents.find(row => row.key === selectedKey), target = byId('detail-content');
    if (!doc) { if (detail.open) detail.close(); return; }
    const rows = [
        ['شماره سند', doc.number], ['مشتری', doc.customer], ['فروشنده', doc.seller || 'ثبت نشده'],
        ['وضعیت', doc.status_label], ['مبلغ', `${fa.format(doc.amount_rial)} ریال`],
        ['اقلام', `${fa.format(doc.item_count)} قلم / ${fa.format(doc.unit_count)} عدد`],
        ['زمان صف', formatDuration(currentSeconds(doc, 'stage_seconds'))], ['زمان کل', formatDuration(currentSeconds(doc, 'total_seconds'))],
    ];
    target.replaceChildren(...rows.flatMap(([key, value]) => [element('dt', '', key), element('dd', '', value)]));
}

function openDocument(doc) {
    selectedKey = doc.key;
    renderDetail();
    if (!detail.open) detail.showModal();
    if (detail.open) {
        seenTracker.markSeen(doc);
        renderLane(doc.stage);
        loadHistory(doc, 1);
    }
}

async function loadHistory(doc, page) {
    historyController?.abort();
    historyController = new AbortController();
    const signal = historyController.signal;
    if (page === 1) {
        historyKey = doc.key;
        historyPage = 1;
        byId('history-events').replaceChildren();
        byId('history-title').textContent = 'تاریخچه انتقال‌ها';
    }
    byId('history-status').textContent = 'در حال دریافت تاریخچه…';
    byId('history-more').hidden = true;
    try {
        const url = new URL(document.querySelector('meta[name="hall-history-url"]').content, location.href);
        if (url.origin !== location.origin) throw new Error('Invalid history origin');
        url.searchParams.set('key', doc.key);
        url.searchParams.set('page', String(page));
        const response = await fetch(url, {
            credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}, signal,
        });
        if (response.status === 401 || response.status === 403 || response.redirected) {
            disposed = true; clearPrivateScreen();
            location.replace(document.querySelector('meta[name="hall-unlock-url"]').content);
            return;
        }
        if (!response.ok) throw new Error('History unavailable');
        const data = await response.json();
        if (signal.aborted || !detail.open || selectedKey !== doc.key || historyKey !== doc.key) return;
        if (data.document_key !== doc.key || !Array.isArray(data.events) ||
            !Number.isSafeInteger(data.transfer_count) || data.transfer_count < 0 ||
            !Number.isSafeInteger(data.record_count) || data.record_count < 0 ||
            data.page !== page || typeof data.has_more !== 'boolean') throw new Error('Invalid movement history');

        for (const item of data.events) {
            if (typeof item.from_label !== 'string' || typeof item.to_label !== 'string' ||
                typeof item.status_label !== 'string' || !Number.isFinite(Date.parse(item.at))) continue;
            const entry = element('li');
            entry.append(element('div', 'history-route', item.from_label + ' ← ' + item.to_label));
            entry.append(element('div', 'history-status-label', item.status_label));
            entry.append(element('time', 'history-time', dateFormat.format(new Date(item.at)) + ' · ' + timeFormat.format(new Date(item.at))));
            byId('history-events').append(entry);
        }
        historyPage = page;
        byId('history-title').textContent = 'تاریخچه انتقال‌ها · ' + fa.format(data.transfer_count) + ' انتقال ثبت‌شده';
        byId('history-status').textContent = data.record_count
            ? fa.format(data.record_count) + ' رویداد ثبت‌شده'
            : 'برای این سند سابقه انتقال ثبت نشده است.';
        byId('history-more').textContent = 'نمایش رویدادهای قدیمی‌تر';
        byId('history-more').hidden = !data.has_more;
    } catch (error) {
        if (!signal.aborted && selectedKey === doc.key) {
            historyPage = page - 1;
            byId('history-more').textContent = 'تلاش مجدد';
            byId('history-status').textContent = 'دریافت تاریخچه ناموفق بود؛ دوباره تلاش کنید.';
            byId('history-more').hidden = false;
        }
    }
}

function renderReport() {
    if (!snapshot || !selectedReport) return;
    const live = {...snapshot, documents:snapshot.documents.map(doc=>({...doc,stage_seconds:currentSeconds(doc,'stage_seconds')}))};
    const report = buildReport(live,selectedReport), content = byId('management-report-content');
    const focusedKey=content.contains(document.activeElement)?document.activeElement.dataset.documentKey:null;
    byId('management-report-title').textContent = report.title;
    const metrics = element('div','report-metrics');
    for (const metric of report.metrics) {
        const item=element('div','report-stat');
        item.append(element('span','',metric.label),element('strong','',metric.unit==='duration'?compactDuration(metric.value):`${fa.format(metric.value)} ${metric.unit}`));
        metrics.append(item);
    }
    const scope=element('p','report-scope',report.scope);
    const table=element('table','report-table'), head=element('thead'), body=element('tbody');
    const heading=element('tr');
    const labels=selectedReport==='sales'?['فروشنده','اسناد در جریان','مبلغ اسناد در جریان / ریال']:['سند / مشتری','وضعیت','مبلغ / ریال','زمان صف'];
    heading.append(...labels.map(text=>element('th','',text)));head.append(heading);
    if (selectedReport==='sales') {
        for (const group of report.groups) {
            const tr=element('tr');tr.append(element('td','',group.name),element('td','',fa.format(group.count)),element('td','',fa.format(group.amount)));body.append(tr);
        }
    } else {
        for (const doc of report.documents) {
            const tr=element('tr'), cell=element('td');
            const open=element('button','report-document',`${doc.number} · ${doc.customer}`);open.type='button';open.dataset.documentKey=doc.key;
            open.addEventListener('click',()=>openDocument(doc));
            cell.append(open);tr.append(cell,element('td','',doc.status_label||snapshot.stages.find(s=>s.key===doc.stage)?.label||''),element('td','',fa.format(doc.amount_rial)),element('td','',formatDuration(doc.stage_seconds)));body.append(tr);
        }
    }
    table.append(head,body);
    const empty=element('p','report-empty',selectedReport==='attention'?'سندی با زمان انتظار بالاتر از حد تعیین‌شده وجود ندارد.':'سندی در این بخش نیست.');
    content.replaceChildren(metrics,scope,(selectedReport==='sales'?report.groups.length:report.documents.length)?table:empty);
    for(const link of root.querySelectorAll('[data-full-report]'))link.hidden=link.dataset.fullReport!==selectedReport;
    if(focusedKey)[...content.querySelectorAll('[data-document-key]')].find(node=>node.dataset.documentKey===focusedKey)?.focus({preventScroll:true});
    paintReportFreshness();
}
function paintReportFreshness() {
    if(!reportDialog?.open)return;
    const stale=byId('connection').dataset.state==='stale';
    byId('report-freshness').textContent=(stale?'ارتباط به‌روز نیست؛ آخرین دریافت موفق: ':'آخرین دریافت: ')+(snapshot?timeFormat.format(new Date(snapshot.updated_at)):'—');
    byId('report-freshness').classList.toggle('is-stale',stale);
}
root.addEventListener('click',event=>{
    const button=event.target.closest('[data-report]');
    if(!button||!snapshot||moving)return;
    selectedReport=button.dataset.report;renderReport();if(!reportDialog.open)reportDialog.showModal();paintReportFreshness();
});
reportDialog?.addEventListener('close',()=>{for(const button of root.querySelectorAll('[data-report]'))button.removeAttribute('aria-current');});
byId('close-management-report')?.addEventListener('click',()=>reportDialog.close());

function changeMessage(change) {
    const doc = change.document;
    const label = key => snapshot.stages.find(s => s.key === key)?.label ?? key;
    if (change.kind === 'moved') return `سند ${doc.number} · ${label(change.from)} ← ${label(change.to)}`;
    if (change.kind === 'entered') return `سند ${doc.number} وارد ${label(doc.stage)} شد.`;
    if (change.kind === 'edited') return `سند ${doc.number} اصلاح شد · ${fa.format(doc.amount_rial)} ریال · ${fa.format(doc.item_count)} قلم`;
    if (change.kind === 'status') return `سند ${doc.number} · ${doc.status_label}`;
    const event = snapshot.events.find(e => e.key === doc.key);
    return event ? `سند ${doc.number} · ${event.label}` : `سند ${doc.number} از صف‌های جاری خارج شد.`;
}

async function applySnapshot(next) {
    const previous = snapshot?.documents ?? null;
    arrivingKeys = new Set(arrivalKeys(previous, next.documents));
    const changes = diffDocuments(previous, next.documents);
    const moves = changes.filter(c => c.kind === 'moved');
    const origins = new Map();
    for (const move of moves.slice(0, 3)) {
        const old = Array.from(root.querySelectorAll('.order-card:not([aria-hidden])')).find(node => node.dataset.key === move.document.key);
        if (old) origins.set(move.document.key, { rect: old.getBoundingClientRect(), node: old.cloneNode(true) });
        const index = next.documents.filter(d => d.stage === move.to).findIndex(d => d.key === move.document.key);
        if (index >= 0) positions.set(move.to, index);
    }
    if (previous === null) seenTracker.prime(next.documents);
    snapshot = next; receivedAt = performance.now();
    byId('sales-amount').textContent = fa.format(next.metrics.sales_rial ?? 0);
    byId('cash-amount').textContent = fa.format(next.metrics.cash_rial ?? 0);
    byId('cheque-amount').textContent = fa.format(next.metrics.cheque_rial ?? 0);
    byId('sales-count').textContent = `${fa.format(next.metrics.sales_count ?? 0)} فاکتور امروز`;
    byId('cheque-count').textContent = `${fa.format(next.metrics.cheque_count ?? 0)} فقره`;
    byId('month-invoice-count').textContent = fa.format(next.metrics.month_invoice_count);
    byId('month-jalali').textContent = `ماه ${next.metrics.month_jalali.replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d])}`;
    byId('document-total').textContent = `/ ${fa.format(next.documents.length)} سند`;
    for (const key of stageKeys) renderLane(key);
    // One-shot entrance: timers, pagination and the next poll must not replay it.
    arrivingKeys.clear();
    if (detail.open) renderDetail();
    if (reportDialog?.open) renderReport();
    byId('attention-count').textContent=fa.format(buildReport(next,'attention').documents.length);
    if (changes.length) {
        const preferred = moves[0] ?? changes.find(c => c.kind === 'left') ?? changes[0];
        byId('event-text').textContent = changeMessage(preferred) + (changes.length > 1 ? ` · ${fa.format(changes.length - 1)} تغییر دیگر` : '');
    } else if (previous === null || (next.events[0] && next.events[0].id !== lastServerEventId)) {
        const event = next.events[0];
        byId('event-text').textContent = event ? `سند ${event.number} · ${event.label}${event.at ? ` · ${dateFormat.format(new Date(event.at))} ${timeFormat.format(new Date(event.at))}` : ''}` : 'اطلاعات سفارش‌ها دریافت شد.';
    }
    lastServerEventId = next.events[0]?.id ?? lastServerEventId;
    if (!reducedMotion && origins.size && typeof document.documentElement.animate === 'function') {
        moving = true;
        await Promise.allSettled(moves.slice(0, 3).map(async move => {
            const origin = origins.get(move.document.key);
            const target = Array.from(root.querySelectorAll('.order-card')).find(node => node.dataset.key === move.document.key);
            if (!origin || !target) return;
            const dest = target.getBoundingClientRect(), node = origin.node;
            node.classList.add('transition-flight'); node.tabIndex = -1; node.setAttribute('aria-hidden', 'true'); node.removeAttribute('data-key');
            const fromColor = getComputedStyle(lanes.get(move.from)).getPropertyValue('--stage');
            const toColor = getComputedStyle(lanes.get(move.to)).getPropertyValue('--stage');
            Object.assign(node.style, { left: `${origin.rect.left}px`, top: `${origin.rect.top}px`, width: `${origin.rect.width}px` });
            node.style.setProperty('--stage', fromColor); document.body.append(node); flights.push(node); target.style.visibility = 'hidden';
            try {
                await node.animate([
                    { transform: 'translate(0,0)', borderRightColor: fromColor },
                    { transform: `translate(${dest.left - origin.rect.left}px,${dest.top - origin.rect.top}px)`, borderRightColor: toColor },
                ], { duration: 780, easing: 'cubic-bezier(.2,.7,.2,1)', fill: 'forwards' }).finished;
            } finally { node.remove(); target.style.visibility = ''; }
        }));
        flights = []; moving = false;
    }
}

function connection(state, message) {
    byId('connection').dataset.state = state;
    byId('connection-label').textContent = message;
    byId('connection-alert').hidden = state !== 'stale';
    paintReportFreshness();
    if (state === 'stale') byId('connection-alert').textContent = 'ارتباط به‌روز نیست؛ اطلاعات نمایش‌داده‌شده مربوط به آخرین دریافت موفق است. اتصال دوباره بررسی می‌شود.';
}

function clearPrivateScreen() {
    snapshot = null;
    for (const node of flights) node.remove();
    historyController?.abort();
    if (detail.open) detail.close(); byId('detail-content').replaceChildren();
    byId('history-events').replaceChildren(); byId('history-status').textContent = '—'; byId('history-more').hidden = true;
    if(reportDialog?.open)reportDialog.close();byId('management-report-content').replaceChildren();byId('management-report-title').textContent='';byId('report-freshness').textContent='';byId('attention-count').textContent='—';
    for (const lane of lanes.values()) { lane.querySelector('.lane-track').replaceChildren(); lane.querySelector('[data-count]').textContent = '—'; lane.querySelector('[data-average]').textContent = '—'; }
    for (const id of ['sales-amount', 'cash-amount', 'cheque-amount', 'sales-count', 'cheque-count', 'month-invoice-count', 'month-jalali', 'document-total', 'event-text']) byId(id).textContent = '—';
}

async function poll() {
    if (disposed || fetching || document.hidden) return;
    fetching = true; controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
        const url = new URL(document.querySelector('meta[name="hall-data-url"]').content, location.href);
        if (url.origin !== location.origin) throw new Error('Invalid data origin');
        const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: controller.signal });
        if (response.status === 401 || response.status === 403 || response.redirected) {
            disposed = true; clearPrivateScreen(); location.replace(document.querySelector('meta[name="hall-unlock-url"]').content); return;
        }
        if (!response.ok) throw new Error('Dashboard unavailable');
        const next = normalizeSnapshot(await response.json());
        if (snapshot && Date.parse(next.updated_at) < Date.parse(snapshot.updated_at)) throw new Error('Stale snapshot');
        await applySnapshot(next);
        connection('live', 'متصل · به‌روز');
        byId('last-updated').textContent = `آخرین دریافت ${timeFormat.format(new Date(next.updated_at))}`;
        refreshSeconds = Math.max(5, Number(next.refresh_seconds) || refreshSeconds);
    } catch (error) {
        if (!disposed && !document.hidden) connection('stale', snapshot ? 'ارتباط قطع است' : 'دریافت اطلاعات ناموفق بود');
    } finally {
        clearTimeout(timeout); fetching = false;
        if (!disposed && !document.hidden) { clearTimeout(pollTimer); pollTimer = setTimeout(poll, refreshSeconds * 1000); }
    }
}

function rotate(manual = false) {
    if (!snapshot || isTabletLayout() || moving || detail.open || reportDialog?.open || (!manual && (paused || document.hidden))) return;
    for (const key of stageKeys) {
        const lane = lanes.get(key), docs = queueDocuments(key);
        if (docs.length <= visibleSlots || (!manual && (lane.matches(':hover') || lane.contains(document.activeElement)))) continue;
        positions.set(key, (positions.get(key) + docs.length - 1) % docs.length);
        renderLane(key);
        if (!reducedMotion) {
            const track = lane.querySelector('.lane-track'), first = track.firstElementChild;
            const step = first.getBoundingClientRect().height + parseFloat(getComputedStyle(track).gap);
            track.animate([{ transform: `translateY(-${step}px)` }, { transform: 'translateY(0)' }], { duration: 520, easing: 'cubic-bezier(.22,.68,.23,1)' });
        }
    }
}

function measure() {
    if (moving) return;
    const win = lanes.get(stageKeys[0]).querySelector('.lane-window');
    const style = getComputedStyle(document.documentElement), height = parseFloat(style.getPropertyValue('--card-height')), gap = parseFloat(style.getPropertyValue('--card-gap'));
    const count = Math.max(1, Math.floor((win.clientHeight + gap) / (height + gap)));
    for (const lane of lanes.values()) {
        const viewport = lane.querySelector('.lane-window');
        const slack = Math.max(0, viewport.clientHeight - (count * (height + gap) - gap));
        viewport.style.setProperty('--window-slack', `${slack}px`);
    }
    if (visibleSlots !== count) { visibleSlots = count; if (snapshot) for (const key of stageKeys) renderLane(key); }
}

const pause = byId('pause');
function paintPause() { pause.textContent = paused ? 'شروع مرور' : 'توقف مرور'; pause.setAttribute('aria-pressed', String(paused)); }
pause.addEventListener('click', () => { paused = !paused; paintPause(); }); paintPause();
byId('next').addEventListener('click', () => rotate(true));
byId('close-detail').addEventListener('click', () => detail.close());
detail.addEventListener('close', () => { historyController?.abort(); historyKey = null; });
byId('history-more').addEventListener('click', () => {
    const doc = snapshot?.documents.find(row => row.key === selectedKey);
    if (doc && detail.open && historyKey === doc.key) loadHistory(doc, historyPage + 1);
});
byId('fullscreen').hidden = !document.fullscreenEnabled;
byId('fullscreen').addEventListener('click', async () => { try { if (document.fullscreenElement) await document.exitFullscreen(); else await document.documentElement.requestFullscreen(); } catch { /* Browser may deny fullscreen; normal display stays usable. */ } });
document.addEventListener('fullscreenchange', () => { byId('fullscreen').textContent = document.fullscreenElement ? 'خروج از تمام‌صفحه' : 'تمام‌صفحه'; });
document.addEventListener('visibilitychange', () => { clearTimeout(pollTimer); if (document.hidden) controller?.abort(); else { poll(); measure(); } });
const observer = new ResizeObserver(measure); observer.observe(byId('board'));
for (const lane of lanes.values()) observer.observe(lane.querySelector('.lane-window'));
window.addEventListener('storage', event => {
    if (event.key === HALL_SEEN_STORAGE_KEY && snapshot) {
        seenTracker.refresh();
        for (const key of stageKeys) renderLane(key);
    }
});
window.addEventListener('resize', measure);
document.fonts?.ready.then(measure);
setInterval(() => {
    byId('wall-clock').textContent = `${dateFormat.format(new Date())} · ${timeFormat.format(new Date())}`;
    if (!snapshot || document.hidden) return;
    for (const node of root.querySelectorAll('.order-card')) { const doc = snapshot.documents.find(d => d.key === node.dataset.key); if (doc) updateCardTimer(node, doc); }
    for (const key of stageKeys) updateAverage(key);
    if (detail.open) renderDetail();
    if (performance.now() - receivedAt > (refreshSeconds * 2 + 10) * 1000) connection('stale', 'اطلاعات قدیمی است');
}, 1000);
setInterval(() => rotate(), 2000);
window.addEventListener('pagehide', () => { disposed = true; clearTimeout(pollTimer); controller?.abort(); observer.disconnect(); });
window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
measure(); poll();
