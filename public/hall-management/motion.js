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
