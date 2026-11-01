// Isolated browser regression: no live requests or product creation.
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chrome'});
 try{
  const page=await browser.newPage();const errors=[],dialogs=[];let writes=0;
  page.on('pageerror',e=>errors.push(e.message));page.on('dialog',async d=>{dialogs.push(d.message());await d.accept();});
  const rows=Array.from({length:300},(_,n)=>({id:n+1,product_id:77,name:'Large guard',variant:`Model ${n+1}`,code:`CASE-${n+1}`,sell_price:1000,stock:10}));
  const roots=[path.resolve('public'),process.env.VOUCHER_PUBLIC_FALLBACK].filter(Boolean);
  await page.route('**/*',async route=>{
   const req=route.request(),u=new URL(req.url());
   if(req.method()!=='GET'){writes++;return route.abort();}
   if(u.pathname==='/fixture')return route.fulfill({contentType:'text/html',body:fs.readFileSync(process.argv[2],'utf8')});
   if(u.pathname.endsWith('/bundle/components/search')){assert.equal(u.searchParams.get('complete_products'),'1');return route.fulfill({json:{results:rows}});}
   for(const root of roots){const file=path.resolve(root,'.'+decodeURIComponent(u.pathname));if(file.startsWith(root+path.sep)&&fs.existsSync(file)&&fs.statSync(file).isFile())return route.fulfill({path:file});}
   return route.fulfill({json:{}});
  });
  await page.goto('http://localhost/fixture');
  await page.check('input[name=product_type][value=bundle]');
  await page.fill('#bundleCreateSearch','Large');
  const all=page.locator('.kit-product-head input[type=checkbox]');await all.waitFor();
  await all.check();assert.equal(await page.locator('.kit-selected').count(),300);assert.deepEqual(dialogs,[],'Selecting 300 variants must not show the old limit alert');
  assert.equal(await page.locator('#bundleSellPrice').inputValue(),'300000');
  await page.locator('.kit-product .kit-select input').nth(1).uncheck();assert.equal(await page.locator('.kit-selected').count(),299);
  await all.check();assert.equal(await page.locator('.kit-selected').count(),300);
  await page.locator('.kit-selected input[name$="[quantity]"]').last().fill('2');
  const data=await page.evaluate(()=>{
   const f=new FormData(document.getElementById('productCreateForm'));
   return {entries:[...f.keys()],payload:JSON.parse(f.get('bundle_form_payload'))};
  });
  assert.equal(data.payload.components.length,300);assert.equal(data.payload.components.at(-1).quantity,'2');
  assert(!data.entries.some(n=>n.startsWith('components[')));assert(data.entries.length<100,'Large forms must not depend on max_input_vars');
  // Allocation snapshots use the same transport, and preserve every concurrency-check value.
  const policy=await page.evaluate(()=>{
   const f=document.createElement('form');
   for(let id=1;id<=300;id++)for(const key of ['allocations','expected_allocations']){const input=document.createElement('input');input.name=`${key}[${id}]`;input.value=key==='allocations'?'3':'2';f.append(input);}
   window.BundleFormPayload.attach(f,['allocations','expected_allocations']);const data=new FormData(f);return {keys:[...data.keys()],payload:JSON.parse(data.get('bundle_form_payload'))};
  });
  assert.deepEqual(policy.keys,['bundle_form_payload']);assert.equal(Object.keys(policy.payload.expected_allocations).length,300);assert.equal(policy.payload.allocations['300'],'3');
  assert.deepEqual(errors,[]);assert.equal(writes,0);
  console.log(JSON.stringify({passed:true,selected:300,postedComponents:data.payload.components.length,formFields:data.entries.length,browserErrors:0,liveWrites:writes}));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});

