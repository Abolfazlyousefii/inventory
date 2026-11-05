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

test('professional theme clearly distinguishes tabs, financial answer and action states', () => {
  for (const selector of [
    '#financeQuickCalc .tab.active',
    '#financeQuickCalc .big-answer',
    '#financeQuickCalc .answer-stats',
    '#financeQuickCalc .payment-row',
    '#financeQuickCalc .calc-btn',
    '#financeQuickCalc .print-btn:disabled',
    '#financeQuickCalc .settings-link:hover',
    '#financeQuickCalc .tab:focus-visible',
    '#financeQuickCalc [hidden]',
  ]) {
    assert.ok(stylesheet.includes(selector), 'missing style for ' + selector);
  }
  assert.match(stylesheet, /--blue:#156fa8;/);
  assert.match(stylesheet, /min-height:49px/);
});

test('mobile layouts keep entry fields readable and preserve reduced-motion accessibility', () => {
  assert.match(stylesheet, /@media\(max-width:660px\)/);
  assert.match(stylesheet, /@media\(max-width:470px\)/);
  assert.match(stylesheet, /@media\(max-width:350px\)/);
  assert.match(stylesheet, /@media\(prefers-reduced-motion:reduce\)/);
  assert.match(stylesheet, /padding:12px 12px 12px 52px;/);
  assert.match(stylesheet, /#financeQuickCalc \.payment-row\.future-row\.with-weights/);
  assert.match(stylesheet, /#financeQuickCalc \.date-wrap \.date-input/);
});
