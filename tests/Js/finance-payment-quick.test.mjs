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
  assert.match(view, /finance-payment-quick-adapter\.js/);
  assert.ok(view.indexOf('finance-payment-date-utils.js') < view.indexOf('finance-payment-quick-adapter.js'));
  assert.ok(view.indexOf('finance-payment-quick-adapter.js') < view.indexOf('finance-payment-quick.js'));
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

test('changing cheque type twice preserves entered amount and edited due date',()=>{
  const state={known:[{amount:30000000,due:'1405/09/14'}],future:[]};
  adapter.moveCheque(state,'known',0,'future');
  assert.equal(state.known.length,0);
  assert.equal(state.future[0].weight,1);
  state.future[0].due='1405/10/14';
  adapter.moveCheque(state,'future',0,'known');
  assert.equal(state.known[0].amount,30000000);
  assert.equal(state.known[0].due,'1405/10/14');
});

test('weighted cheque retains its weight when temporarily given a fixed amount',()=>{
  const state={known:[],future:[{weight:3,due:'1405/09/14'}]};
  adapter.moveCheque(state,'future',0,'known');
  assert.equal(state.known[0].amount,null);
  state.known[0].amount=12000000;
  adapter.moveCheque(state,'known',0,'future');
  assert.equal(state.future[0].weight,3);
  adapter.moveCheque(state,'future',0,'known');
  assert.equal(state.known[0].amount,12000000);
});

test('failed type changes leave every cheque intact at the 40-row limit',()=>{
  const state={known:[{amount:0,due:'1405/09/14'}],future:Array.from({length:40},()=>({weight:1,due:'1405/10/14'}))};
  const before=JSON.stringify(state);
  assert.throws(()=>adapter.moveCheque(state,'known',0,'future'),/۴۰/);
  assert.equal(JSON.stringify(state),before);
});

test('invalid row or type cannot remove another cheque',()=>{
  const state={known:[{amount:0,due:'1405/09/14'}],future:[]};
  const before=JSON.stringify(state);
  for(const index of [-1,1,NaN,0.5])assert.throws(()=>adapter.moveCheque(state,'known',index,'future'));
  assert.throws(()=>adapter.moveCheque(state,'cash',0,'future'));
  assert.throws(()=>adapter.moveCheque(state,'known',0,'known'));
  assert.equal(JSON.stringify(state),before);
});

test('temporary saved amounts never enter weighted API payloads',()=>{
  const state={price:100000000,cash:20000000,known:[{amount:30000000,due:'1405/09/14'}],future:[]};
  adapter.moveCheque(state,'known',0,'future');
  const data=adapter.buildPayload('cheque',state,config,'');
  assert.equal(data.fixed_payments.length,1);
  assert.equal(data.weighted_payments.length,1);
  assert.equal(data.weighted_payments[0].weight,'1');
  assert.equal(data.weighted_payments[0].amount_rial,undefined);
  assert.equal(data.weighted_payments[0].savedAmount,undefined);
  adapter.moveCheque(state,'future',0,'known');
  assert.equal(state.known[0].amount,30000000);
});

test('native settings dialog avoids Bootstrap modal CSS and is reachable from the rate chip', () => {
  const dialog = view.split('\n').find(line => line.includes('<dialog id="settingsDialog"'));
  assert.ok(dialog, 'settings dialog should exist');
  assert.ok(dialog.includes('class="pc-settings-dialog"'), 'do not use Bootstrap modal class on native dialog');
  assert.ok(view.includes('<button type="button" id="rateChip"'), 'rate is a native keyboard-accessible button');
  assert.ok(view.includes('id="rateChip" class="rate-chip-button" aria-controls="settingsDialog" aria-haspopup="dialog"'));
  assert.ok(script.includes("$('rateChip').addEventListener('click',openSettingsDialog)"));
  assert.ok(script.includes("$('openSettings').addEventListener('click',openSettingsDialog)"));
});

test('settings dialog initializes stored rate and focuses the editable percent field', () => {
  assert.ok(script.includes('function openSettingsDialog()'));
  assert.ok(script.includes("$('rateInput').value=String(config.monthly)"));
  assert.ok(script.includes("$('settingsDialog').showModal()"));
  assert.ok(script.includes("$('rateInput').focus()"));
});

test('monthly rate chip preserves decimal rates instead of rounding to whole percents', () => {
  assert.equal(adapter.formatMonthlyRate(6), '۶٪');
  assert.equal(adapter.formatMonthlyRate(7.5), '۷٫۵٪');
  assert.equal(adapter.formatMonthlyRate(6.125), '۶٫۱۲۵٪');
  assert.ok(script.includes('Adapter.formatMonthlyRate(config.monthly)'));
  assert.ok(!script.includes("number(config.monthly)+'٪'"));
});



test('redesigned calculator opens with blank editable inputs and no sample cheques in every mode', () => {
  assert.match(view, /class="pc-quick pc-v2"/);
  assert.match(view, /id="priceInput"[^>]*value=""/);
  assert.match(view, /id="cashInput"[^>]*value=""/);
  assert.doesNotMatch(view, /value="100,000,000"|value="50,000,000"/);
  for (const mode of ['cheque','cash','value'])
    assert.ok(script.includes(mode+':{price:null,cash:null,known:[],future:[]}'), 'blank '+mode);
  assert.ok(script.includes('if(!isReadyToCalculate()&&!manual)'));
  assert.ok(script.includes('showIdleResult()'));
});

test('typing rial amounts groups thousands immediately without changing the amount', () => {
  const field={value:'1234567',selectionStart:7,selectionEnd:7,
    setSelectionRange(start,end){this.selectionStart=start;this.selectionEnd=end;}};
  adapter.formatMoneyWhileTyping(field);
  assert.equal(field.value,'1,234,567');
  assert.equal(field.selectionStart,9);
  assert.equal(adapter.parseMoney('1,234,567'),1234567);
  field.value='۱۲۳۴۵۶۷';field.selectionStart=7;
  adapter.formatMoneyWhileTyping(field);
  assert.equal(field.value,'1,234,567');
  const mid={value:'12345',selectionStart:2,selectionEnd:2,
    setSelectionRange(start,end){this.selectionStart=start;this.selectionEnd=end;}};
  adapter.formatMoneyWhileTyping(mid);
  assert.equal(mid.value,'12,345');
  assert.equal(mid.selectionStart,2);
  assert.equal(adapter.formatMoneyInput(''), '');
  assert.equal(adapter.formatMoneyInput('abc'), 'abc');
  assert.ok(script.includes('Adapter.formatMoneyWhileTyping(target)'));
});

test('optional blank cash is safely treated as zero and the server retains calculation authority', () => {
  const payload=adapter.buildPayload('cheque',{
    price:100000000,cash:null,known:[],future:[{due:'1405/09/14',weight:1}]
  },config,'');
  assert.equal(payload.fixed_payments.length,0);
  assert.equal(payload.price_rial,'100000000');
  assert.ok(script.includes('fetch(app.dataset.calculateUrl'));
  assert.ok(script.includes('form.action=app.dataset.printUrl'));
  assert.match(view, /data-calculate-url=/);
  assert.match(view, /data-print-url=/);
});
