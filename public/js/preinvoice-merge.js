/* Three-way merge: independent edits merge; overlapping edits require a choice. */
(function (root) {
    function equal(a, b) { return JSON.stringify(a) === JSON.stringify(b); }
    function merge(base, local, remote, path = '', conflicts = []) {
        if (equal(local, base)) return structuredClone(remote);
        if (equal(remote, base) || equal(local, remote)) return structuredClone(local);
        if (path === 'products') {
            const keyed = rows => Object.fromEntries((rows || []).map(r => [`${r.id}:${r.variety_id}`, r]));
            const b = keyed(base), l = keyed(local), r = keyed(remote);
            return [...new Set([...Object.keys(r), ...Object.keys(l), ...Object.keys(b)])]
                .map(k => merge(b[k], l[k], r[k], `item ${k}`, conflicts)).filter(Boolean);
        }
        if (base && local && remote && !Array.isArray(local) && typeof local === 'object') {
            const result = {};
            for (const key of new Set([...Object.keys(base), ...Object.keys(local), ...Object.keys(remote)])) {
                const value = merge(base[key], local[key], remote[key], path ? `${path}.${key}` : key, conflicts);
                if (value !== undefined) result[key] = value;
            }
            return result;
        }
        conflicts.push({path, local, remote});
        return structuredClone(local);
    }
    root.PreinvoiceMerge = {merge, equal};
})(typeof window === 'undefined' ? globalThis : window);
