(() => {
    const root = document.getElementById('collaborative-editor');
    if (!root) return;
    const table = document.getElementById('live-items'), message = document.getElementById('live-message');
    let documentState = null, queue = Promise.resolve(), busy = false, searchVersion = 0;
    const text = value => { message.textContent = value; };
    const element = (tag, value, cls) => { const node = document.createElement(tag); if (value !== undefined) node.textContent = value; if (cls) node.className = cls; return node; };
    async function request(url, body) {
        const response = await fetch(url, {method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
            headers: {'Accept':'application/json', 'Content-Type':'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || ''},
            ...(body ? {body:JSON.stringify(body)} : {})});
        const result = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join('\n') || result.message || `ذخیره انجام نشد (${response.status}). اطلاعات فرم حفظ شد.`);
        return result;
    }
    function converted(result) {
        if (!result.converted) return false;
        text('این پیش‌فاکتور به فاکتور تبدیل شده است. تغییرات ذخیره‌نشده در این صفحه حفظ شده‌اند.');
        if (!message.querySelector('a')) { const link=element('a',' باز کردن اصلاح فاکتور');link.href=result.url;message.append(link); }
        return true;
    }
    function render(doc) {
        documentState = doc;
        const ids = new Set(doc.items.map(item => String(item.id)));
        for (const tr of [...table.children]) {
            if (!ids.has(tr.dataset.id)) {
                if (tr.querySelector('[data-dirty="1"]')) {tr.dataset.removed='1';tr.title='این ردیف در تب دیگری حذف شده است.';}
                else tr.remove();
            }
        }
        for (const item of doc.items) {
            let tr = [...table.children].find(row => row.dataset.id === String(item.id));
            if (!tr) {
                tr=element('tr');tr.dataset.id=item.id;tr.append(element('td',item.name));
                for (const field of ['quantity','price']) {
                    const td=element('td'), input=element('input');input.type='number';input.min='1';input.max=field==='quantity'?'1000000':'1000000000000';input.className='form-control';input.dataset.field=field;
                    input.addEventListener('input',()=>{input.dataset.dirty='1';});
                    input.addEventListener('change',()=>{
                        const value=Number(input.value), expected=Number(input.dataset.base);
                        if (!Number.isSafeInteger(value)||value<1) {text('تعداد و قیمت باید عدد مثبت باشند.');return;}
                        enqueue({action:'set',item_id:item.id,field,value,expected},input);
                    });td.append(input);tr.append(td);
                }
                const td=element('td'), button=element('button','حذف','btn btn-outline-danger btn-sm');button.type='button';
                button.addEventListener('click',()=>{
                    const current=documentState.items.find(row=>row.id===item.id);
                    if (!current) {tr.remove();return;}
                    if(confirm('این قلم از سند حذف شود؟')) enqueue({action:'remove',item_id:item.id,expected_quantity:current.quantity,expected_price:current.price});
                });td.append(button);tr.append(td);table.append(tr);
            }
            tr.firstChild.textContent=item.name;
            for (const field of ['quantity','price']) {
                const input=tr.querySelector(`[data-field="${field}"]`);
                if(input.dataset.dirty!=='1') {input.value=item[field];input.dataset.base=item[field];}
                else if(Number(input.value)===item[field]) {input.dataset.dirty='0';input.dataset.base=item[field];}
            }
        }
        document.getElementById('live-total').textContent='جمع سند: '+Number(doc.total).toLocaleString('fa-IR')+' ریال';
    }
    function enqueue(action,input) {
        const reason=document.getElementById('live-reason').value.trim();
        if(reason.length<3) {text('برای ذخیره تغییر، دلیل ویرایش را وارد کنید و سپس دوباره مقدار را تغییر دهید.');return;}
        const task=async()=>{
            busy=true;
            try {
                let result=await request(root.dataset.change,{...action,reason});
                if(converted(result)) return;
                if(result.conflict) {
                    // Preserve the user's value, and retry only with explicit consent and a new server baseline.
                    if(action.action==='set') {
                        const latest=result.document.items.find(row=>row.id===action.item_id);
                        if(latest && confirm(`${result.conflict}\nمقدار سرور: ${latest[action.field]}\nمقدار شما: ${action.value}\nمقدار شما اعمال شود؟`)) {
                            result=await request(root.dataset.change,{...action,expected:latest[action.field],reason});
                            if(converted(result)) return;
                        } else if(latest && input) {input.value=latest[action.field];input.dataset.dirty='0';input.dataset.base=latest[action.field];}
                    }
                    if(result.conflict) {render(result.document);text(result.conflict);return;}
                }
                if(input && Number(input.value)===action.value) input.dataset.dirty='0';
                render(result.document);text('تغییر ذخیره شد.');
            } catch(error) {text(error.message);} finally {busy=false;}
        };
        queue=queue.then(task,task);
    }
    async function refresh() {
        if(busy) return;
        try {const result=await request(root.dataset.state);if(!converted(result)) render(result.document);} catch(error) {text(error.message);}
    }
    const search=document.getElementById('live-search');let timer;
    search.addEventListener('input',()=>{
        clearTimeout(timer);const version=++searchVersion;
        timer=setTimeout(async()=>{
            try {
                const result=await request(root.dataset.search+'?q='+encodeURIComponent(search.value));if(version!==searchVersion)return;
                const list=document.getElementById('live-results');list.replaceChildren();
                for(const item of result.items) {
                    const button=element('button',`${item.name} — موجودی ${item.stock} — قیمت ${item.price.toLocaleString('fa-IR')} ریال`,'list-group-item list-group-item-action');button.type='button';
                    button.addEventListener('click',()=>enqueue({action:'add',variant_id:item.id}));list.append(button);
                }
            } catch(error) {text(error.message);}
        },350);
    });
    refresh().then(()=>text('سند دریافت شد؛ تغییرات تب‌های دیگر خودکار دریافت می‌شوند.'));
    setInterval(()=>{if(!document.hidden) queue=queue.then(refresh,refresh);},3000);
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)queue=queue.then(refresh,refresh);});
})();
