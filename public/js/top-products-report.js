(()=>{'use strict';
const root=document.querySelector('.tp-report');if(!root)return;
const number=n=>new Intl.NumberFormat('fa-IR',{maximumFractionDigits:2}).format(n||0);
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const colors=['#4387da','#19a38d','#a579d8','#ecaa4c','#e77788','#5bb9c5','#797fc9','#98b45c','#cd8760','#6b9aa9'];
const d=JSON.parse(document.getElementById('tp-chart-data').textContent),label=r=>r.name+(r.variant_name?' · '+r.variant_name:'');
const tooltip=document.getElementById('tp-tooltip'),modal=document.getElementById('tp-detail-dialog'),body=document.getElementById('tp-detail-body');let pending,activeTip;
function hideTooltip(){tooltip.hidden=true;activeTip=null;}
function showTooltip(r,event,target){
 activeTip={r,event,target};
 tooltip.innerHTML=`<strong>${esc(label(r))}</strong><div><span>تعداد فروش</span><b>${number(r.quantity)} عدد</b></div><div><span>مبلغ فروش</span><b>${number(r.revenue)} ریال</b></div><div><span>سهم از ${d.metric==='quantity'?'تعداد':'مبلغ'}</span><b>${number(d.total?r[d.metric]/d.total*100:0)}٪</b></div>${r.stock!==undefined?`<div><span>موجودی آزاد فعلی</span><b>${r.stock===null?'—':number(r.stock)}</b></div>`:''}`;
 tooltip.hidden=false;const box=target.getBoundingClientRect(),x=event?.clientX??box.left+box.width/2,y=event?.clientY??box.bottom;
 const width=tooltip.offsetWidth,height=tooltip.offsetHeight;tooltip.style.left=`${Math.max(8,Math.min(x-width/2,innerWidth-width-8))}px`;
 tooltip.style.top=`${y+16+height<innerHeight?Math.max(8,y+16):Math.max(8,y-height-12)}px`;
}
function wire(target,r,details=true){target.setAttribute('aria-describedby','tp-tooltip');
 target.addEventListener('pointerenter',e=>showTooltip(r,e,target));target.addEventListener('pointermove',e=>showTooltip(r,e,target));target.addEventListener('pointerleave',hideTooltip);
 target.addEventListener('focus',()=>showTooltip(r,null,target));target.addEventListener('blur',hideTooltip);
 const activate=()=>{if(details&&r.product_id)openDetail(r.product_id,r.name);else showTooltip(r,null,target);};target.addEventListener('click',activate);
 if(target.tagName.toLowerCase()==='circle')target.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();activate();}});
}
const bars=document.getElementById('tp-bars');if(bars){const max=d.bars[0]?.[d.metric]||0;
 bars.innerHTML=d.bars.map((r,i)=>`<button type="button" class="tp-chart-bar" aria-label="${esc(label(r))}، ${number(r[d.metric])}"><span class="tp-bar-name"><i style="background:${colors[i]}"></i>${esc(label(r))}</span><span class="tp-bar-track"><span class="tp-bar-fill" style="width:${max?r[d.metric]/max*100:0}%;background:${colors[i]}"></span></span><span>${number(r[d.metric])}</span></button>`).join('');
 bars.querySelectorAll('.tp-chart-bar').forEach((el,i)=>wire(el,d.bars[i]));
 const slices=d.slices.map((r,i)=>({...r,color:colors[i]}));
 const rest={name:'سایر کالاها',quantity:Math.max(0,d.summary.quantity-slices.reduce((n,r)=>n+r.quantity,0)),revenue:Math.max(0,d.summary.revenue-slices.reduce((n,r)=>n+r.revenue,0)),color:'#afbbcb'};
 if(rest[d.metric]>0)slices.push(rest);
 const circumference=2*Math.PI*82;let offset=0;
 const arcs=slices.map(r=>{const length=d.total?r[d.metric]/d.total*circumference:0;const arc=`<circle class="tp-ring-segment" cx="100" cy="100" r="82" fill="none" stroke="${r.color}" stroke-width="23" stroke-dasharray="${length} ${circumference-length}" stroke-dashoffset="${-offset}" transform="rotate(-90 100 100)" tabindex="0" role="button" aria-label="${esc(label(r))}، ${number(d.total?r[d.metric]/d.total*100:0)} درصد"/>`;offset+=length;return arc;}).join('');
 const ring=document.getElementById('tp-ring');ring.classList.add('tp-ring');ring.innerHTML=`<svg viewBox="0 0 200 200" aria-label="سهم کالاها از فروش"><circle cx="100" cy="100" r="82" fill="none" stroke="#edf2f8" stroke-width="23"/>${arcs}</svg><div class="tp-ring-center"><strong>${number(d.total)}</strong><span>${d.metric==='quantity'?'عدد فروش':'ریال فروش'}</span></div>`;
 ring.querySelectorAll('.tp-ring-segment').forEach((el,i)=>wire(el,slices[i],Boolean(slices[i].product_id)));
 const legend=document.getElementById('tp-legend');legend.innerHTML=slices.map(r=>`<button type="button" class="tp-legend-row"><span class="tp-dot" style="background:${r.color}"></span><span>${esc(label(r))}</span><strong>${number(d.total?r[d.metric]/d.total*100:0)}٪</strong></button>`).join('');
 legend.querySelectorAll('button').forEach((el,i)=>wire(el,slices[i],Boolean(slices[i].product_id)));
}
function outsideClose(dialog){dialog.addEventListener('click',e=>{if(e.target!==dialog)return;const b=dialog.getBoundingClientRect();if(e.clientX<b.left||e.clientX>b.right||e.clientY<b.top||e.clientY>b.bottom)dialog.close();});}
const filter=document.getElementById('tp-filter-dialog');document.getElementById('tp-filter-open').addEventListener('click',()=>{hideTooltip();filter.showModal();});root.querySelector('[data-close-filters]').addEventListener('click',()=>filter.close());outsideClose(filter);outsideClose(modal);
document.getElementById('tp-close').addEventListener('click',()=>modal.close());modal.addEventListener('close',()=>pending?.abort());
window.addEventListener('scroll',()=>{requestAnimationFrame(()=>{if(!activeTip)return;const {r,event,target}=activeTip;if(target===document.activeElement||target.matches(':hover'))showTooltip(r,target===document.activeElement?null:event,target);else hideTooltip();});},true);window.addEventListener('resize',hideTooltip);document.addEventListener('keydown',e=>{if(e.key==='Escape')hideTooltip();});
async function openDetail(product,name){hideTooltip();pending?.abort();const controller=new AbortController();pending=controller;document.getElementById('tp-detail-title').textContent=name;body.textContent='در حال دریافت جزئیات…';modal.showModal();try{const url=new URL(root.dataset.detailUrl,location.href);url.searchParams.set('product_id',product);const response=await fetch(url,{headers:{Accept:'application/json'},signal:controller.signal});if(!response.ok)throw new Error('دریافت جزئیات انجام نشد؛ دوباره تلاش کنید.');const report=await response.json();
 body.innerHTML=`<p class="tp-caption">${number(report.summary.quantity)} عدد · ${number(report.summary.revenue)} ریال · ${number(report.summary.invoices)} فاکتور</p><h3>مدل‌ها و رنگ‌های فروخته‌شده</h3><div class="tp-detail-list">${report.rows.map(r=>`<div class="tp-detail-row"><span>${esc(r.variant_name||'بدون تنوع')}</span><span>${number(r.quantity)} عدد · ${number(r.revenue)} ریال</span><span>موجودی فعلی: ${r.stock===null?'—':number(r.stock)}</span></div>`).join('')||'داده‌ای وجود ندارد'}</div><h3>روند روزانهٔ ارسال</h3><div class="tp-detail-list">${report.trend.map(r=>`<div class="tp-detail-row"><span>${esc(r.day)}</span><span>${number(r.quantity)} عدد</span><span>${number(r.revenue)} ریال</span></div>`).join('')}</div><h3>فاکتورهای مرتبط</h3><div class="tp-detail-list">${report.invoices.map(r=>`<div class="tp-detail-row"><span>${r.url?`<a href="${esc(r.url)}" target="_blank" rel="noopener">فاکتور ${esc(r.uuid)}</a>`:`فاکتور ${esc(r.uuid)}`}</span><span>${esc(r.shipped_at)}</span><span>${number(r.quantity)} عدد · ${number(r.revenue)} ریال</span></div>`).join('')}</div>`;
 }catch(e){if(e.name!=='AbortError')body.textContent=e.message;}
}
})();
