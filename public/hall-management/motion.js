/**
 * Identify genuine newly recorded queue arrivals for a one-shot entrance.
 * Only trusted server entry tokens qualify. UI-only edits, timer ticks and
 * polling refreshes must never reanimate existing orders.
 *
 * The Hall's first response is a baseline rather than an arrival notification.
 */
export function arrivalKeys(previousDocuments, nextDocuments) {
    if (!Array.isArray(previousDocuments) || !Array.isArray(nextDocuments)) return [];

    const previous = new Map(previousDocuments.map(doc => [doc.key, doc]));
    const arrivals = new Set();

    for (const doc of nextDocuments) {
        if (!doc || typeof doc.key !== 'string' ||
            typeof doc.entry_token !== 'string' || !doc.entry_token) continue;

        const old = previous.get(doc.key);
        if (!old || old.entry_token !== doc.entry_token) arrivals.add(doc.key);
    }

    return [...arrivals];
}

/**
 * Tablet/phone users scroll the complete queue; a TV keeps a bounded
 * rotating window and one extra off-screen card for the animation.
 */
export function visibleQueueDocuments(documents, offset, slots, compact) {
    if (!Array.isArray(documents) || documents.length === 0) return [];
    if (compact) return documents;

    const start = ((offset % documents.length) + documents.length) % documents.length;
    const visibleSlots = Math.max(1, Math.floor(slots) || 1);
    const total = Math.min(documents.length, visibleSlots + (documents.length > visibleSlots ? 1 : 0));

    return Array.from({length:total}, (_, index) => documents[(start + index) % documents.length]);
}
