(() => {
  'use strict';
  const app=document.getElementById('pc-calculator'); if(!app) return;
  const el=id=>document.getElementById(id);
  const form=el('pc-form'),fixed=el('pc-fixed-body'),weighted=el('pc-weighted-body');
  const price=el('pc-price'),rate=el('pc-rate'),base=el('pc-base-date'),customer=el('pc-customer');
  const format=new Intl.NumberFormat('fa-IR',{maximumFractionDigits:0});
  const digits=s=>String(s??'').replace(/[۰-۹]/g,x=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(x)).replace(/[٠-٩]/g,x=>'٠١٢٣٤٥٦٧٨٩'.indexOf(x)).replace(/[,٬\s\u200e\u200f]/g,'').replace(/٫/g,'.').trim();
  const decimal=s=>digits(String(s??'').replace(/[,٬]/g,'.'));
  const integer=n=>format.format(n);
  const percentage=(v,dec=4)=>(100*v).toLocaleString('fa-IR',{maximumFractionDigits:dec})+'٪';
  let pending=null,seq=0,timer=null,lastVerified=null;
  let mode='schedule';
  const modes={
    schedule:{
      help:'قیمت نقدی کالا را وارد کنید. پیش‌پرداخت و چک‌های معلوم را اضافه کنید؛ مبلغ چک‌های باقیمانده از تاریخ و وزن آنها محاسبه می‌شود.',
      fixedTitle:'پیش‌پرداخت و مبالغ مشخص',
      fixedHelp:'نقد پرداخت‌شده یا چکی با مبلغ معلوم را در این قسمت وارد کنید.',
      answer:'جمع کل پرداخت‌ها',
      explanation:'جمع اسمی مبلغ نقدی و تمام چک‌ها برای این قرارداد.'
    },
    cash_needed:{
      help:'قیمت نقدی کالا و چک‌هایی که مشتری مبلغ و تاریخشان را تعیین کرده وارد کنید؛ سیستم مبلغ نقدی لازم امروز را محاسبه می‌کند.',
      fixedTitle:'چک‌ها و پرداخت‌های معلوم',
      fixedHelp:'مبلغ اسمی هر چک و تاریخ سررسید آن را وارد کنید. پرداخت نقدی قبلی هم قابل اضافه کردن است.',
      answer:'نقدی لازم امروز',
      explanation:'این مبلغ باید در تاریخ مبنا به‌صورت نقد دریافت شود تا ارزش فعلی کل پرداخت‌ها برابر قیمت نقدی کالا شود.'
    },
    cash_value:{
      help:'مبلغ و سررسید چک‌های موجود را وارد کنید؛ ارزش معادل نقدی آن‌ها و سقف قیمت نقدی کالای قابل فروش محاسبه می‌شود.',
      fixedTitle:'مبالغ نقد و چک‌های مشتری',
      fixedHelp:'هر چک را با مبلغ و تاریخ دقیق ثبت کنید؛ برای نقد همان روز «نقدی» را انتخاب کنید.',
      answer:'ارزش نقدی امروز',
      explanation:'ارزش فعلی چک‌ها پس از تنزیل با نرخ تعیین‌شده؛ این عدد، معادل قیمت نقدی خرید است.'
    }
  };
  const originalDate=base.value;
  function status(message){el('pc-result-status').hidden=false;el('pc-result-status').textContent=message;}
  function clearResults(){lastVerified=null;el('pc-print').disabled=true;el('pc-result').hidden=true;el('pc-error').hidden=true;status('اطلاعات را تکمیل کنید و محاسبه را بزنید.');weighted.querySelectorAll('.pc-row-result').forEach(x=>x.textContent='پس از محاسبه');}
  function error(message){lastVerified=null;el('pc-print').disabled=true;el('pc-result').hidden=true;el('pc-error').hidden=false;el('pc-error').textContent=message;status('لطفاً اطلاعات ورودی را بررسی کنید.');}
  function add(template,body,due){
    if(body.rows.length>=40){error('حداکثر ۴۰ ردیف در هر بخش قابل ثبت است.');return null;}
    const row=el(template).content.firstElementChild.cloneNode(true);
    row.querySelector('.pc-due').value=due||base.value;
    if(template==='pc-fixed-template'&&mode!=='schedule')row.querySelector('.pc-kind').value='cheque';
    row.querySelector('.pc-remove').addEventListener('click',()=>{row.remove();el('pc-fixed-empty').hidden=fixed.rows.length>0;queue();});
    body.appendChild(row);el('pc-fixed-empty').hidden=fixed.rows.length>0;return row;
  }
  function collect(){
    return {
      mode,
      price_rial:mode==='cash_value'?null:digits(price.value),
      monthly_percent:decimal(rate.value).replace(/[٪%]/g,''),
      base_date:base.value.trim(),
      customer_name:customer.value.trim(),
      fixed_payments:[...fixed.rows].map(row=>({
        kind:row.querySelector('.pc-kind').value,
        due_date:row.querySelector('.pc-due').value.trim(),
        amount_rial:digits(row.querySelector('.pc-amount').value)
      })),
      weighted_payments:(mode==='schedule'?[...weighted.rows]:[]).map(row=>({
        due_date:row.querySelector('.pc-due').value.trim(),
        weight:decimal(row.querySelector('.pc-weight').value)
      }))
    };
  }
  const complete=data=>Boolean((mode==='cash_value'||data.price_rial)&&data.monthly_percent!==''&&data.base_date&&
    (mode!=='cash_value'||data.fixed_payments.length>0)&&
    data.fixed_payments.every(x=>x.due_date&&x.amount_rial!=='')&&
    data.weighted_payments.every(x=>x.due_date&&x.weight!==''));
  function render(result,inputs){
    for(const [id,value] of Object.entries({
      'pc-nominal':result.total_nominal_rial,'pc-fixed-total':result.fixed_total_rial,
      'pc-cheques-total':result.total_cheques_rial??result.flexible_total_rial,'pc-increase':result.nominal_increase_rial,
      'pc-present':Math.round(result.present_value_rounded_rial)
    }))el(id).textContent=integer(value);
    el('pc-answer-title').textContent=modes[mode].answer;
    el('pc-answer').textContent=integer(mode==='cash_needed'?result.calculated_cash_rial:
      mode==='cash_value'?result.cash_equivalent_rial:result.total_nominal_rial);
    el('pc-answer-help').textContent=modes[mode].explanation;
    el('pc-increase-label').textContent=mode==='cash_value'?'اختلاف جمع اسمی با ارزش نقدی':'اختلاف با قیمت نقدی کالا';
    el('pc-overall').textContent=percentage(result.overall_rate,2);
    el('pc-daily').textContent=percentage(result.daily_rate,4);
    el('pc-result-customer').textContent=inputs.customer_name?'شرایط پیشنهادی: '+inputs.customer_name:'';
    const all=[];
    result.fixed_payments.forEach((item,i)=>all.push({day:item.day,due:inputs.fixed_payments[i]?.due_date||'',amount:item.amount_rial,
      kind:inputs.fixed_payments[i]?.kind==='cheque'?'چک با مبلغ مشخص':'نقدی / پیش‌پرداخت'}));
    result.flexible_payments.forEach((item,i)=>all.push({day:item.day,due:inputs.weighted_payments[i]?.due_date||'',amount:item.amount_rial,kind:'چک محاسبه‌شده'}));
    (result.computed_payments||[]).forEach(item=>all.push({day:item.day,due:inputs.base_date,amount:item.amount_rial,kind:'نقدی لازم امروز (محاسبه‌شده)'}));
    all.sort((a,b)=>a.day-b.day);
    const tbody=el('pc-result-rows');tbody.replaceChildren();
    all.forEach(item=>{const tr=document.createElement('tr');for(const s of [item.kind,item.due,integer(item.day),integer(item.amount)]){
      const td=document.createElement('td');td.textContent=s;tr.appendChild(td);}tbody.appendChild(tr);});
    [...weighted.rows].forEach((row,i)=>row.querySelector('.pc-row-result').textContent=
      result.flexible_payments[i]?integer(result.flexible_payments[i].amount_rial)+' ریال':'—');
    el('pc-result').hidden=false;el('pc-error').hidden=true;el('pc-result-status').hidden=true;
    lastVerified=JSON.stringify(inputs);el('pc-print').disabled=false;
  }
  async function calculate(automatic=false){
    const data=collect();if(!complete(data)){if(!automatic)error('مبلغ، سود ماهانه و تاریخ همه ردیف‌ها را تکمیل کنید.');return;}
    if(pending)pending.abort();
    const controller=new AbortController(),mine=++seq;pending=controller;
    el('pc-submit').disabled=true;el('pc-submit').textContent='در حال محاسبه...';el('pc-print').disabled=true;status('در حال محاسبه...');
    el('pc-error').hidden=true;
    try{
      const response=await fetch(app.dataset.calculateUrl,{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','Accept':'application/json',
          'X-CSRF-TOKEN':form.querySelector('[name="_token"]').value},
        body:JSON.stringify(data),signal:controller.signal});
      const dataOut=await response.json();if(mine!==seq)return;
      if(!response.ok){error(dataOut.errors?Object.values(dataOut.errors).flat().slice(0,4).join('\n'):
        dataOut.message||'خطا در محاسبه');return;}
      render(dataOut.result,data);
    }catch(e){if(e.name!=='AbortError'&&mine===seq)error('اتصال به سرور برقرار نشد؛ دوباره تلاش کنید.');}
    finally{if(mine===seq){pending=null;el('pc-submit').disabled=false;el('pc-submit').textContent='محاسبه شرایط پرداخت';}}
  }
  function queue(){
    clearTimeout(timer);if(pending)pending.abort();pending=null;seq++;
    el('pc-submit').disabled=false;el('pc-submit').textContent='محاسبه شرایط پرداخت';
    clearResults();if(complete(collect()))timer=setTimeout(()=>calculate(true),700);
  }
  function selectMode(next){
    if(!modes[next])return;
    mode=next;
    const settings=modes[next];
    el('pc-price-field').hidden=next==='cash_value';
    price.required=next!=='cash_value';
    el('pc-weighted-panel').hidden=next!=='schedule';
    el('pc-mode-help').textContent=settings.help;
    el('pc-fixed-title').textContent=settings.fixedTitle;
    el('pc-fixed-help').textContent=settings.fixedHelp;
    el('pc-price-label').textContent='قیمت نقدی کالا / قرارداد (ریال)';
    app.querySelectorAll('[data-pc-mode]').forEach(button=>{
      const active=button.dataset.pcMode===next;
      button.setAttribute('aria-pressed',active?'true':'false');
      button.classList.toggle('is-active',active);
    });
    queue();
  }
  function example(name){
    const next=name==='cash_needed'?'cash_needed':name==='cash_value'?'cash_value':'schedule';
    selectMode(next);
    fixed.replaceChildren();weighted.replaceChildren();
    base.value=originalDate;price.value=next==='cash_value'?'':'100000000';rate.value='6';customer.value='';
    const insertFixed=(kind,due,amount)=>{
      const row=add('pc-fixed-template',fixed,due);
      if(!row)return;
      row.querySelector('.pc-kind').value=kind;
      row.querySelector('.pc-amount').value=String(amount);
    };
    const insertWeighted=(due,weight=1)=>{
      const row=add('pc-weighted-template',weighted,due);
      if(row)row.querySelector('.pc-weight').value=String(weight);
    };
    if(name==='schedule'){
      insertFixed('cash',base.value,50000000);
      insertWeighted(app.dataset.firstDue);
    }else if(name==='last_cheque'){
      insertFixed('cash',base.value,20000000);
      insertFixed('cheque',app.dataset.firstDue,30000000);
      insertWeighted(app.dataset.secondDue);
    }else if(name==='cash_needed'){
      insertFixed('cheque',app.dataset.thirdDue,80000000);
    }else if(name==='cash_value'){
      insertFixed('cheque',app.dataset.firstDue,20000000);
      insertFixed('cheque',app.dataset.secondDue,30000000);
      insertFixed('cheque',app.dataset.thirdDue,80000000);
    }
    queue();
  }
  app.querySelectorAll('[data-pc-mode]').forEach(button=>
    button.addEventListener('click',()=>selectMode(button.dataset.pcMode)));
  app.querySelectorAll('[data-pc-example]').forEach(button=>
    button.addEventListener('click',()=>example(button.dataset.pcExample)));
  el('pc-add-fixed').addEventListener('click',()=>{
    const row=add('pc-fixed-template',fixed,base.value);if(row){queue();row.querySelector('.pc-amount').focus();}
  });
  el('pc-add-weighted').addEventListener('click',()=>{
    const row=add('pc-weighted-template',weighted,base.value);if(row){queue();row.querySelector('.pc-due').focus();}
  });
  form.addEventListener('submit',e=>{e.preventDefault();clearTimeout(timer);calculate();});
  form.addEventListener('input',queue);form.addEventListener('change',queue);
  el('pc-print').addEventListener('click',()=>{
    const data=collect();
    if(!lastVerified || JSON.stringify(data)!==lastVerified){
      error('اطلاعات تغییر کرده است. ابتدا محاسبه را دوباره انجام دهید.');
      return;
    }
    // A genuine HTML POST is opened in a new tab: the server recomputes every amount.
    const reportForm=document.createElement('form');
    reportForm.method='POST';reportForm.action=app.dataset.printUrl;
    reportForm.target='_blank';reportForm.rel='noopener';
    reportForm.hidden=true;
    function hiddenInput(name,value){
      const input=document.createElement('input');
      input.type='hidden';input.name=name;input.value=String(value??'');
      reportForm.appendChild(input);
    }
    hiddenInput('_token',form.querySelector('[name="_token"]').value);
    for(const field of ['mode','price_rial','monthly_percent','base_date','customer_name'])hiddenInput(field,data[field]);
    for(const key of ['fixed_payments','weighted_payments']){
      data[key].forEach((row,index)=>{
        for(const [name,value] of Object.entries(row))hiddenInput(key+'['+index+']['+name+']',value);
      });
    }
    document.body.appendChild(reportForm);
    reportForm.submit();
    reportForm.remove();
  });
  el('pc-reset').addEventListener('click',()=>{
    if(pending)pending.abort();pending=null;seq++;clearTimeout(timer);
    fixed.replaceChildren();weighted.replaceChildren();price.value='';rate.value='';customer.value='';base.value=originalDate;
    selectMode('schedule');
    add('pc-weighted-template',weighted,app.dataset.firstDue);
    add('pc-weighted-template',weighted,app.dataset.secondDue);
    el('pc-fixed-empty').hidden=false;clearResults();
  });
  add('pc-weighted-template',weighted,app.dataset.firstDue);
  add('pc-weighted-template',weighted,app.dataset.secondDue);
  el('pc-fixed-empty').hidden=false;
})();
