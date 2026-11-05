import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const css=readFileSync(new URL('../../public/hall-management/dashboard.css',import.meta.url),'utf8');
const dashboard=readFileSync(new URL('../../public/hall-management/dashboard.js',import.meta.url),'utf8');

test('tablet and mobile breakpoints expose scrollable full queues and do not trap 1024px viewport',()=>{
    assert.match(css,/@media\s*\(min-width:641px\)\s*and\s*\(max-width:1099px\)/);
    assert.match(css,/@media\s*\(min-width:901px\)\s*and\s*\(max-width:1099px\)[\s\S]*?body\s*\{\s*overflow:auto/);
    assert.match(css, /\.lane-window\s*\{[\s\S]*?overflow-y:auto/);
    assert.match(dashboard, /visibleQueueDocuments\(docs, offset, visibleSlots, tablet\)/);
});
test('motion has reduced-motion protection and does not animate every poll',()=>{
    assert.match(css, /@media\s*\(prefers-reduced-motion:reduce\)/);
    assert.match(css, /animation:none!important/);
    assert.match(dashboard, /arrivingKeys = new Set\(arrivalKeys\(previous, next.documents\)\)/);
    assert.match(dashboard, /arrivingKeys\.clear\(\)/);
});
test('dialog and card affordances preserve focus and touch access',()=>{
    assert.match(css, /\.order-card:focus-visible/);
    assert.match(css, /@media\s*\(hover:none\)/);
    assert.match(dashboard, /\.focus\(\{preventScroll:true\}\)/);
});
