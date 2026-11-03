const fs=require('fs'),assert=require('assert/strict');
const {chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:process.env.BROWSER_CHANNEL || 'chrome'}); const page=await browser.newPage({viewport:{width:940,height:800}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 let html=fs.readFileSync('resources/views/preinvoice/partials/product-finder-modal.blade.php','utf8').replace(/@push\([^)]*\)|@endpush/g,'').replace(/<link[^>]*>|<script[\s\S]*?<\/script>/g,'').replace(/data-models-url="[^"]*"/,'data-models-url="/models/__PRODUCT__"').replace(/data-search-url="[^"]*"/,'data-search-url="/search"').replace(/data-categories-url="[^"]*"/,'data-categories-url="/categories"');
 await page.route('http://inventory.test/**', route => route.fulfill({status:200,body:'<html></html>',contentType:'text/html'}));await page.goto('http://inventory.test/');
 await page.setContent('<div dir="rtl">'+html+'</div>');
 for(const css of ['public/lib/bootstrap.rtl.min.css','public/css/pages/preinvoice-product-finder.css','public/css/pages/preinvoice-model-list.css'])await page.addStyleTag({content:fs.readFileSync(css,'utf8')});
 await page.evaluate(()=>{
  const modal=document.querySelector('#productFinderModal');modal.classList.add('show');modal.style.display='block';
  window.selected=[];document.addEventListener('preinvoice:product-selected',e=>selected.push(e.detail.productId));
  window.bootstrap={Modal:{getOrCreateInstance:()=>({hide(){modal.dispatchEvent(new Event('hidden.bs.modal'));}})}};
  window.fetch=async url=>({ok:true,headers:{get:()=> 'application/json'},json:async()=>String(url).includes('/models/')?{data:{id:1,name:'گارد سولید',models:[{brand:'Samsung',name:'A12'},{brand:'Samsung',name:'A13'}],colors:['مشکی','کرم']}}:{data:[{id:1,name:'گارد سولید',total_available_stock:3,picker_query:'a12'}],meta:{last_page:1}}});
 });
 for(const js of ['public/js/pages/preinvoice-product-finder.js','public/js/pages/preinvoice-model-list.js'])await page.addScriptTag({content:fs.readFileSync(js,'utf8')});
 await page.fill('#productFinderQuery','A12');await page.press('#productFinderQuery','Enter');await page.locator('[data-model-list]').click();
 await page.waitForSelector('#productModelListBody .product-model-list__models');assert.equal(await page.evaluate(()=>selected.length),0);
 assert.equal(await page.locator('#productModelListBody').getByText('A12',{exact:true}).count(),1);
 await page.setViewportSize({width:390,height:780});await page.screenshot({path:'storage/app/private/model-list-mobile.png'});
 assert.ok(await page.locator('#productModelListSelect').isVisible());
 await page.press('#productModelListSelect','Escape');assert.equal(await page.locator('#productModelList').isVisible(),false);
 await page.locator('[data-model-list]').click();await page.waitForFunction(()=>!document.querySelector('#productModelListSelect').disabled);
 await page.locator('#productModelListSelect').click();assert.deepEqual(await page.evaluate(()=>selected),[1]);assert.deepEqual(errors,[]);
 await browser.close();console.log('Browser passed: preview does not select, compact mobile panel, Escape returns, selection uses original event; no JS errors.');
})().catch(e=>{console.error(e);process.exit(1)});
