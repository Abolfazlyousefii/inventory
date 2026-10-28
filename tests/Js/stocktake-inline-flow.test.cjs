const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const blade = fs.readFileSync(path.join(__dirname, '../../resources/views/stocktake/form.blade.php'), 'utf8');
const inlineScript = blade.match(/<script>([\s\S]*?)<\/script>/);
assert.ok(inlineScript, 'stocktaking page inline script exists');

// Blade expressions inside the inline JS are replaced with their rendered
// JSON shape, so the test covers actual browser-side JavaScript grammar.
const js = inlineScript[1]
    .replace('@json($stockCountConflicts->values())', '[]')
    .replace('@json($oldActualQuantities)', '{}');

test('stocktaking form JavaScript remains syntactically valid', () => {
    assert.doesNotThrow(() => new vm.Script(js));
});

test('queue indicator shows each stage but never changes actual input', () => {
    const snippet = js.match(/function flowHtml\(r\)\{[^\n]+\}/);
    assert.ok(snippet, 'queue row formatter exists');
    const context = {};
    vm.createContext(context);
    vm.runInContext(snippet[0], context);
    const result = context.flowHtml({
        pending_collection: 100,
        warehouse_received: 7,
        collecting: 3,
        queue_total: 110,
    });
    assert.match(result, /موجودی در صف جمع‌آوری/);
    assert.match(result, /۱۱۰|110/);
    assert.match(result, /۱۰۰|100/);
    assert.match(result, /۷|7/);
    assert.match(result, /۳|3/);
    assert.ok(!result.includes('actual_quantities'), 'read-only queue widget does not write an actual quantity');

    const empty = context.flowHtml({queue_total:0,pending_collection:0,warehouse_received:0,collecting:0});
    assert.match(empty, /flow-badge/);
    assert.ok(!empty.includes('تحویل انبار:'), 'empty queue is compact');
});
