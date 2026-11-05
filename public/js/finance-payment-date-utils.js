/* Gregorian/Jalali calendar support for the UI only; financial math lives in Laravel. */
(function(root){
  'use strict';
  const MAX_DAYS=3650;
  const persian=new Intl.DateTimeFormat('en-US-u-ca-persian',{
    timeZone:'UTC',year:'numeric',month:'numeric',day:'numeric',numberingSystem:'latn'
  });
  function validDay(day){if(!Number.isInteger(day)||day<0||day>MAX_DAYS)throw Error('سررسید باید از تاریخ مبنا تا ۳۶۵۰ روز بعد باشد.');}
  function rate(monthly){if(!Number.isFinite(monthly)||monthly<0||monthly>100)throw Error('سود ماهانه باید بین صفر و صد باشد.');}
  const utcDay = date => Math.floor(date.getTime()/86400000);
  function persianParts(date) {
    const p=persian.formatToParts(date);
    const get=type=>Number(p.find(x=>x.type===type)?.value);
    return [get('year'),get('month'),get('day')];
  }
  function pad(v){return String(v).padStart(2,'0');}
  function toJalali(date) {
    const [y,m,d]=persianParts(date);
    return y+'/'+pad(m)+'/'+pad(d);
  }
  function toGregorianDay(value) {
    const normalized=String(value??'').replace(/[۰-۹]/g,v=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(v))
      .replace(/[٠-٩]/g,v=>'٠١٢٣٤٥٦٧٨٩'.indexOf(v)).replace(/-/g,'/').trim();
    const match=/^(1[2-5]\d{2})\/(\d{1,2})\/(\d{1,2})$/.exec(normalized);
    if(!match)throw Error('تاریخ شمسی را مثل ۱۴۰۵/۰۹/۱۴ وارد کنید.');
    const target=match.slice(1).map(Number);
    if(target[1]<1||target[1]>12||target[2]<1||target[2]>31||(target[1]>6&&target[2]>30))
      throw Error('روز یا ماه تاریخ شمسی معتبر نیست.');
    const cmp=a=>a[0]===target[0]?(a[1]===target[1]?a[2]-target[2]:a[1]-target[1]):a[0]-target[0];
    let lo=Math.floor(Date.UTC(target[0]+620,0,1)/86400000);
    let hi=Math.floor(Date.UTC(target[0]+623,11,31)/86400000);
    while(lo<=hi){
      const mid=Math.floor((lo+hi)/2);
      const n=persianParts(new Date(mid*86400000));
      const diff=cmp(n);
      if(diff===0)return mid;
      if(diff<0)lo=mid+1;else hi=mid-1;
    }
    throw Error('این روز در تقویم شمسی وجود ندارد.');
  }
  function dayDifference(base,target) {
    const days=toGregorianDay(target)-toGregorianDay(base);
    validDay(days);
    return days;
  }
  function jalaliAfterDays(base,days) {
    validDay(days);
    return toJalali(new Date((toGregorianDay(base)+days)*86400000));
  }
  function todayJalali() {
    const now=new Date();return toJalali(new Date(Date.UTC(now.getFullYear(),now.getMonth(),now.getDate())));
  }

  const api={dayDifference,jalaliAfterDays,todayJalali,toGregorianDay,rate};
  if(typeof module!=='undefined'&&module.exports)module.exports=api;
  root.FinanceDate=api;
})(typeof window!=='undefined'?window:globalThis);
