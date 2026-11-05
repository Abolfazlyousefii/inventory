
/* Production UI: one compact tabbed interface, verified Laravel calculations, read-only reports. */
(() => {
 'use strict';
 const F=window.FinanceDate,Adapter=window.FinancePaymentQuickAdapter,app=document.getElementById('financeQuickCalc'),$=id=>app.querySelector('#'+id);
 if(!app||!F||!Adapter)return;
 const en=new Intl.NumberFormat('en-US',{maximumFractionDigits:0});
 const fa=new Intl.NumberFormat('fa-IR',{maximumFractionDigits:0});
 const toLatin=s=>String(s??'').replace(/[۰-۹]/g,x=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(x)).replace(/[٠-٩]/g,x=>'٠١٢٣٤٥٦٧٨٩'.indexOf(x));
 const digits=s=>toLatin(s).replace(/[٬,\s\u200e\u200f]/g,'').trim();
 const money=v=>v==null||!Number.isFinite(v)?'':en.format(v);
 const number=v=>fa.format(v);
 const moneyValue=raw=>{const s=digits(raw);return s===''?null:(/^\d+$/.test(s)?Number(s):NaN);};
 const dateInDays=n=>F.jalaliAfterDays(config.baseDate,n);
 const safeSave=()=>{try{localStorage.setItem('finance-quick-monthly-rate-v1',String(config.monthly))}catch{}};
 const names={cheque:'مبلغ چک آخر',cash:'نقدی باقیمانده',value:'ارزش نقدی چک‌ها'};
 const monthNames=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
 let config={monthly:6,baseDate:app.dataset.todayJalali||F.todayJalali()};
 try{const saved=Number(localStorage.getItem('finance-quick-monthly-rate-v1'));
   if(localStorage.getItem('finance-quick-monthly-rate-v1')!==null&&Number.isFinite(saved)&&saved>=0&&saved<=100)config.monthly=saved;
 }catch{}
 function defaultStates(){return {
  cheque:{price:100000000,cash:50000000,known:[],future:[{due:dateInDays(30),weight:1}]},
  cash:{price:100000000,cash:0,known:[{amount:50000000,due:dateInDays(30)},{amount:50000000,due:dateInDays(60)}],future:[]},
  value:{price:null,cash:0,known:[{amount:100000000,due:dateInDays(180)}],future:[]}
 };}
 let states=defaultStates(),active='cheque',weightVisible=false,showExtraCash={cash:false,value:false},lastResult=null,lastPayload=null;
 let requestSequence=0,pendingController=null,debounceTimer=null;
 let pickedInput=null,calYear=1405,calMonth=8;
 const escapeHTML=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
 const inputEl=(row,field)=>row.querySelector('[data-field="'+field+'"]');
 function dateControl(value,index,kind){const id=`date-${kind}-${index}`;return `<div class="date-wrap"><input id="${id}" class="row-input date-input" type="text" inputmode="numeric" autocomplete="off" data-field="due" value="${escapeHTML(value)}" aria-label="تاریخ شمسی چک ${index+1}" placeholder="1405/09/14"><button class="calendar-trigger" type="button" data-calendar-for="${id}" aria-label="انتخاب تاریخ شمسی چک ${index+1}">▦</button></div>`;}
 function rowMarkup(item,index,kind){
   const known=kind==='known',weightClass=!known&&weightVisible?' with-weights':'';
   const due=dateControl(item.due,index,kind),amount=known?`<label class="row-field"><span>مبلغ چک · ریال</span><input class="row-input" type="text" inputmode="numeric" autocomplete="off" data-money data-field="amount" value="${money(item.amount)}" placeholder="مبلغ چک"></label>`:'';
   const weight=!known&&weightVisible?`<label class="row-field"><span>سهم / وزن</span><input class="row-input" type="text" inputmode="decimal" data-field="weight" value="${item.weight}" placeholder="۱"></label>`:'';
   return `<div class="payment-row ${known?'known-row':'future-row'}${weightClass}" data-type="${kind}" data-index="${index}">${amount}<label class="row-field"><span>تاریخ سررسید شمسی</span>${due}<small class="due-hint">${dueText(item.due)}</small></label>${weight}<button type="button" class="remove" data-remove aria-label="حذف چک ${index+1}">×</button></div>`;
 }
 function dueText(due){try{const days=F.dayDifference(config.baseDate,due);return days===0?'امروز':number(days)+' روز دیگر';}catch{return 'تاریخ را بررسی کن';}}
 function drawRows(){const state=states[active];$('knownRows').innerHTML=state.known.map((r,i)=>rowMarkup(r,i,'known')).join('');$('futureRows').innerHTML=state.future.map((r,i)=>rowMarkup(r,i,'future')).join('');$('knownSection').hidden=active==='cheque'&&state.known.length===0;
   $('weightArrow').textContent=weightVisible?'⌃':'⌄';$('toggleWeights').setAttribute('aria-expanded',String(weightVisible));}
 function syncFields(){const state=states[active];const isCheque=active==='cheque',isCash=active==='cash';
   $('priceBox').hidden=active==='value'&&!showExtraCash.value&&state.cash===0;
   $('priceBox').children[0].hidden=active==='value';
   const cashVisible=isCheque||(showExtraCash[active]||false)||state.cash>0;
   $('cashField').hidden=!cashVisible;
   $('cashExtra').hidden=isCheque||cashVisible;
   $('priceBox').hidden=active==='value'&&!cashVisible;
   $('priceInput').value=money(state.price);$('cashInput').value=money(state.cash);
   $('cashLabel').innerHTML=isCheque?'نقدی مشتری، همین امروز <em>ریال</em>':'نقدی گرفته‌شده تا امروز <em>ریال</em>';
   $('knownTitle').textContent=isCheque?'چک‌هایی که مبلغ‌شان مشخص است':'مبلغ و تاریخ چک‌های مشتری';
   $('knownHint').textContent=isCheque?'اگر از قبل چکی با مبلغ مشخص گرفته‌ای، اینجا اضافه کن.':'هر چک را با مبلغ واقعی و سررسید خودش وارد کن.';
   $('addKnown').textContent=isCheque?'+ افزودن چک با مبلغ مشخص':'+ افزودن چک دیگر';
   $('futureSection').hidden=!isCheque;
   $('modeHelp').textContent=isCheque?'قیمت نقدی و نقدی دریافتی را بنویس؛ تاریخ چک آخر را انتخاب کن.':isCash?'قیمت جنس و چک‌های مشتری را بنویس؛ می‌گیم چقدر دیگر نقد بگیری.':'فقط مبلغ چک‌ها و تاریخ سررسیدشان را بنویس؛ می‌گیم امروز چقدر ارزش دارند.';
   $('inputPanel').setAttribute('aria-labelledby','tab-'+active);
   drawRows();
 }
 function clearError(message){const error=$('errorBox');error.textContent=message||'';error.hidden=!message;if(message){lastResult=null;lastPayload=null;$('answerAmount').textContent='—';$('answerHint').textContent='ورودی‌ها را بررسی کن.';$('statOne').textContent='—';$('statTwo').textContent='—';$('printBtn').disabled=true;$('detailsRows').replaceChildren();}}
 const detailRow=(label,value)=>{const div=document.createElement('div');div.className='detail-row';const l=document.createElement('span'),v=document.createElement('strong');l.textContent=label;v.textContent=number(value)+' ریال';div.append(l,v);return div;};
 function render(r){clearError('');lastResult=r;
   $('answerLabel').textContent=active==='cheque'?(r.future.length===1?'مبلغ چک آخر':'جمع چک‌های محاسبه‌شده'):active==='cash'?'نقدی باقیمانده برای دریافت':'ارزش امروز چک‌ها';
   $('answerAmount').textContent=number(r.answer);
   $('answerHint').textContent=active==='cheque'?(r.future.length===1?'مبلغ چکی که باید از مشتری بگیری.':'جزئیات مبلغ هر چک را از بخش پایین ببین.'):
     active==='cash'?'این مبلغ را نقد بگیر تا قیمت نقدی کالا تکمیل شود.':'تا این مقدار، معادل قیمت نقدی کالا قابل فروش است.';
   $('statOneLabel').textContent=active==='value'?'جمع مبلغ روی چک‌ها':'جمع کل اسمی دریافتی‌ها';
   $('statOne').textContent=number(r.nominalTotal);
   $('statTwoLabel').textContent=active==='value'?'تفاوت با ارزش نقدی':'تفاوت با قیمت نقدی';
   $('statTwo').textContent=number(r.difference);
   $('detailsRows').replaceChildren();
   const state=states[active];
   if(state.cash>0)$('detailsRows').appendChild(detailRow('نقدی دریافت‌شده',state.cash));
   r.known.forEach((row,i)=>$('detailsRows').appendChild(detailRow('چک مشخص '+number(i+1)+' · '+number(row.day)+' روزه',row.amount)));
   r.future.forEach((row,i)=>$('detailsRows').appendChild(detailRow('چک محاسبه‌شده '+number(i+1)+' · '+number(row.day)+' روزه',row.amount)));
   if(active==='cash')$('detailsRows').appendChild(detailRow('نقدی لازم امروز',r.computedCash));
   $('formulaInfo').textContent='تاریخ مبنا '+config.baseDate+' · سود ماهانه '+number(config.monthly)+'٪ · ماه مالی ۳۰روزه · ارزش فعلی = مبلغ چک ÷ (۱ + نرخ مؤثر روزانه) به توان تعداد روز.';
   $('printBtn').disabled=false;
 }
 function collectPayload(){
   const state=states[active];
   if(state.known.length>40||state.future.length>40)throw Error('حداکثر ۴۰ چک در هر بخش مجاز است.');
   // The calendar is only a date picker; server also verifies each Persian date.
   for(const item of [...state.known,...(active==='cheque'?state.future:[])])
     F.dayDifference(config.baseDate,item.due);
   return Adapter.buildPayload(active,state,config,$('customerInput').value);
 }
 function invalidate(){
   lastResult=null;lastPayload=null;$('printBtn').disabled=true;
   $('errorBox').hidden=true;$('errorBox').textContent='';
   $('detailsRows').replaceChildren();
   $('answerAmount').textContent='…';$('answerHint').textContent='در حال محاسبه…';
   $('statOne').textContent='—';$('statTwo').textContent='—';
 }
 function recalc(immediate=false){
   clearTimeout(debounceTimer);
   if(pendingController)pendingController.abort();
   pendingController=null;
   const id=++requestSequence;
   invalidate();
   debounceTimer=setTimeout(()=>{void calculateOnServer(id);},immediate?0:600);
 }
 async function calculateOnServer(id){
   let data;
   try{data=collectPayload();}
   catch(err){if(id===requestSequence)clearError(err.message||'ورودی‌ها را اصلاح کن.');return;}
   const controller=new AbortController();pendingController=controller;
   try{
     const response=await fetch(app.dataset.calculateUrl,{
       method:'POST',credentials:'same-origin',
       headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':app.dataset.csrf},
       body:JSON.stringify(data),signal:controller.signal
     });
     const body=await response.json();
     if(id!==requestSequence)return;
     if(!response.ok){
       const errorMessage=body.errors?Object.values(body.errors).flat().slice(0,3).join('\n'):
         body.message||'محاسبه انجام نشد. لطفاً ورودی‌ها را بررسی کن.';
       clearError(errorMessage);return;
     }
     const state=states[active];
     const output=Adapter.toViewModel(active,state,body.result);
     render(output);
     lastPayload=JSON.stringify(data);
   }catch(err){
     if(err.name!=='AbortError'&&id===requestSequence)
       clearError('خطا در ارتباط با سرور؛ مجدداً محاسبه کن.');
   }finally{
     if(id===requestSequence)pendingController=null;
   }
 }
 function printVerified(){
   if(!lastResult||!lastPayload)return;
   let current;
   try{current=collectPayload();}
   catch(err){clearError(err.message);return;}
   if(JSON.stringify(current)!==lastPayload){
     clearError('اطلاعات تغییر کرده است؛ لطفاً دوباره محاسبه کن.');
     return;
   }
   // Always POST user inputs so Laravel recomputes the A4 report itself.
   const form=document.createElement('form');
   form.method='POST';form.action=app.dataset.printUrl;form.target='_blank';form.hidden=true;
   const put=(key,val)=>{
     if(val==null)return;
     const input=document.createElement('input');input.type='hidden';input.name=key;
     input.value=String(val);form.appendChild(input);
   };
   put('_token',app.dataset.csrf);
   for(const key of ['mode','price_rial','monthly_percent','base_date','customer_name'])
     put(key,current[key]);
   for(const group of ['fixed_payments','weighted_payments'])
     current[group].forEach((item,i)=>Object.entries(item).forEach(([name,value])=>put(group+'['+i+']['+name+']',value)));
   document.body.appendChild(form);
   form.submit();form.remove();
 }
 function showMode(mode){if(!states[mode])return;active=mode;
   app.querySelectorAll('[data-mode]').forEach(btn=>{const on=btn.dataset.mode===mode;btn.classList.toggle('active',on);btn.setAttribute('aria-selected',String(on));btn.tabIndex=on?0:-1});
   syncFields();recalc();}
 function addKnown(){const state=states[active];if(state.known.length>=40)return;const next=Math.min(3650,(state.known.length+1)*30);state.known.push({amount:null,due:dateInDays(next)});drawRows();recalc();$('knownRows').lastElementChild?.querySelector('[data-field="amount"]')?.focus();}
 function addFuture(){const state=states.cheque;if(state.future.length>=40)return;const next=Math.min(3650,(state.future.length+1)*30);state.future.push({due:dateInDays(next),weight:1});drawRows();recalc();$('futureRows').lastElementChild?.querySelector('[data-field="due"]')?.focus();}
 function updateDueHints(){for(const item of app.querySelectorAll('.payment-row')){const input=inputEl(item,'due'),hint=item.querySelector('.due-hint');if(input&&hint)hint.textContent=dueText(input.value);}}
 function handleInput(ev){const target=ev.target;const row=target.closest('.payment-row');if(row){const type=row.dataset.type,index=Number(row.dataset.index);const item=states[active][type==='known'?'known':'future'][index];if(!item)return;
     if(target.dataset.field==='amount')item.amount=moneyValue(target.value);
     else if(target.dataset.field==='due'){item.due=target.value.trim();updateDueHints();}
     else if(target.dataset.field==='weight'){const n=Number(toLatin(target.value).replace(/٫/g,'.'));item.weight=target.value.trim()===''?NaN:n;}
   }else if(target.id==='priceInput')states[active].price=moneyValue(target.value);
   else if(target.id==='cashInput')states[active].cash=moneyValue(target.value);
   recalc();
 }
 function formatMoneyField(input){const n=moneyValue(input.value);if(n!==null&&Number.isFinite(n)&&Number.isSafeInteger(n))input.value=money(n);}
 function saveSettings(ev){ev.preventDefault();const normalizedRate=toLatin($('rateInput').value).replace(/٫/g,'.').trim();const percent=Number(normalizedRate);
   try{if(!/^\d{1,3}(?:\.\d{1,4})?$/.test(normalizedRate))throw Error('نرخ سود حداکثر چهار رقم اعشار داشته باشد.');F.rate(percent);const date=$('baseDateInput').value.trim();F.dayDifference(date,date);
     config.monthly=percent;config.baseDate=date;safeSave();$('rateChip').textContent=number(config.monthly)+'٪';$('settingsError').hidden=true;$('settingsDialog').close();drawRows();recalc(true);
   }catch(err){$('settingsError').hidden=false;$('settingsError').textContent=err.message||'مقادیر تنظیمات معتبر نیست.';}}
 // Full Persian calendar: any valid Jalali date, no time or hour controls.
 function openCalendar(input){pickedInput=input;let date=input.value.trim();try{F.toGregorianDay(date);}catch{date=config.baseDate}
   [calYear,calMonth]=date.split('/').slice(0,2).map(Number);renderCalendar();$('calendarDialog').showModal();}
 function renderCalendar(){if(calMonth>12){calMonth=1;calYear++}if(calMonth<1){calMonth=12;calYear--}calYear=Math.min(1499,Math.max(1200,calYear));
   $('calMonth').textContent=monthNames[calMonth-1]+' '+number(calYear);const container=$('calendarDays');container.replaceChildren();const first=`${calYear}/${String(calMonth).padStart(2,'0')}/01`;
   let offset=0;try{const day=F.toGregorianDay(first);offset=(new Date(day*86400000).getUTCDay()+1)%7}catch{}
   for(let i=0;i<offset;i++){const empty=document.createElement('span');container.append(empty)}
   const selected=pickedInput?.value.trim(),today=config.baseDate;
   for(let d=1;d<=31;d++){const value=`${calYear}/${String(calMonth).padStart(2,'0')}/${String(d).padStart(2,'0')}`;try{F.toGregorianDay(value)}catch{break}
     const btn=document.createElement('button');btn.type='button';btn.textContent=number(d);btn.dataset.date=value;btn.className=(selected===value?'selected ':'')+(value===today?'today':'');btn.setAttribute('aria-label','انتخاب '+value);container.append(btn)}
 }
 function chooseDate(value){if(!pickedInput)return;pickedInput.value=value;pickedInput.dispatchEvent(new Event('input',{bubbles:true}));$('calendarDialog').close();}
 function moveMonth(n){calMonth+=n;if(calMonth<1){calMonth=12;calYear--}if(calMonth>12){calMonth=1;calYear++}renderCalendar();}
 // Simple, keyboard-friendly tab navigation.
 app.querySelectorAll('[data-mode]').forEach(btn=>btn.addEventListener('click',()=>showMode(btn.dataset.mode)));
 $('addKnown').addEventListener('click',addKnown);$('addFuture').addEventListener('click',addFuture);
 $('toggleWeights').addEventListener('click',()=>{weightVisible=!weightVisible;drawRows();});
 $('showCash').addEventListener('click',()=>{showExtraCash[active]=true;syncFields();$('cashInput').focus();});
 $('inputPanel').addEventListener('click',ev=>{const btn=ev.target.closest('[data-remove]');if(!btn)return;const row=btn.closest('.payment-row');states[active][row.dataset.type==='known'?'known':'future'].splice(Number(row.dataset.index),1);drawRows();recalc();});
 $('inputPanel').addEventListener('input',handleInput);
 $('inputPanel').addEventListener('blur',ev=>{if(ev.target.matches('[data-money]')||['priceInput','cashInput'].includes(ev.target.id))formatMoneyField(ev.target)},true);
 $('calculateBtn').addEventListener('click',()=>{recalc(true);app.querySelector('.answer').scrollIntoView({behavior:'smooth',block:'nearest'});});
 $('resetBtn').addEventListener('click',()=>{const state=states[active];state.price=active==='value'?null:null;state.cash=0;state.known=[];state.future=active==='cheque'?[{due:dateInDays(30),weight:1}]:[];showExtraCash[active]=false;syncFields();recalc();$('customerInput').value='';});
 $('printBtn').addEventListener('click',printVerified);
 $('openSettings').addEventListener('click',()=>{$('rateInput').value=String(config.monthly);$('baseDateInput').value=config.baseDate;$('settingsDialog').showModal();});
 $('closeSettings').addEventListener('click',()=>$('settingsDialog').close());
 $('settingsForm').addEventListener('submit',saveSettings);
 app.addEventListener('click',ev=>{const btn=ev.target.closest('[data-calendar-for]');if(!btn)return;const input=$(btn.dataset.calendarFor);if(input)openCalendar(input)});
 $('calendarClose').addEventListener('click',()=>$('calendarDialog').close());$('calPrev').addEventListener('click',()=>moveMonth(-1));$('calNext').addEventListener('click',()=>moveMonth(1));
 $('calendarToday').addEventListener('click',()=>chooseDate(config.baseDate));
 $('calendarDays').addEventListener('click',ev=>{const btn=ev.target.closest('[data-date]');if(btn)chooseDate(btn.dataset.date)});

 $('rateChip').textContent=number(config.monthly)+'٪';
 showMode(active);
})();

