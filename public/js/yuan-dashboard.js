/* Yuan pricing workspace. The backend owns all monetary arithmetic. */
(() => {
'use strict';
const root = document.getElementById('yuanDashboard');
if (!root) return;
const $ = id => document.getElementById(id);
const fa = new Intl.NumberFormat('fa-IR');
const short = n => n == null ? '—' : fa.format(n);
const toman = n => n == null ? '—' : short(n) + ' تومان';
const state = {page:1,lastPage:1,items:[],offset:0,total:0,selected:null,days:30,paused:window.matchMedia('(prefers-reduced-motion: reduce), (pointer: coarse)').matches,loading:false,request:0,rate:null,preview:null,busy:false};
const isCompact = () => window.matchMedia('(max-width: 900px), (pointer: coarse)').matches;
const text = (tag,content,cls) => { const element=document.createElement(tag); if(cls)element.className=cls; element.textContent=content; return element; };
const showNotice = message => { const target=$('yuanNotice'); target.textContent=message; target.hidden=false; clearTimeout(showNotice.timer); showNotice.timer=setTimeout(()=>target.hidden=true,6000); };
const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
const headers = {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'};
async function json(url,options={}) {
 const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers,...options});
 if (!response.ok || response.redirected) {let body={};try{body=await response.json();}catch{} if(response.status===403 && body.code==='yuan_locked' && body.unlock_url){window.location.assign(body.unlock_url);throw new Error('دسترسی پنل یوان منقضی شده است.');} throw new Error(body.message||'دریافت اطلاعات ناموفق بود.');}
 return response.json();
}
const readFilters=()=>({q:$('yuanSearch').value.trim(),status:$('yuanStatus').value,sort:$('yuanSort').value});
async function load(page=1,reset=false,append=false){
 if(state.loading && !reset)return;
 const request=++state.request;state.loading=true;
 const params=new URLSearchParams({...readFilters(),page:String(page)});
 if(reset){state.offset=0;$('yuanRows').replaceChildren(text('tr',''));$('yuanRows').firstChild.replaceChildren(text('td','در حال دریافت اطلاعات…','yuan-empty'));$('yuanRows').firstChild.firstChild.colSpan=7;}
 try{
  const result=await json(root.dataset.listUrl+'?'+params);
  if(request!==state.request)return;
  state.items=append?[...state.items,...(result.rows||[])]:result.rows||[];state.page=result.page;state.lastPage=result.last_page;state.total=result.total;state.offset=0;state.rate=result.rate;
  $('yuanCount').textContent=short(result.total)+' کالا';
  if(result.rate)$('yuanRate').textContent=toman(Math.trunc(result.rate.rate_rial/10));
  if(append){$('yuanRows').append(...(result.rows||[]).map(makeRow));updateMotionLabel();}else render();
  if(!state.selected && state.items.length)select(state.items[0]);
  else if(state.selected){const current=state.items.find(item=>item.id===state.selected.id);if(current)select(current,false);}
 }catch(error){if(request===state.request)showNotice(error.message);}finally{if(request===state.request)state.loading=false;}
}
function makeRow(item){
 const tr=document.createElement('tr');tr.tabIndex=0;tr.dataset.id=String(item.id);tr.classList.toggle('is-selected',item.id===state.selected?.id);
 const title=text('div','','yuan-prod-title');
 if(item.image){const img=document.createElement('img');img.src=item.image;img.alt='';img.loading='lazy';title.append(img);}else title.append(text('span','◻','yuan-placeholder'));
 const label=document.createElement('span');label.append(text('b',item.name),text('small',(item.sku||'—')+' · '+item.category));title.append(label);
 const td=document.createElement('td');td.append(title);tr.append(td);
 for(const value of [item.buy_cny,item.cost_toman==null?'—':short(item.cost_toman),short(item.sale_toman)])tr.append(text('td',value));
 const profit=text('td',item.profit_percent==null?'—':(item.profit_percent>0?'+':'')+short(item.profit_percent)+'٪','yuan-profit');profit.classList.toggle('negative',item.profit_percent!=null && item.profit_percent<0);tr.append(profit);
 const status=document.createElement('td');status.append(text('span',item.enabled?'فعال':'متوقف','yuan-pill'+(item.enabled?'':' is-paused')));tr.append(status);
 const actions=document.createElement('td');
 if(item.purchase_edit_url){
  const link=text('a','ویرایش خرید','yuan-btn yuan-btn-small yuan-edit-link');
  link.href=item.purchase_edit_url;link.setAttribute('aria-label','ویرایش خرید مربوط به '+item.name);
  link.addEventListener('click',event=>event.stopPropagation());
  actions.append(link);
 }else actions.append(text('span','—','yuan-muted'));
 tr.append(actions);
 tr.addEventListener('click',event=>{if(!event.target.closest('a'))select(item);});
 tr.addEventListener('keydown',event=>{if(event.target.closest('a'))return;if(event.key==='Enter'||event.key===' '){event.preventDefault();select(item);}});
 return tr;
}
function render(){
 const tbody=$('yuanRows');tbody.replaceChildren();
 if(!state.items.length){const tr=document.createElement('tr');const td=text('td','کالایی با این فیلتر پیدا نشد.','yuan-empty');td.colSpan=7;tr.append(td);tbody.append(tr);return;}
 tbody.append(...state.items.map(makeRow));
 $('yuanWindow').scrollTop=0;
 updateMotionLabel();
}
function updateMotionLabel(){
 const label=$('yuanMotionLabel');
 label.textContent=isCompact()?'برای مشاهده کالاهای بیشتر، فهرست را پیمایش کنید':(state.paused?'مرور متوقف است':'● مرور خودکار · '+short(state.page)+' از '+short(state.lastPage)+' بخش');
 $('yuanPause').textContent=state.paused?'▶ شروع مرور':'Ⅱ توقف مرور';$('yuanPause').setAttribute('aria-pressed',String(state.paused));
}
async function rotate(manual=false){
 if(state.loading||!state.items.length||(!manual&&(state.paused||document.hidden||isCompact()||root.querySelector('.yuan-dialog[open]')||$('yuanWindow').matches(':hover')||$('yuanWindow').contains(document.activeElement))))return;
 const tbody=$('yuanRows');const first=tbody.firstElementChild;if(!first)return;
 const next=state.offset+1;
 if(next>=state.items.length){await load(state.page>=state.lastPage?1:state.page+1);return;}
 // Bounded moving window: moving the existing DOM row preserves its event listeners.
 state.offset=next;
 const distance=first.getBoundingClientRect().height;
 if(!window.matchMedia('(prefers-reduced-motion: reduce)').matches){
  const anim=tbody.animate([{transform:'translateY(0)'},{transform:`translateY(-${distance}px)`}],{duration:500,easing:'ease-in-out'});
  await anim.finished.catch(()=>{});
 }
 tbody.append(first);updateMotionLabel();
}
async function select(item,fetchHistory=true){
 state.selected=item;
 for(const row of $('yuanRows').rows)row.classList.toggle('is-selected',Number(row.dataset.id)===item.id);
 $('yuanDetailTitle').textContent=item.name;
 $('yuanDetailBuy').textContent=short(item.buy_cny)+' ¥';
 $('yuanDetailRate').textContent=toman(item.applied_rate_toman);
 $('yuanDetailCost').textContent=toman(item.cost_toman);
 $('yuanDetailProfit').textContent=item.profit_percent==null?'—':short(item.profit_percent)+'٪';
 $('yuanDetailSale').textContent=toman(item.sale_toman);
 if(fetchHistory) await history(item.id);
}
async function history(id){
 const ticket=++history.ticket;
 $('yuanChartStatus').textContent='در حال دریافت سوابق…';
 try{
  const url=root.dataset.historyUrl.replace('__ID__',encodeURIComponent(id));
  const result=await json(url+'?days='+state.days);
  if(ticket!==history.ticket||state.selected?.id!==id)return;
  chart(result.prices,result.rates);
  $('yuanChartStatus').textContent=short(result.price_changes)+' تغییر ثبت‌شده';
 }catch(error){if(ticket===history.ticket){$('yuanChartStatus').textContent='سوابق در دسترس نیست';$('yuanChart').replaceChildren();}}
}
history.ticket=0;
function chart(prices,rates){
 const svg=$('yuanChart');svg.replaceChildren();const NS='http://www.w3.org/2000/svg';
 const draw=(tag,attrs,content)=>{const el=document.createElementNS(NS,tag);for(const [key,value] of Object.entries(attrs))el.setAttribute(key,String(value));if(content!==undefined)el.textContent=content;svg.append(el);return el;};
 const width=480,height=235,left=58,right=62,top=18,bottom=35;
 const series=[{rows:prices,color:'#1684ed',mode:'step'},{rows:rates,color:'#ed7c32',mode:'line'}];
 const points=series.flatMap(s=>s.rows).filter(row=>Number.isFinite(Date.parse(row.time))&&Number.isFinite(Number(row.value)));
 if(!points.length){draw('text',{x:240,y:116,'text-anchor':'middle',fill:'currentColor','font-size':13},'داده تاریخی ثبت نشده است');return;}
 const times=points.map(p=>Date.parse(p.time));let minTime=Math.min(...times),maxTime=Math.max(...times);if(minTime===maxTime)minTime-=86400000;
 const x=ts=>left+(ts-minTime)/(maxTime-minTime)*(width-left-right);
 const bounds=series.map(s=>{const nums=s.rows.map(r=>Number(r.value)).filter(Number.isFinite);let min=nums.length?Math.min(...nums):0,max=nums.length?Math.max(...nums):1;const padding=Math.max((max-min)*.18,max*.025,1);return {min:Math.max(0,min-padding),max:max+padding};});
 const y=(v,b)=>top+(b.max-v)/(b.max-b.min)*(height-top-bottom);
 for(let i=0;i<=4;i++){
  const yy=top+i*(height-top-bottom)/4;draw('line',{x1:left,x2:width-right,y1:yy,y2:yy,stroke:'var(--app-border)'});
  draw('text',{x:left-7,y:yy+4,'text-anchor':'end',fill:'var(--app-muted)','font-size':10},short(Math.round(bounds[0].max-i*(bounds[0].max-bounds[0].min)/4)));
  draw('text',{x:width-right+7,y:yy+4,'text-anchor':'start',fill:'var(--app-muted)','font-size':10},short(Math.round(bounds[1].max-i*(bounds[1].max-bounds[1].min)/4)));
 }
 for(let i=0;i<=4;i++){
  const ts=minTime+(maxTime-minTime)*i/4;
  draw('text',{x:x(ts),y:height-11,'text-anchor':'middle',fill:'var(--app-muted)','font-size':10},new Intl.DateTimeFormat('fa-IR',{month:'short',day:'numeric'}).format(new Date(ts)));
 }
 series.forEach((s,index)=>{
  const rows=s.rows.filter(r=>Number.isFinite(Date.parse(r.time))&&Number.isFinite(Number(r.value))).sort((a,b)=>Date.parse(a.time)-Date.parse(b.time));
  if(!rows.length)return;
  let d=`M ${x(Date.parse(rows[0].time))} ${y(Number(rows[0].value),bounds[index])}`;
  for(let i=1;i<rows.length;i++){
   const px=x(Date.parse(rows[i].time)),py=y(Number(rows[i].value),bounds[index]);
   if(s.mode==='step')d+=` H ${px} V ${py}`;else d+=` L ${px} ${py}`;
  }
  draw('path',{d,fill:'none',stroke:s.color,'stroke-width':2.8,'stroke-linejoin':'round','stroke-linecap':'round'});
  const last=rows.at(-1);draw('circle',{cx:x(Date.parse(last.time)),cy:y(Number(last.value),bounds[index]),r:4,fill:'var(--app-surface)',stroke:s.color,'stroke-width':2.5});
 });
}
let searchTimer;
for(const id of ['yuanStatus','yuanSort'])$(id).addEventListener('change',()=>{state.selected=null;load(1,true);});
$('yuanSearch').addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>{state.selected=null;load(1,true);},320);});
for(const button of root.querySelectorAll('[data-days]'))button.addEventListener('click',()=>{
 state.days=Number(button.dataset.days);root.querySelectorAll('[data-days]').forEach(b=>b.classList.toggle('is-active',b===button));if(state.selected)history(state.selected.id);
});
$('yuanPause').addEventListener('click',()=>{state.paused=!state.paused;updateMotionLabel();});
$('yuanNext').addEventListener('click',()=>{if(isCompact())$('yuanWindow').scrollBy({top:240,behavior:'smooth'});else rotate(true);});
$('yuanWindow').addEventListener('scroll',()=>{
 if(!isCompact()||state.loading||state.page>=state.lastPage)return;
 const windowNode=$('yuanWindow');
 if(windowNode.scrollTop+windowNode.clientHeight>=windowNode.scrollHeight-220)load(state.page+1,false,true);
});
window.addEventListener('resize',updateMotionLabel);
window.setInterval(()=>rotate(false),2500);
const dialog=$('yuanDialog');
$('yuanCancel').addEventListener('click',()=>dialog.close());
$('yuanConfirm').addEventListener('click',()=>apply());
async function action(url,payload={}){return json(url,{method:'POST',body:JSON.stringify(payload)});}
async function guarded(callback){if(state.busy)return;state.busy=true;try{await callback();}catch(error){showNotice(error.message);}finally{state.busy=false;}}
$('yuanFetch')?.addEventListener('click',()=>guarded(async()=>{const result=await action(root.dataset.rateUrl);state.preview=null;$('yuanApply').disabled=true;showNotice(result.message);await load(1);if(state.selected)history(state.selected.id);}));
$('yuanPreview')?.addEventListener('click',()=>guarded(async()=>{
 const result=await action(root.dataset.previewUrl);state.preview=result;
 $('yuanDialogText').textContent=short(result.count)+' کالا برای تغییر قیمت واجد شرایط هستند. مبلغ‌های زیر فقط پیش‌نمایش‌اند و تا زمان تأیید ثبت نمی‌شوند.';
 const list=$('yuanDialogList');list.replaceChildren();
 for(const item of result.samples){const el=document.createElement('div');el.append(text('strong',item.name),text('span',toman(Math.trunc(item.old_rial/10))+' ← '+toman(Math.trunc(item.new_rial/10))));list.append(el);}
 $('yuanConfirm').hidden=!result.count;
 $('yuanApply').disabled=!result.count;
 dialog.showModal();
}));
$('yuanApply')?.addEventListener('click',()=>{if(state.preview && !dialog.open)dialog.showModal();});
async function apply(){
 if(!state.preview?.count)return;
 await guarded(async()=>{
  $('yuanConfirm').disabled=true;
  try{
   const result=await action(root.dataset.applyUrl,{rate_id:state.preview.rate_id,confirmed:true});
   dialog.close();state.preview=null;$('yuanApply').disabled=true;
   showNotice(result.message+' تعداد کالاهای تغییر یافته: '+short(result.changed));
   await load(1);if(state.selected)history(state.selected.id);
  }finally{$('yuanConfirm').disabled=false;}
 });
}
updateMotionLabel();load(1);
})();
