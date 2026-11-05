/* Pure UI ↔ Laravel API adapter; do not calculate interest in the browser. */
(function(root){
  'use strict';
  const MAX_RIAL=999999999999999;
  const toAmount=(value,label)=>{
    if(!Number.isSafeInteger(value)||value<0||value>MAX_RIAL)
      throw Error(label+' باید مبلغ صحیح و معتبر ریالی باشد.');
    return String(value);
  };
  const knownType='cheque';
  function buildPayload(mode,state,config,customer){
    if(!['cheque','cash','value'].includes(mode))throw Error('نوع محاسبه نامعتبر است.');
    if(!Number.isFinite(config.monthly)||config.monthly<0||config.monthly>100)
      throw Error('نرخ سود ماهانه معتبر نیست.');
    if(!config.baseDate)throw Error('تاریخ مبنای محاسبه را انتخاب کنید.');
    const fixed=[];
    if(state.cash===null||!Number.isSafeInteger(state.cash))
      throw Error('مبلغ نقدی را به‌درستی وارد کنید.');
    if(state.cash>0)fixed.push({kind:'cash',due_date:config.baseDate,amount_rial:toAmount(state.cash,'نقدی')});
    for(const [i,item] of state.known.entries()){
      if(!item.due)throw Error('تاریخ سررسید چک '+(i+1)+' را وارد کنید.');
      fixed.push({kind:knownType,due_date:item.due,amount_rial:toAmount(item.amount,'مبلغ چک '+(i+1))});
    }
    if(mode==='value'&&fixed.length===0)throw Error('حداقل یک مبلغ چک یا نقدی وارد کنید.');
    let price=null;
    if(mode!=='value'){
      price=toAmount(state.price,'قیمت نقدی');
      if(Number(price)===0)throw Error('قیمت نقدی کالا باید بیشتر از صفر باشد.');
    }
    const weighted=mode==='cheque'?state.future.map((item,i)=>{
      if(!item.due)throw Error('سررسید چک '+(i+1)+' را وارد کنید.');
      if(!Number.isFinite(item.weight)||item.weight<=0||item.weight>1000000)
        throw Error('وزن چک '+(i+1)+' باید مثبت باشد.');
      return {due_date:item.due,weight:String(item.weight)};
    }):[];
    if(mode==='cheque'&&weighted.length===0)
      throw Error('برای محاسبه مبلغ چک، یک تاریخ سررسید اضافه کنید.');
    return {
      mode:mode==='cheque'?'schedule':mode==='cash'?'cash_needed':'cash_value',
      price_rial:price,
      monthly_percent:String(config.monthly),
      base_date:config.baseDate,
      customer_name:(customer||'').trim().slice(0,100),
      fixed_payments:fixed,weighted_payments:weighted
    };
  }
  function toViewModel(mode,state,result){
    const known=result.fixed_payments.slice(state.cash>0?1:0).map(row=>({
      amount:row.amount_rial,day:row.day
    }));
    const future=result.flexible_payments.map(row=>({
      amount:row.amount_rial,day:row.day
    }));
    const answer=mode==='cheque'?result.flexible_total_rial:
      mode==='cash'?result.calculated_cash_rial:result.cash_equivalent_rial;
    if(!Number.isSafeInteger(answer))throw Error('پاسخ سرور معتبر نیست.');
    return {
      answer,
      known,future,
      computedCash:result.calculated_cash_rial??0,
      nominalTotal:result.total_nominal_rial,
      difference:result.nominal_increase_rial,
      presentValue:result.present_value_rounded_rial,
      percent:result.overall_rate*100,
      daily:result.daily_rate
    };
  }
  const api={buildPayload,toViewModel};
  if(typeof module!=='undefined'&&module.exports)module.exports=api;
  root.FinancePaymentQuickAdapter=api;
})(typeof window!=='undefined'?window:globalThis);
