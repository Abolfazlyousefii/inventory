(() => {
 'use strict';
 const root=document.querySelector('[data-hr-workspace]');if(!root)return;
 const headers={'X-Requested-With':'XMLHttpRequest'},filters=root.querySelector('[data-hr-filters]'),dialog=root.querySelector('#hrDetailDialog');
 let listRequest,detailRequest,composeRequest,timer,submitting=false,dateTarget;
 const composeDialog=root.querySelector('#hrComposeDialog'),composeHost=root.querySelector('[data-hr-compose-host]');
 const snapshot=form=>form?JSON.stringify([...new FormData(form)].map(([key,value])=>[key,value instanceof File?value.name+':'+value.size:value])):'';
 const baselines=new WeakMap();
 function initForm(scope){
  const form=scope.querySelector('form[data-hr-save]');if(!form)return;
  const unit=form.querySelector('[data-hr-unit]');
  const hours=()=>form.querySelectorAll('[data-hr-hourly]').forEach(x=>{const show=unit.value==='ساعتی';x.hidden=!show;x.querySelectorAll('select').forEach(select=>select.disabled=!show);});
  form.querySelectorAll('[data-hr-time]').forEach(field=>{const input=field.querySelector('input'),hour=field.querySelector('[data-hr-clock="hour"]'),minute=field.querySelector('[data-hr-clock="minute"]');const parts=input.value.split(':');hour.value=parts[0]||'08';minute.value=parts[1]||'00';field.addEventListener('change',()=>{input.value=hour.value+':'+minute.value;});});
  unit?.addEventListener('change',hours);if(unit)hours();baselines.set(form,snapshot(form));
 }
 const dirty=form=>form&&baselines.has(form)&&snapshot(form)!==baselines.get(form);
 function closeCompose(){if(submitting)return;const form=composeHost.querySelector('form');if(dirty(form)&&!confirm('اطلاعات واردشده ذخیره نشده است. فرم بسته شود؟'))return;composeRequest?.abort();composeDialog.close();composeHost.replaceChildren();}
 composeDialog?.addEventListener('cancel',e=>{e.preventDefault();closeCompose();});
 const notice=(form)=>form.querySelector('[data-hr-feedback]')||form.closest('.hr-detail')?.querySelector('[data-hr-feedback]')||root.querySelector('[data-hr-feedback]');
 async function loadList(url){
  listRequest?.abort();const request=listRequest=new AbortController(),list=root.querySelector('#hrEntryList');if(!list)return;
  list.setAttribute('aria-busy','true');
  try{const response=await fetch(url,{headers,signal:request.signal});if(!response.ok||response.redirected){let message='دریافت فهرست انجام نشد؛ بازهٔ تاریخ و فیلترها را بررسی کنید.';if(response.headers.get('content-type')?.includes('json')){const data=await response.json();message=Object.values(data.errors||{}).flat().join('\n')||data.message||message;}throw Error(message);}const html=await response.text();if(request.signal.aborted)return;const replacement=new DOMParser().parseFromString(html,'text/html').querySelector('#hrEntryList');if(!replacement)throw Error('پاسخ فهرست معتبر نیست.');list.replaceWith(replacement);history.replaceState(null,'',url);root.querySelector('[data-hr-feedback]').textContent='';}
  catch(error){if(error.name!=='AbortError')root.querySelector('[data-hr-feedback]').textContent=error.message;}
  finally{if(listRequest===request)root.querySelector('#hrEntryList')?.removeAttribute('aria-busy');}
 }
 function search(){const url=new URL(filters.action);new FormData(filters).forEach((value,key)=>{if(value!=='')url.searchParams.set(key,value);});root.querySelectorAll('[data-hr-export]').forEach(link=>{const target=new URL(link.dataset.exportUrl);target.search=url.search;link.href=target;});loadList(url);}
 filters?.addEventListener('submit',e=>{e.preventDefault();clearTimeout(timer);search();});
 filters?.addEventListener('input',e=>{if(e.target.name==='q'){clearTimeout(timer);timer=setTimeout(search,280);}});
 filters?.addEventListener('change',()=>{clearTimeout(timer);search();});
 root.addEventListener('click',async e=>{
  const compose=e.target.closest('[data-hr-compose]');if(compose&&composeDialog){
   e.preventDefault();if(submitting)return;composeRequest?.abort();const request=composeRequest=new AbortController();composeHost.textContent='در حال دریافت فرم…';if(!composeDialog.open)composeDialog.showModal();
   try{const response=await fetch(compose.href,{headers,signal:request.signal});if(!response.ok||response.redirected)throw Error('فرم دریافت نشد؛ دسترسی یا اتصال را بررسی کنید.');const html=await response.text();if(!request.signal.aborted){composeHost.innerHTML=html;initForm(composeHost);composeHost.querySelector('input:not([type=hidden]),select,textarea')?.focus();}}
   catch(error){if(error.name!=='AbortError')composeHost.textContent=error.message;}
  }
  if(e.target.closest('[data-hr-compose-close]'))closeCompose();
  const tab=e.target.closest('[data-hr-tab]');if(tab&&filters){e.preventDefault();filters.elements.tab.value=tab.dataset.hrTab;filters.elements.status.value='';root.querySelectorAll('[data-hr-tab]').forEach(x=>x.classList.toggle('is-current',x===tab));search();}
  const pager=e.target.closest('.hr-pagination a');if(pager){e.preventDefault();loadList(pager.href);}
  const detail=e.target.closest('[data-hr-detail]');if(detail&&dialog){
   e.preventDefault();detailRequest?.abort();const request=detailRequest=new AbortController(),host=root.querySelector('[data-hr-detail-host]');host.textContent='در حال دریافت جزئیات…';dialog.showModal();
   try{const response=await fetch(detail.href,{headers,signal:request.signal});if(!response.ok||response.redirected)throw Error('جزئیات دریافت نشد یا اجازه مشاهده ندارید.');const html=await response.text();if(!request.signal.aborted)host.innerHTML=html;}
   catch(error){if(error.name!=='AbortError')host.textContent=error.message;}
  }
  if(e.target.closest('[data-hr-close]')&&!submitting){detailRequest?.abort();dialog.close();}
 });
 dialog?.addEventListener('cancel',e=>{if(submitting)e.preventDefault();else detailRequest?.abort();});
 root.addEventListener('submit',async e=>{
  const form=e.target;if(!form.matches('[data-hr-save]'))return;e.preventDefault();if(submitting)return;
  const feedback=notice(form),body=new FormData(form);if(e.submitter?.name)body.set(e.submitter.name,e.submitter.value);
  if(e.submitter?.value==='cancel'&&!confirm('درخواست مرخصی لغو شود؟'))return;
  const buttons=[...form.querySelectorAll('button[type=submit]')];submitting=true;buttons.forEach(x=>x.disabled=true);if(feedback)feedback.textContent='در حال ثبت…';
  try{const response=await fetch(form.getAttribute('action'),{method:'POST',headers:{...headers,Accept:'application/json'},body});let data;try{data=await response.json();}catch{throw Error('پاسخ معتبر دریافت نشد؛ پیش از تلاش مجدد سوابق را بررسی کنید.');}if(!response.ok)throw Error(Object.values(data.errors||{}).flat().join('\n')||data.message||'ثبت انجام نشد.');if(!data.ok||!data.url)throw Error('پاسخ ثبت معتبر نیست.');if(composeDialog?.contains(form)){baselines.delete(form);submitting=false;composeDialog.close();composeHost.replaceChildren();await loadList(window.location.href);root.querySelector('[data-hr-feedback]').textContent=data.message||'ثبت با موفقیت انجام شد؛ سوابق با فیلتر فعلی نمایش داده می‌شود.';}else{window.location.assign(data.url);}}
  catch(error){if(feedback)feedback.textContent=error.message;submitting=false;buttons.forEach(x=>x.disabled=false);}
 });
 initForm(root);
 const dateDialog=root.querySelector('#hrDateDialog'),datePicker=root.querySelector('#hrDatePicker');
 const normalize=value=>value.replace(/[۰-۹٠-٩]/g,c=>String(c.charCodeAt(0)-(c.charCodeAt(0)>1775?1776:1632)));
 if(dateDialog&&window.jalaliDatepicker){
  window.jalaliDatepicker.startWatch({selector:'#hrDatePicker',container:'#hrDateDialog',autoShow:false,time:false,persianDigits:false,hideAfterChange:true});
  const open=input=>{dateTarget=input;datePicker.value=normalize(input.value);window.jalaliDatepicker.updateOptions({container:'#hrDateDialog',autoShow:false,time:false});dateDialog.showModal();window.jalaliDatepicker.show(datePicker);};
  root.addEventListener('click',e=>{const button=e.target.closest('[data-hr-calendar]');if(button){e.preventDefault();e.stopPropagation();open(button.previousElementSibling);}});
  datePicker.addEventListener('jdp:change',()=>{if(!dateTarget)return;dateTarget.value=datePicker.value;dateTarget.dispatchEvent(new Event('change',{bubbles:true}));dateDialog.close();});
  const closeDate=()=>{datePicker.blur();window.jalaliDatepicker.hide();dateDialog.close();};
  root.querySelector('[data-hr-date-close]').addEventListener('pointerdown',e=>e.preventDefault());
  root.querySelector('[data-hr-date-close]').addEventListener('click',e=>{e.preventDefault();e.stopPropagation();closeDate();});
  dateDialog.addEventListener('cancel',e=>{e.preventDefault();closeDate();});
  dateDialog.addEventListener('click',e=>{e.stopPropagation();if(e.target===dateDialog){const box=dateDialog.getBoundingClientRect();if(e.clientX<box.left||e.clientX>box.right||e.clientY<box.top||e.clientY>box.bottom)closeDate();}});
  datePicker.addEventListener('click',()=>window.jalaliDatepicker.show(datePicker));
  dateDialog.addEventListener('close',()=>{datePicker.blur();window.jalaliDatepicker.hide();const target=dateTarget;dateTarget=null;requestAnimationFrame(()=>target?.parentElement.querySelector('[data-hr-calendar]')?.focus());});
 }
 window.addEventListener('beforeunload',e=>{if(!submitting&&[...root.querySelectorAll('form[data-hr-save]')].some(dirty)){e.preventDefault();e.returnValue='';}});
})();
