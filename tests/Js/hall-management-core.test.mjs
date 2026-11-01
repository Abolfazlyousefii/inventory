import test from 'node:test';
import assert from 'node:assert/strict';
import { formatDuration, compactDuration, diffDocuments, normalizeSnapshot, elapsedSeconds } from '../../public/hall-management/core.js';

const document = (key, stage, extra = {}) => ({ key, stage, number: '123', customer: 'نمونه', amount_rial: 123456789, item_count: 2, unit_count: 80, ...extra });
const snapshot = (documents) => ({ version: 2, documents, stages: ['reserved','finance','warehouse','shipping'].map(key=>({key, count: documents.filter(x=>x.stage===key).length})), metrics: {sales_rial:0,sales_count:0,cash_rial:0,cheque_rial:0,cheque_count:0}, updated_at:'2026-11-01T14:00:00+03:30' });

test('long elapsed durations never wrap after a day', () => {
    assert.equal(formatDuration(90061), '۲۵:۰۱:۰۱');
    assert.equal(formatDuration(null), '—');
    assert.equal(formatDuration(-1), '۰۰:۰۰:۰۰');
});
test('conversion keeps workflow identity and is one movement rather than deletion plus creation', () => {
    const changes = diffDocuments([document('order:1','finance')], [document('order:1','warehouse',{number:'F-12'})]);
    assert.equal(changes.length,1);
    assert.equal(changes[0].kind,'moved');
    assert.equal(changes[0].from,'finance');
    assert.equal(changes[0].to,'warehouse');
});
test('financial edits and item edits are visible without fake stage movements', () => {
    const changes = diffDocuments([document('order:1','finance')],[document('order:1','finance',{amount_rial:333,item_count:3})]);
    assert.equal(changes[0].kind,'edited');
    assert.equal(changes[0].amount_delta,-123456456);
});
test('first snapshot does not announce all historical orders as new', () => {
    assert.deepEqual(diffDocuments(null,[document('1','draft')]),[]);
});
test('removed order does not invent a cancellation', () => {
    assert.equal(diffDocuments([document('1','draft')],[])[0].kind,'left');
});
test('invalid or duplicate payload cannot partially replace a good screen', () => {
    assert.throws(()=>normalizeSnapshot(snapshot([document('1','finance'),document('1','finance')])));
    assert.throws(()=>normalizeSnapshot(snapshot([document('1','unknown')])));
    assert.throws(()=>normalizeSnapshot({ ...snapshot([]), updated_at:'bad' }));
});
test('rial and item counts remain raw values, not converted toman or quantities', () => {
    const doc=normalizeSnapshot(snapshot([document('1','finance')])).documents[0];
    assert.equal(doc.amount_rial,123456789);
    assert.equal(doc.item_count,2);
    assert.equal(doc.unit_count,80);
});
test('timer uses server anchor and monotonic elapsed time rather than client wall clock', () => {
    assert.equal(elapsedSeconds(120, 5000, 8500),123);
    assert.equal(elapsedSeconds(null,5000,8500),null);
    assert.equal(elapsedSeconds(120,5000,4000),120);
});
test('old orders fit the corner timer without hiding the day count', () => {
    assert.equal(compactDuration(90061),'۱ روز ۱ ساعت');
    assert.equal(compactDuration(null),'—');
    assert.equal(compactDuration(3660),'۰۱:۰۱');
});
test('incomplete financial metrics never overwrite the last real amounts with zeros', () => {
    assert.throws(()=>normalizeSnapshot({...snapshot([]),metrics:{}}));
    assert.throws(()=>normalizeSnapshot({...snapshot([]),metrics:{...snapshot([]).metrics,sales_rial:'1000'}}));
});
test('invalid event dates do not break rendering of otherwise valid financial data', () => {
    const result=normalizeSnapshot({...snapshot([]),events:[{key:'1',number:'1',label:'ارسال شد',at:'not-a-date'}]});
    assert.deepEqual(result.events,[]);
});
test('draft cards and the obsolete draft-queue payload are rejected', () => {
    assert.throws(()=>normalizeSnapshot(snapshot([document('old-draft','draft')])));
    assert.throws(()=>normalizeSnapshot({...snapshot([]),version:1}));
    const current=normalizeSnapshot(snapshot([document('real-hold','reserved')]));
    assert.equal(current.documents[0].stage,'reserved');
});
