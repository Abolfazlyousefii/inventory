import test from 'node:test';
import assert from 'node:assert/strict';
import { discountTotal, initialUnit, historicalTotal } from '../../public/js/collection-unit-discount.js';
test('unit discount follows final quantity',()=>{assert.equal(discountTotal(20000,100,100000),2000000);assert.equal(discountTotal(20000,120,100000),2400000);assert.equal(discountTotal(20000,0,100000),0)});
test('invalid or excessive discount is rejected',()=>{for(const args of [[-1,2,100],[101,2,100],[1.5,2,100],[1,-1,100],[Number.MAX_SAFE_INTEGER,2,Number.MAX_SAFE_INTEGER]])assert.throws(()=>discountTotal(...args))});
test('historical indivisible totals retain their exact value until explicitly edited',()=>{assert.deepEqual(initialUnit(100,3),{unit:33,approximate:true});assert.deepEqual(initialUnit(120,3),{unit:40,approximate:false});assert.deepEqual(initialUnit(0,0),{unit:0,approximate:false})});

test('historical proportional rounding matches existing financial rules',()=>{assert.equal(historicalTotal(100,3,2,1000),67);assert.equal(historicalTotal(100,3,3,1000),100);assert.equal(historicalTotal(100,3,0,1000),0);assert.equal(historicalTotal(100,3,2,10),20)});
