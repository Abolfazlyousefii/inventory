import test from 'node:test';
import assert from 'node:assert/strict';
import { arrivalKeys, visibleQueueDocuments } from '../../public/hall-management/motion.js';

const doc = (key, stage, entry_token, extra={}) => ({
    key, stage, entry_token, amount_rial:1000, item_count:1, ...extra,
});

test('initial snapshot animates no historical orders', () => {
    assert.deepEqual(arrivalKeys(null, [doc('order:1','finance','hall:1')]), []);
});
test('a newly recorded arrival animates just once on its first update', () => {
    const old=[doc('order:1','finance','hall:1')];
    const next=[...old, doc('order:2','warehouse','hall:2')];
    assert.deepEqual(arrivalKeys(old,next),['order:2']);
    assert.deepEqual(arrivalKeys(next,next),[]);
});
test('changing amount or time does not animate existing cards', () => {
    const before=[doc('order:1','warehouse','hall:2')];
    const next=[doc('order:1','warehouse','hall:2',{amount_rial:1500,stage_seconds:20})];
    assert.deepEqual(arrivalKeys(before,next),[]);
});
test('a transfer to the independent reapproval queue animates the same workflow key', () => {
    const before=[doc('order:1','warehouse','hall:2')];
    const after=[doc('order:1','reapproval','hall:3')];
    assert.deepEqual(arrivalKeys(before,after),['order:1']);
});
test('reentry to the same queue with a new recorded entry is a fresh arrival', () => {
    const before=[doc('order:1','warehouse','hall:2')];
    const after=[doc('order:1','warehouse','hall:5')];
    assert.deepEqual(arrivalKeys(before,after),['order:1']);
});
test('legacy documents without a trustworthy entry token do not receive invented animations', () => {
    const before=[doc('order:1','finance',null)];
    const after=[doc('order:1','warehouse',null),doc('order:2','finance',null)];
    assert.deepEqual(arrivalKeys(before,after),[]);
});
test('malformed legacy input is safely treated as no prior snapshot', () => {
    assert.deepEqual(arrivalKeys(undefined,[doc('order:2','finance','hall:2')]),[]);
});

test('tablet queues expose every document without hidden or repeated cards', () => {
    const docs=Array.from({length:26},(_,i)=>doc('order:'+i,'shipping','hall:'+i));
    const shown=visibleQueueDocuments(docs,9,3,true);
    assert.equal(shown.length,26);
    assert.deepEqual(shown.map(d=>d.key),docs.map(d=>d.key));
});

test('wallboard rotation remains bounded and wraps without losing invoices', () => {
    const docs=Array.from({length:8},(_,i)=>doc('order:'+i,'shipping','hall:'+i));
    assert.deepEqual(visibleQueueDocuments(docs,6,4,false).map(d=>d.key),[
        'order:6','order:7','order:0','order:1','order:2',
    ]);
    assert.deepEqual(visibleQueueDocuments(docs,0,4,false).map(d=>d.key),[
        'order:0','order:1','order:2','order:3','order:4',
    ]);
});

test('small and empty queues keep exactly the available invoices', () => {
    const docs=[doc('order:1','reserved','hall:1'),doc('order:2','reserved','hall:2')];
    assert.deepEqual(visibleQueueDocuments(docs,0,4,false),docs);
    assert.deepEqual(visibleQueueDocuments([],0,4,true),[]);
});
