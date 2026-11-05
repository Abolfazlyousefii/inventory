import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const root = {};
const adapterCode = readFileSync(new URL('../../public/js/finance-payment-quick-adapter.js', import.meta.url), 'utf8');
runInNewContext(adapterCode, {window:root, Number, String, Error});
const adapter = root.FinancePaymentQuickAdapter;
const calendarRoot = {};
const calendarCode = readFileSync(new URL('../../public/js/finance-payment-date-utils.js', import.meta.url), 'utf8');
runInNewContext(calendarCode, {window:calendarRoot, Intl, Date, Math, Number, String, Error});
const calendar = calendarRoot.FinanceDate;
const view = readFileSync(new URL('../../resources/views/finance/payment-calculator/index.blade.php', import.meta.url), 'utf8');
const script = readFileSync(new URL('../../public/js/finance-payment-quick.js', import.meta.url), 'utf8');

const config={monthly:6,baseDate:'1405/08/14'};

test('three tabs remain visible, but only the Laravel calculator owns amount computation',()=>{
  for(const mode of ['cheque','cash','value'])
    assert.match(view,new RegExp('data-mode="'+mode+'"'));
  assert.match(view, /id="calendarDialog"/);
  assert.match(view, /data-calculate-url/);
  assert.match(view, /data-print-url/);
  assert.match(view, /id="printBtn"/);
  assert.match(script, /FinancePaymentQuickAdapter/);
  assert.match(script, /fetch\(/);
  assert.doesNotMatch(script, /FinanceMath\.calculate|F\.calculate\(/);
});

test('cheque tab posts fixed cash and weighted due date to verified server API',()=>{
  const data=adapter.buildPayload('cheque',{
    price:100000000,cash:50000000,known:[],future:[{due:'1405/09/14',weight:1}]
  },config,'نمونه');
  assert.equal(data.mode,'schedule');
  assert.equal(data.price_rial,'100000000');
  assert.equal(data.fixed_payments.length,1);
  assert.equal(data.fixed_payments[0].kind,'cash');
  assert.equal(data.fixed_payments[0].amount_rial,'50000000');
  assert.equal(data.weighted_payments[0].due_date,'1405/09/14');
});

test('cash tab accepts two 50m cheques and emits no weighted payments',()=>{
  const data=adapter.buildPayload('cash',{
    price:100000000,cash:0,
    known:[{amount:50000000,due:'1405/09/14'},{amount:50000000,due:'1405/10/14'}],
    future:[]
  },config,'');
  assert.equal(data.mode,'cash_needed');
  assert.equal(data.fixed_payments.length,2);
  assert.equal(data.weighted_payments.length,0);
  assert.equal(data.fixed_payments[1].amount_rial,'50000000');
});

test('value tab does not require a price and values a six-month cheque',()=>{
  const data=adapter.buildPayload('value',{
    price:null,cash:0,known:[{amount:100000000,due:'1406/02/14'}],future:[]
  },config,'');
  assert.equal(data.mode,'cash_value');
  assert.equal(data.price_rial,null);
  assert.equal(data.fixed_payments[0].amount_rial,'100000000');
});

test('adapts server-calculated results instead of trusting browser arithmetic',()=>{
  const state={cash:50000000};
  const computed=adapter.toViewModel('cheque',state,{
    fixed_payments:[{day:0,amount_rial:50000000}],
    flexible_payments:[{day:30,amount_rial:53000000}],
    calculated_cash_rial:0,cash_equivalent_rial:0,flexible_total_rial:53000000,
    total_nominal_rial:103000000,nominal_increase_rial:3000000,
    present_value_rounded_rial:100000000,overall_rate:.03,daily_rate:.00194
  });
  assert.equal(computed.answer,53000000);
  assert.equal(computed.known.length,0);
  assert.equal(computed.future[0].amount,53000000);
  assert.equal(computed.difference,3000000);
});

test('validates missing price, invalid weight and malformed amount',()=>{
  assert.throws(()=>adapter.buildPayload('cash',{
    price:0,cash:0,known:[],future:[]
  },config,''),/قیمت نقدی/);
  assert.throws(()=>adapter.buildPayload('cheque',{
    price:100000000,cash:0,known:[],future:[{due:'1405/09/14',weight:0}]
  },config,''),/وزن/);
  assert.throws(()=>adapter.buildPayload('value',{
    price:null,cash:0,known:[{amount:NaN,due:'1405/09/14'}],future:[]
  },config,''),/مبلغ/);
});

test('free-date Jalali calendar computes non-round 10-day maturities',()=>{
  const due=calendar.jalaliAfterDays('1405/08/14',10);
  assert.equal(calendar.dayDifference('1405/08/14',due),10);
  assert.equal(calendar.dayDifference('1405/08/14','1405/08/14'),0);
  assert.throws(()=>calendar.dayDifference('1405/08/14','1405/08/10'),/سررسید/);
  assert.throws(()=>calendar.toGregorianDay('1405/13/01'),/تاریخ/);
});
