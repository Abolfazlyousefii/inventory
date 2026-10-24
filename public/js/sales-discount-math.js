(function(root){'use strict';
    const input=value=>String(value??'0').trim().replace(/[۰-۹٠-٩]/g,c=>'۰۱۲۳۴۵۶۷۸۹'.includes(c)?'۰۱۲۳۴۵۶۷۸۹'.indexOf(c):'٠١٢٣٤٥٦٧٨٩'.indexOf(c)).replace(/٫/g,'.').replace(/[,٬ ]/g,'');
    const percentValue=value=>{const n=Number(input(value));return Number.isFinite(n)?Math.min(100,Math.max(0,n)):0;};
    function amount(base,type,value){
        const gross=BigInt(Math.max(0,Math.trunc(Number(base)||0)));
        const text=input(value)||'0';
        if(type==='none')return 0;
        if(type==='percent'){
            if(!/^\d+(?:\.\d{0,2})?$/.test(text))return 0;
            const parts=text.split('.');const basis=BigInt(parts[0]||'0')*100n+BigInt((parts[1]||'').padEnd(2,'0'));
            return Number(gross*(basis>10000n?10000n:basis)/10000n);
        }
        if(!/^\d+(?:\.0{0,2})?$/.test(text))return 0;
        const requested=BigInt(text.split('.')[0]);return Number(requested>gross?gross:requested);
    }
    function allocate(weights,requested){
        const big=weights.map(value=>BigInt(Math.max(0,Math.trunc(Number(value)||0))));const gross=big.reduce((a,b)=>a+b,0n);
        let total=BigInt(Math.max(0,Math.trunc(Number(requested)||0)));if(total>gross)total=gross;
        const shares=big.map(()=>0n);if(gross===0n||total===0n)return shares.map(Number);
        let last=-1;big.forEach((w,i)=>{if(w>0n)last=i;});let allocated=0n;
        big.forEach((w,i)=>{if(w===0n)return;let share=i===last?total-allocated:total*w/gross;if(share>w)share=w;shares[i]=share;allocated+=share;});
        let remaining=total-allocated;
        for(let i=big.length-1;i>=0&&remaining>0n;i--){const capacity=big[i]-shares[i];const extra=capacity<remaining?capacity:remaining;shares[i]+=extra;remaining-=extra;}
        return shares.map(Number);
    }
    const api={input,percentValue,amount,allocate};root.SalesDiscountMath=api;if(typeof module!=='undefined')module.exports=api;
})(typeof window==='undefined'?globalThis:window);
