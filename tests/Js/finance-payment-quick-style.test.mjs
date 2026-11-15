import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const stylesheet = readFileSync(
  new URL('../../public/css/finance-payment-quick.css', import.meta.url), 'utf8',
);
const view = readFileSync(
  new URL('../../resources/views/finance/payment-calculator/index.blade.php', import.meta.url), 'utf8',
);

test('approved three-tab workflow and original server-backed controls stay intact', () => {
  for (const mode of ['cheque', 'cash', 'value']) {
    assert.match(view, new RegExp('id="tab-' + mode + '"'));
  }
  for (const id of ['answerAmount', 'calculateBtn', 'printBtn', 'calendarDialog', 'settingsDialog']) {
    assert.ok(view.includes('id="' + id + '"'), 'missing ' + id);
  }
  assert.match(view, /finance-payment-quick\.js/);
  assert.match(view, /finance-payment-quick-adapter\.js/);
});


test('compact design preserves three tabs, distinct result and accessible controls', () => {
  for (const selector of [
    '#financeQuickCalc.pc-v2 .tabs .tab.active',
    '#financeQuickCalc.pc-v2 .big-answer',
    '#financeQuickCalc.pc-v2 .answer-stats',
    '#financeQuickCalc.pc-v2 .payment-row',
    '#financeQuickCalc.pc-v2 .calc-btn',
    '#financeQuickCalc.pc-v2 .print-btn',
    '#financeQuickCalc.pc-v2 .settings-link:hover',
    '#financeQuickCalc.pc-v2 button:focus-visible',
    '#financeQuickCalc.pc-v2 [hidden]',
  ])assert.ok(stylesheet.includes(selector), 'missing style for '+selector);
  assert.ok(stylesheet.includes('--pc-blue:#215ac2;'));
  assert.ok(stylesheet.includes('font-size:clamp(29px'));
});

test('responsive layout and dark mode keep calculator inside the ERP shell', () => {
  for (const css of [
    '@media(max-width:1099px)','@media(max-width:760px)',
    '@media(max-width:660px)','@media(max-width:470px)',
    '@media(max-width:350px)','@media(prefers-reduced-motion:reduce)',
    'html[data-app-theme="dark"] #financeQuickCalc.pc-v2',
    '#financeQuickCalc.pc-v2 .payment-row.has-kind.with-weights',
  ])assert.ok(stylesheet.includes(css),'missing '+css);
});

test('demo layout is simplified and starts blank with no sample values or ornaments', () => {
  for (const required of ['class="pc-quick pc-v2"','class="calc-workspace"','id="emptyCheques"','id="settingsDialog"'])
    assert.ok(view.includes(required), 'missing '+required);
  assert.ok(stylesheet.includes('#financeQuickCalc.pc-v2 .calc-workspace'));
  assert.ok(stylesheet.includes('#financeQuickCalc.pc-v2 .answer'));
  assert.doesNotMatch(view, /demo-badge|id="formTitle"|class="mark"/);
});
