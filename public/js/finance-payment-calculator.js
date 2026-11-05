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
  const originalDate=base.value;
  function status(message){el('pc-result-status').hidden=false;el('pc-result-status').textContent=message;}
  function clearResults(){lastVerified=null;el('pc-print').disabled=true;el('pc-result').hidden=true;el('pc-error').hidden=true;status('اطلاعات را تکمیل کنید و محاسبه را بزنید.');weighted.querySelectorAll('.pc-row-result').forEach(x=>x.textContent='پس از محاسبه');}
  function error(message){lastVerified=null;el('pc-print').disabled=true;el('pc-result').hidden=true;el('pc-error').hidden=false;el('pc-error').textContent=message;status('لطفاً اطلاعات ورودی را بررسی کنید.');}
  function add(template,body,due){
    if(body.rows.length>=40){error('حداکثر ۴۰ ردیف در هر بخش قابل ثبت است.');return null;}
    const row=el(template).content.firstElementChild.cloneNode(true);
    row.querySelector('.pc-due').value=due||base.value;
    row.querySelector('.pc-remove').addEventListener('click',()=>{row.remove();el('pc-fixed-empty').hidden=fixed.rows.length>0;queue();});
    body.appendChild(row);el('pc-fixed-empty').hidden=fixed.rows.length>0;return row;
  }
  function collect(){
    return {
      price_rial:digits(price.value),
      monthly_percent:decimal(rate.value).replace(/[٪%]/g,''),
      base_date:base.value.trim(),
      customer_name:customer.value.trim(),
      fixed_payments:[...fixed.rows].map(row=>({
        kind:row.querySelector('.pc-kind').value,
        due_date:row.querySelector('.pc-due').value.trim(),
        amount_rial:digits(row.querySelector('.pc-amount').value)
      })),
      weighted_payments:[...weighted.rows].map(row=>({
        due_date:row.querySelector('.pc-due').value.trim(),
        weight:decimal(row.querySelector('.pc-weight').value)
      }))
    };
  }
  const complete=data=>Boolean(data.price_rial&&data.monthly_percent!==''&&data.base_date&&
    data.fixed_payments.every(x=>x.due_date&&x.amount_rial!=='')&&
    data.weighted_payments.every(x=>x.due_date&&x.weight!==''));
  function render(result,inputs){
    for(const [id,value] of Object.entries({
      'pc-nominal':result.total_nominal_rial,'pc-fixed-total':result.fixed_total_rial,
      'pc-cheques-total':result.flexible_total_rial,'pc-increase':result.nominal_increase_rial,
      'pc-present':Math.round(result.present_value_rounded_rial)
    }))el(id).textContent=integer(value);
    el('pc-overall').textContent=percentage(result.overall_rate,2);
    el('pc-daily').textContent=percentage(result.daily_rate,4);
    el('pc-result-customer').textContent=inputs.customer_name?'شرایط پیشنهادی: '+inputs.customer_name:'';
    const all=[];
    result.fixed_payments.forEach((item,i)=>all.push({day:item.day,due:inputs.fixed_payments[i]?.due_date||'',amount:item.amount_rial,
      kind:inputs.fixed_payments[i]?.kind==='cheque'?'چک با مبلغ مشخص':'نقدی / پیش‌پرداخت'}));
    result.flexible_payments.forEach((item,i)=>all.push({day:item.day,due:inputs.weighted_payments[i]?.due_date||'',amount:item.amount_rial,kind:'چک محاسبه‌شده'}));
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
    for(const field of ['price_rial','monthly_percent','base_date','customer_name'])hiddenInput(field,data[field]);
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
    add('pc-weighted-template',weighted,app.dataset.firstDue);
    add('pc-weighted-template',weighted,app.dataset.secondDue);
    el('pc-fixed-empty').hidden=false;clearResults();
  });
  add('pc-weighted-template',weighted,app.dataset.firstDue);
  add('pc-weighted-template',weighted,app.dataset.secondDue);
  el('pc-fixed-empty').hidden=false;
})();
