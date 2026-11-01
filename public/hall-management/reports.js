// Read-only summaries of the same authorized Hall snapshot. No extra private API.
export function buildReport(snapshot, kind) {
    const titles = {sales:'فروش و فروشندگان',finance:'مالی و وصول',reserved:'رزروهای فعال',warehouse:'عملکرد انبار',shipping:'ارسال بار',attention:'اسناد نیازمند توجه'};
    if (!titles[kind]) throw new Error('Unknown report');
    const all = snapshot.documents;
    let documents = all.filter(d => kind === 'sales' || kind === 'attention' || d.stage === kind);
    const late = d => d.stage_seconds != null && d.stage_seconds >= (snapshot.stages.find(s=>s.key===d.stage)?.warning_minutes ?? 45)*60;
    if (kind === 'attention') documents = documents.filter(late);
    documents = [...documents].sort((a,b)=>(b.stage_seconds ?? -1)-(a.stage_seconds ?? -1));
    const timed = documents.filter(d=>d.stage_seconds != null);
    const average = timed.length ? Math.round(timed.reduce((sum,d)=>sum+d.stage_seconds,0)/timed.length) : null;
    const metric = (label,value,unit) => ({label,value,unit});
    let metrics = [metric('اسناد در جریان',documents.length,'سند'),metric('میانگین انتظارِ زمان‌های معلوم',average,'duration'),metric('بالاتر از زمان تعیین‌شده',documents.filter(late).length,'سند')];
    if (kind === 'sales') metrics = [metric('فروش قطعی امروز',snapshot.metrics.sales_rial,'ریال'),metric('فاکتورهای امروز',snapshot.metrics.sales_count,'فاکتور'),metric('اسناد در جریان',all.length,'سند')];
    if (kind === 'finance') metrics = [metric('وصول نقد و واریز امروز',snapshot.metrics.cash_rial,'ریال'),metric('چک دریافت‌شده امروز',snapshot.metrics.cheque_rial,'ریال'),metric('تعداد چک دریافتی امروز',snapshot.metrics.cheque_count,'فقره')];
    const groups = [];
    if (kind === 'sales') {
        const sellers = new Map();
        for (const d of all) {
            const name = d.seller || 'فروشنده ثبت نشده';
            const item = sellers.get(name) ?? {name,count:0,amount:0};
            item.count++; item.amount += d.amount_rial; sellers.set(name,item);
        }
        groups.push(...[...sellers.values()].sort((a,b)=>b.count-a.count || a.name.localeCompare(b.name,'fa')));
    }
    return {kind,title:titles[kind],metrics,documents,groups,scope:kind==='sales'?'اسناد در جریان فروشندگان؛ شامل فروش‌های تمام‌شده نیست':'اسناد حاضر در صف؛ زمان‌های نامعلوم در میانگین محاسبه نشده‌اند'};
}
