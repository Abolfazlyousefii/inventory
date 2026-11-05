export const stageKeys = ['reserved', 'finance', 'warehouse', 'reapproval', 'shipping'];

export function formatDuration(seconds, includeSeconds = true) {
    if (seconds == null || !Number.isFinite(seconds)) return '—';
    const value = Math.max(0, Math.floor(seconds));
    const parts = [Math.floor(value / 3600), Math.floor(value / 60) % 60];
    if (includeSeconds) parts.push(value % 60);
    return parts.map(n => String(n).padStart(2, '0')).join(':').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
}

export function elapsedSeconds(base, receivedAt, monotonicNow) {
    return base == null ? null : Math.max(0, base + Math.floor(Math.max(0, monotonicNow - receivedAt) / 1000));
}

export function compactDuration(seconds) {
    if (seconds == null || !Number.isFinite(seconds)) return '—';
    if (seconds < 86400) return formatDuration(seconds, false);
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor(seconds % 86400 / 3600);
    return `${new Intl.NumberFormat('fa-IR').format(days)} روز ${new Intl.NumberFormat('fa-IR').format(hours)} ساعت`;
}

export function diffDocuments(previous, current) {
    if (previous == null) return [];
    const before = new Map(previous.map(doc => [doc.key, doc]));
    const after = new Map(current.map(doc => [doc.key, doc]));
    const changes = [];
    for (const doc of current) {
        const old = before.get(doc.key);
        if (!old) changes.push({ kind: 'entered', document: doc, to: doc.stage });
        else if (old.stage !== doc.stage) changes.push({ kind: 'moved', document: doc, from: old.stage, to: doc.stage });
        else if (old.amount_rial !== doc.amount_rial || old.item_count !== doc.item_count || old.unit_count !== doc.unit_count) {
            changes.push({ kind: 'edited', document: doc, amount_delta: doc.amount_rial - old.amount_rial });
        } else if (old.status !== doc.status) changes.push({ kind: 'status', document: doc });
    }
    for (const doc of previous) if (!after.has(doc.key)) changes.push({ kind: 'left', document: doc, from: doc.stage });
    return changes;
}

export function normalizeSnapshot(input) {
    if (!input || input.version !== 3 || !Array.isArray(input.documents) || !Array.isArray(input.stages)
        || !Number.isFinite(Date.parse(input.updated_at)) || !input.metrics || typeof input.metrics !== 'object') {
        throw new Error('Invalid hall snapshot');
    }
    for (const field of ['sales_rial', 'sales_count', 'cash_rial', 'cheque_rial', 'cheque_count', 'month_invoice_count']) {
        if (!Number.isSafeInteger(input.metrics[field]) || input.metrics[field] < 0) throw new Error('Invalid dashboard metric');
    }
    if (!/^\d{4}\/\d{2}$/.test(input.metrics.month_jalali)) throw new Error('Invalid Jalali month');
    const keys = new Set();
    const documents = input.documents.map(doc => {
        if (!doc || typeof doc.key !== 'string' || keys.has(doc.key) || !stageKeys.includes(doc.stage)
            || typeof doc.number !== 'string' || typeof doc.customer !== 'string') throw new Error('Invalid hall document');
        for (const name of ['amount_rial', 'item_count', 'unit_count']) {
            if (!Number.isSafeInteger(doc[name]) || doc[name] < 0) throw new Error('Invalid document amount or count');
        }
        for (const name of ['stage_seconds', 'total_seconds']) {
            if (doc[name] != null && (!Number.isSafeInteger(doc[name]) || doc[name] < 0)) throw new Error('Invalid duration');
        }
        if (doc.entry_token != null && (typeof doc.entry_token !== 'string' || doc.entry_token.length > 140 || !doc.entry_token)) throw new Error('Invalid entry token');
        if (doc.transfer_count != null && (!Number.isSafeInteger(doc.transfer_count) || doc.transfer_count < 0)) throw new Error('Invalid movement count');
        keys.add(doc.key);
        return { ...doc, stage_seconds: doc.stage_seconds ?? null, total_seconds: doc.total_seconds ?? null, entry_token: doc.entry_token ?? null, transfer_count: doc.transfer_count ?? 0 };
    });
    if (input.stages.length !== stageKeys.length || new Set(input.stages.map(s => s.key)).size !== stageKeys.length) throw new Error('Invalid queues');
    for (const stage of input.stages) {
        if (!stageKeys.includes(stage.key) || stage.count !== documents.filter(doc => doc.stage === stage.key).length) throw new Error('Invalid queue counts');
    }
    const events = (Array.isArray(input.events) ? input.events : []).filter(event => event
        && typeof event.key === 'string' && typeof event.number === 'string' && typeof event.label === 'string'
        && (event.at == null || Number.isFinite(Date.parse(event.at))));
    return { ...input, documents, events };
}
