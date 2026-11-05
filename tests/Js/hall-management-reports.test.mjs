import test from 'node:test';
import assert from 'node:assert/strict';
import { buildReport } from '../../public/hall-management/reports.js';

const doc = (key,stage,seconds,extra={}) => ({key,stage,number:key,customer:'مشتری',seller:'فروشنده',amount_rial:100,item_count:2,unit_count:40,stage_seconds:seconds,total_seconds:300,...extra});
const snapshot = documents => ({updated_at:'2026-11-01T12:00:00+03:30',metrics:{sales_rial:9000,sales_count:7,cash_rial:3000,cheque_rial:2000,cheque_count:2,month_invoice_count:28,month_jalali:'1405/08'},stages:['reserved','finance','warehouse','reapproval','shipping'].map(key=>({key,label:key,warning_minutes:30})),documents});

test('sales report uses day metrics rather than summing open orders as sales',()=>{
    const data=snapshot([doc('a','reserved',20)]);
    const before=JSON.stringify(data);
    const report=buildReport(data,'sales');
    assert.equal(report.metrics[0].value,9000);
    assert.equal(report.metrics[1].value,7);
    assert.equal(report.scope,'اسناد در جریان فروشندگان؛ شامل فروش‌های تمام‌شده نیست');
    assert.equal(JSON.stringify(data),before);
});
test('queue report excludes unknown times from averages and lists longest waits first',()=>{
    const report=buildReport(snapshot([doc('unknown','warehouse',null),doc('short','warehouse',60),doc('long','warehouse',180),doc('else','shipping',900)]),'warehouse');
    assert.equal(report.metrics[0].value,3);
    assert.equal(report.metrics[1].value,120);
    assert.deepEqual(report.documents.map(d=>d.key),['long','short','unknown']);
});
test('attention includes only known waits above each actual stage threshold',()=>{
    const report=buildReport(snapshot([doc('unknown','finance',null),doc('below','warehouse',1799),doc('due','shipping',1800)]),'attention');
    assert.deepEqual(report.documents.map(d=>d.key),['due']);
});
test('seller grouping preserves all documents and separates unknown sellers',()=>{
    const report=buildReport(snapshot([doc('a','reserved',null),doc('b','finance',60),doc('c','shipping',70,{seller:null})]),'sales');
    assert.equal(report.groups.length,2);
    assert.equal(report.groups.reduce((s,g)=>s+g.count,0),3);
    assert.equal(report.groups.find(g=>g.name==='فروشنده').amount,200);
});
test('empty queues do not invent zero wait times',()=>{
    assert.equal(buildReport(snapshot([]),'shipping').metrics[1].value,null);
    assert.throws(()=>buildReport(snapshot([]),'invented'));
});

test('reapproval report shows only pending reapproval documents', () => {
    const report=buildReport(snapshot([doc('a','reapproval',200),doc('b','finance',20),doc('c','reapproval',300)]),'reapproval');
    assert.equal(report.documents.length,2);
    assert.deepEqual(report.documents.map(row=>row.key),['c','a']);
});
