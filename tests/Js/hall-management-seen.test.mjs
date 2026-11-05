import test from 'node:test';
import assert from 'node:assert/strict';
import { createSeenTracker } from '../../public/hall-management/seen.js';

function store() {
    const map = new Map();
    return {
        getItem: key => map.has(key) ? map.get(key) : null,
        setItem: (key, val) => map.set(key, val),
        raw: () => [...map.values()].join(','),
    };
}
const doc = (token, key='order:7') => ({key,entry_token:token,customer:'نباید ذخیره شود',amount_rial:987654321});

test('initial existing cards are a baseline, not all new', () => {
    const state=createSeenTracker(store());
    const old=doc('hall:11');
    state.prime([old]);
    assert.equal(state.isNew(old),false);
    assert.equal(state.isNew(doc('hall:12')),true);
});
test('viewed queue entry stays non-gold through reload on the same browser', () => {
    const storage=store();
    const state=createSeenTracker(storage);
    state.prime([doc('hall:1')]);
    const incoming=doc('hall:2');
    assert.equal(state.isNew(incoming),true);
    state.markSeen(incoming);
    assert.equal(state.isNew(incoming),false);
    assert.equal(createSeenTracker(storage).isNew(incoming),false);
    assert.equal(storage.raw().includes('نباید ذخیره شود'),false);
    assert.equal(storage.raw().includes('987654321'),false);
});
test('a new entry to a queue becomes gold even after viewing the same invoice earlier', () => {
    const state=createSeenTracker(store());
    state.prime([doc('hall:1')]);
    state.markSeen(doc('hall:2'));
    assert.equal(state.isNew(doc('hall:3')),true);
    assert.equal(state.isNew(doc('hall:2')),false);
});
test('legacy document without trustworthy arrival identity is not labeled new', () => {
    const state=createSeenTracker(store());
    state.prime([]);
    assert.equal(state.isNew(doc(null)),false);
});
test('storage failures cannot break live cards or manual viewing', () => {
    const storage={getItem(){throw new Error('blocked');},setItem(){throw new Error('blocked');}};
    const state=createSeenTracker(storage);
    state.prime([]);
    const incoming=doc('hall:5');
    assert.equal(state.isNew(incoming),true);
    state.markSeen(incoming);
    assert.equal(state.isNew(incoming),false);
});
