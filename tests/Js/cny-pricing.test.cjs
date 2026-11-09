const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const context={window:{}};vm.runInNewContext(fs.readFileSync(path.resolve(__dirname,'../../public/js/cny-pricing.js'),'utf8'),context);
const {quote}=context.window.AriyaCny;
test('shows the agreed purchase, true margin and rounded Toman sale',()=>{
    assert.deepEqual(JSON.parse(JSON.stringify(quote('۵',400000))),{buy:2000000,sale:2910000,saleCny:'7.285714',units:50000,amount:'5'});
    assert.equal(quote('۵',410000).sale,2990000);
    assert.equal(quote('۵',390000).sale,2840000);
});
test('preserves Persian fractional yuan and handles supported maximum amounts exactly',()=>{
    assert.equal(quote('۲٫۵۱۲۵',400000).buy,1005000);
    assert.equal(quote('100000',100000000).buy,10000000000000);
});
test('refuses invalid inputs instead of retaining a previous price',()=>{
    for(const value of ['','0','-1','5abc','5.12345','100000.0001'])assert.throws(()=>quote(value,400000));
    assert.throws(()=>quote('5',0));
});
