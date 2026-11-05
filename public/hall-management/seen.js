// Browser-local state. Never store customer names, amounts, or financial payloads.
export const HALL_SEEN_STORAGE_KEY = 'hall-seen-entries-v3';

export function createSeenTracker(storage) {
    const seen = new Set();
    let primed = false;

    function load() {
        try {
            const raw = storage?.getItem(HALL_SEEN_STORAGE_KEY);
            if (raw === null || raw === undefined) return;
            const value = JSON.parse(raw);
            if (!Array.isArray(value)) return;
            seen.clear();
            for (const token of value.slice(-2500)) if (typeof token === 'string' && token.length <= 260) seen.add(token);
            primed = true;
        } catch { /* Storage can be disabled; the current page still works. */ }
    }
    function save() {
        try { storage?.setItem(HALL_SEEN_STORAGE_KEY, JSON.stringify([...seen].slice(-2500))); }
        catch { /* A private TV must not fail when storage is unavailable. */ }
    }
    function identity(doc) {
        return doc?.entry_token && typeof doc.key === 'string' ? doc.key + '|' + doc.entry_token : null;
    }

    load();
    return {
        prime(documents) {
            if (primed) return;
            // The initial live screen is a baseline; only later arrivals are NEW.
            for (const doc of documents) {
                const id = identity(doc);
                if (id) seen.add(id);
            }
            primed = true;
            save();
        },
        isNew(doc) {
            const id = identity(doc);
            return id !== null && !seen.has(id);
        },
        markSeen(doc) {
            const id = identity(doc);
            if (id && !seen.has(id)) { seen.add(id); save(); }
        },
        refresh: load,
    };
}
