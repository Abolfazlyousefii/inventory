document.addEventListener('DOMContentLoaded', () => {
    'use strict';
    const app = document.getElementById('categoryCountApp');
    if (!app) return;
    let seed;
    try { seed = JSON.parse(document.getElementById('categoryDocumentSeed').textContent); }
    catch (_) { return; }
    const ids = ['Category','Subcategory','Show','Search','Rows','More','LoadAll','Save','Status','Summary','Recovery','Restore','Discard'];
    const ui = Object.fromEntries(ids.map(id => [id,document.getElementById('cd'+id)]));
    const counts = new Map();
    const invalid = new Map();
    const rows = new Map();
    let documentId = Number(seed.id) || null;
    let reviewToken = seed.review_token || null;
    let categoryId = Number(seed.category_id) || 0;
    let afterId = 0, hasMore = false, loading = false, saving = false, loadingAll = false;
    let dirty = false, query = '', timer = null, controller = null, generation = 0, pending = null, navigating = false;
    const storageKey = 'inventory.stocktake.category-document.v1:' + seed.user_id + ':' + (documentId || 'new');
    const fa = value => Number(value || 0).toLocaleString('fa-IR');
    const make = (tag,className,text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = String(text);
        return node;
    };
    const normalize = value => String(value ?? '').replace(/[۰-۹]/g,d=>String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
        .replace(/[٠-٩]/g,d=>String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))).trim() || '0';
    const validCount = value => /^\d+$/.test(value) && Number.isSafeInteger(Number(value)) && Number(value) <= 2147483647;
    for (const item of seed.items || []) {
        const id = Number(item.variant_id), quantity = normalize(item.actual_quantity);
        if (Number.isSafeInteger(id) && id > 0 && validCount(quantity)) counts.set(id,quantity);
    }
    function notice(message,error=false) {
        ui.Status.textContent = message;
        ui.Status.classList.toggle('is-error',error);
    }
    function readRecovery() {
        try {
            const snapshot = JSON.parse(globalThis.sessionStorage.getItem(storageKey) || 'null');
            return snapshot && snapshot.version === 1 && snapshot.user_id === seed.user_id &&
                Number(snapshot.document_id || 0) === Number(seed.id || 0) &&
                Array.isArray(snapshot.counts) && snapshot.counts.length <= 20000 ? snapshot : null;
        } catch (_) { return null; }
    }
    let recovery = readRecovery();
    function persist() {
        if (!categoryId) return true;
        if (recovery) return true;
        try {
            globalThis.sessionStorage.setItem(storageKey,JSON.stringify({version:1,user_id:seed.user_id,
                document_id:documentId,category_id:categoryId,review_token:reviewToken,
                counts:[...counts],invalid:[...invalid],rows:[...rows.values()],pending,
            }));
            return true;
        } catch (_) { return false; }
    }
    function checkpoint() {
        if (!persist()) notice('نسخه بازیابی مرورگر حفظ نشد؛ فضای ذخیره را بررسی کنید و فعلاً صفحه را نبندید.',true);
    }
    function showCategoryOption(id,name) {
        if (![...(ui.Category.options || [])].some(option=>Number(option.value)===Number(id))) {
            const option=make('option','',name || 'دسته بازیابی‌شده');option.value=String(id);ui.Category.append(option);
        }
        ui.Category.value=String(id);
    }
    function controls() {
        const uncertain = Boolean(pending);
        const locked = saving || uncertain || navigating || Boolean(recovery);
        ui.Category.disabled = Boolean(documentId) || locked || loadingAll;
        ui.Subcategory.disabled = Boolean(documentId) || locked || loadingAll || !ui.Category.value;
        ui.Show.disabled = Boolean(documentId) || locked || loading || loadingAll;
        ui.Search.disabled = locked || loadingAll || !categoryId;
        ui.More.hidden = !hasMore;
        ui.More.disabled = loading || locked || loadingAll;
        ui.LoadAll.hidden = !hasMore && !loadingAll;
        ui.LoadAll.disabled = locked;
        ui.LoadAll.textContent = loadingAll ? 'توقف دریافت' : 'نمایش همه کالاها';
        ui.Save.disabled = saving || navigating || Boolean(recovery) || (!pending && (!categoryId || loading || loadingAll || invalid.size > 0));
        ui.Save.textContent = pending ? 'بازیابی نتیجه ذخیره' : 'ذخیره یک سند و بررسی شمارش';
        for (const input of ui.Rows.querySelectorAll('[data-count]')) input.disabled = locked;
        const positive = [...counts.values()].filter(value=>Number(value)>0).length;
        ui.Summary.textContent = fa(rows.size)+' ردیف دریافت‌شده · '+fa(positive)+' ردیف با موجودی مثبت' +
            (hasMore ? (documentId ? ' · شمارش ذخیره‌شدهٔ باقی ردیف‌ها حفظ می‌شود.' : ' · باقی کالاهای همین دسته نیز در سند با صفر ثبت می‌شوند.') : '');
    }
    function renderRow(row) {
        const id = Number(row.variant_id);
        if (rows.has(id)) return;
        rows.set(id,row);
        if (!counts.has(id)) counts.set(id,'0');
        const tr = make('tr');
        const name = make('td','cd-name');
        name.dataset.label='کالا و تنوع';
        name.append(make('strong','',row.product_name),make('span','',row.name || ''),make('small','',row.sku || ''));
        tr.append(name);
        const figures = [['موجودی انبار مرکزی',row.system_available],['رزرو فعال',row.active_reserved],['صف جمع‌آوری',row.queue_total]];
        for (const [label,value] of figures) {
            const cell=make('td','cd-number',fa(value));cell.dataset.label=label;tr.append(cell);
        }
        const actual=make('td','cd-actual');actual.dataset.label='شمارش واقعی';
        const input=make('input','form-control');input.type='text';input.inputMode='numeric';input.autocomplete='off';
        input.maxLength=12;input.dataset.count=String(id);input.value=invalid.get(id) ?? counts.get(id);
        input.setAttribute('aria-label','شمارش '+row.product_name+' '+(row.name||''));
        if (invalid.has(id)) input.setCustomValidity('عدد صحیح نامنفی وارد کنید.');
        actual.append(input);tr.append(actual);
        const diff=make('td','cd-diff');diff.dataset.label='اختلاف';diff.dataset.diff=String(id);
        const difference=Number(counts.get(id))-Number(row.expected_physical || 0);
        diff.textContent=(difference>0?'+':'')+fa(difference);tr.append(diff);
        ui.Rows.append(tr);
    }
    function resetListing() {
        generation++;controller?.abort();controller=null;loading=false;afterId=0;hasMore=true;
        rows.clear();ui.Rows.replaceChildren();
    }
    async function page() {
        if (loading || !hasMore || !categoryId || saving || pending) return false;
        loading=true;const current=generation;controller=new AbortController();controls();
        try {
            const url=new URL(seed.rows_url,location.origin);
            url.searchParams.set('category_id',String(categoryId));url.searchParams.set('after_id',String(afterId));
            url.searchParams.set('limit','60');if(query)url.searchParams.set('q',query);
            const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});
            const result=await response.json();
            if(!response.ok)throw new Error(result.message || 'دریافت کالاها ناموفق بود.');
            const next=Number(result.next_cursor);
            if(!Array.isArray(result.rows) || (result.has_more && (!result.rows.length || !Number.isSafeInteger(next) || next<=afterId)))throw new Error('صفحه‌بندی کالاها معتبر نیست.');
            if(current!==generation)return false;
            result.rows.forEach(renderRow);afterId=Number.isFinite(next)?next:afterId;hasMore=Boolean(result.has_more);
            notice(documentId ? 'شمارش ذخیره‌شده از سرور دریافت شد؛ فقط ردیف‌های موردنظر را اصلاح و همان سند را ذخیره کنید.' :
                hasMore ? 'بخش اول کالاها دریافت شد. می‌توانید ادامه را ببینید یا همین حالا یک سند برای تمام دسته ذخیره کنید.' :
                'کالاها دریافت شدند؛ مقدار پیش‌فرض صفر است. فقط موجودی‌های واقعی را اصلاح و یک سند ذخیره کنید.');
            checkpoint();return true;
        }catch(error){
            if(current===generation)notice(error.name==='AbortError'?'دریافت متوقف شد؛ شمارش‌های واردشده حفظ شدند.':error.message,true);
            return false;
        }finally{if(current===generation){loading=false;controller=null;controls();}}
    }
    async function start(selected) {
        if(saving || pending || (documentId && categoryId && selected!==categoryId))return;
        if(categoryId && selected!==categoryId && dirty && !window.confirm('شمارش‌های ذخیره‌نشده این دسته کنار گذاشته شوند؟'))return;
        if(selected!==categoryId){counts.clear();invalid.clear();dirty=false;}
        categoryId=selected;query='';ui.Search.value='';loadingAll=false;resetListing();await page();
    }
    function accept(result) {
        const id=Number(result.document_id || result.document?.id);
        const target=new URL(result.view_url || '',location.origin);
        if(!Number.isSafeInteger(id) || id<1 || !result.view_url || target.origin!==location.origin ||
            !['http:','https:'].includes(target.protocol) || !target.pathname.startsWith('/stock-count-documents/')) {
            throw new Error('پاسخ ذخیره معتبر نیست؛ نتیجه درخواست را بازیابی کنید.');
        }
        pending=null;dirty=false;navigating=true;documentId=id;
        try{globalThis.sessionStorage.removeItem(storageKey);}catch(_){}
        notice('یک سند ذخیره شد؛ صفحه بررسی شمارش در حال بازشدن است.');
        const link=make('a','ms-2','بازکردن صفحه بررسی');link.href=target.href;ui.Status.append(link);
        controls();
        location.assign(target.href);
    }
    async function save() {
        if(ui.Save.disabled || saving || navigating)return;
        saving=true;
        try {
            if(!pending){
                const uuid=globalThis.crypto?.randomUUID?.();
                if(!uuid)throw new Error('ایجاد شناسه امن درخواست ممکن نیست؛ از اتصال امن استفاده کنید.');
                pending={request_id:uuid,category_id:categoryId,
                    ...(documentId?{document_id:documentId,expected_review_token:reviewToken}:{}),
                    actual_quantities:Object.fromEntries(counts)};
            }
            controls();
            if(!persist())throw new Error('حفظ نسخه بازیابی ممکن نشد؛ درخواست ارسال نشد.');
            notice('در حال ذخیره یک سند برای کل دسته؛ پس از ذخیره، صفحه بررسی باز می‌شود...');
            const response=await fetch(seed.save_url,{method:'POST',credentials:'same-origin',
                headers:{Accept:'application/json','Content-Type':'application/json',
                    'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]')?.content||''},body:JSON.stringify(pending)});
            const result=await response.json().catch(()=>({}));
            if(!response.ok){
                if(response.status>=400 && response.status<500)pending=null;
                throw new Error([result.message,result.errors&&Object.values(result.errors).flat().join('، ')].filter(Boolean).join(' · ') || 'ذخیره انجام نشد.');
            }
            accept(result);
        }catch(error){
            notice(error.message+(pending?' نتیجه هنوز مشخص نیست؛ برای جلوگیری از سند تکراری فقط «بازیابی نتیجه ذخیره» را بزنید.':''),true);
            checkpoint();
        }finally{saving=false;controls();}
    }
    async function restore() {
        if(!recovery || saving || pending)return;
        try {
            const snapshot=recovery;
            const restored=new Map(snapshot.counts.map(([id,value])=>[Number(id),String(value)]));
            if([...restored].some(([id,value])=>!Number.isSafeInteger(id)||id<1||!validCount(value)))throw new Error('نسخه بازیابی معتبر نیست.');
            if(snapshot.pending && (!/^[0-9a-f-]{36}$/i.test(snapshot.pending.request_id || '') || Number(snapshot.pending.category_id)!==Number(snapshot.category_id)))throw new Error('شناسه درخواست بازیابی معتبر نیست.');
            counts.clear();for(const [id,value] of restored)counts.set(id,value);
            invalid.clear();for(const [id,value] of snapshot.invalid||[])invalid.set(Number(id),String(value));
            categoryId=Number(snapshot.category_id);documentId=Number(snapshot.document_id)||null;
            reviewToken=snapshot.review_token||null;pending=snapshot.pending||null;dirty=true;
            showCategoryOption(categoryId,seed.category_name || 'دسته بازیابی‌شده');ui.Subcategory.value='';ui.Recovery.hidden=true;recovery=null;
            query='';ui.Search.value='';resetListing();
            for(const row of snapshot.rows||[])renderRow(row);
            controls();checkpoint();
            if(pending){
                const url=new URL('/stock-count-documents/requests/'+encodeURIComponent(pending.request_id),location.origin);
                url.searchParams.set('operation','unified_save');
                const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});
                if(response.ok){accept(await response.json());return;}
                notice('نتیجه ذخیره قبلی هنوز در دسترس نیست؛ شمارش قفل است. برای ادامه فقط «بازیابی نتیجه ذخیره» را بزنید.',true);
            }else await page();
        }catch(error){notice(error.message||'بازیابی انجام نشد؛ هیچ موجودی تغییر نکرد.',true);}
        finally{controls();}
    }
    ui.Rows.addEventListener('input',event=>{
        const field=event.target.closest('[data-count]');if(!field || saving || pending || navigating || recovery)return;
        const id=Number(field.dataset.count),value=normalize(field.value);dirty=true;
        if(!validCount(value)){invalid.set(id,field.value);field.setCustomValidity('فقط عدد صحیح نامنفی مجاز است.');}
        else{invalid.delete(id);field.setCustomValidity('');field.value=value;counts.set(id,value);}
        controls();checkpoint();
        // Update only the changed difference; never rebuild the focused input.
        const row=rows.get(id);
        const cells=ui.Rows.children || [];
        for(const tr of cells)for(const cell of tr.children||[]){
            if(cell.dataset?.diff===String(id)){
                const difference=Number(value)-Number(row?.expected_physical||0);
                cell.textContent=validCount(value)?(difference>0?'+':'')+fa(difference):'—';
            }
        }
    });
    ui.Show.addEventListener('click',async()=>{
        const selected=Number(ui.Subcategory.value||ui.Category.value);
        if(!Number.isSafeInteger(selected)||selected<1){notice('ابتدا دسته‌بندی را انتخاب کنید.',true);return;}
        if(recovery && !window.confirm('نسخه قبلی مرورگر کنار گذاشته شود و شمارش جدیدی شروع شود؟ اسناد ذخیره‌شده در آرشیو باقی می‌مانند.'))return;
        recovery=null;ui.Recovery.hidden=true;await start(selected);
    });
    ui.More.addEventListener('click',page);
    ui.LoadAll.addEventListener('click',async()=>{
        if(loadingAll){loadingAll=false;controller?.abort();controls();return;}
        if(saving||pending||loading)return;
        loadingAll=true;controls();
        try{while(loadingAll&&hasMore){if(!await page())break;}}
        finally{loadingAll=false;controls();}
    });
    ui.Search.addEventListener('input',()=>{
        clearTimeout(timer);timer=setTimeout(async()=>{
            if(!categoryId||saving||pending||loadingAll)return;
            const next=ui.Search.value.trim();if(next===query)return;
            query=next;resetListing();await page();
        },320);
    });
    ui.Category.addEventListener('change',async()=>{
        if(documentId||pending||saving)return;
        ui.Subcategory.replaceChildren(make('option','','همه زیردسته‌ها'));
        ui.Subcategory.value='';controls();if(!ui.Category.value)return;
        const selected=ui.Category.value;
        try{
            const url=new URL(app.dataset.subcategoriesUrl,location.origin);url.searchParams.set('category_id',selected);
            const response=await fetch(url,{headers:{Accept:'application/json'},credentials:'same-origin'});
            const result=await response.json();if(!response.ok)throw new Error('دریافت زیردسته‌ها ناموفق بود.');
            if(ui.Category.value!==selected)return;
            for(const item of result){const option=make('option','',item.name);option.value=String(item.id);ui.Subcategory.append(option);}
        }catch(error){notice(error.message,true);}
    });
    ui.Save.addEventListener('click',save);ui.Restore.addEventListener('click',restore);
    ui.Discard.addEventListener('click',()=>{
        if(!window.confirm('فقط نسخه مرورگر کنار گذاشته شود؟ سند ذخیره‌شده روی سرور باقی می‌ماند.'))return;
        try{globalThis.sessionStorage.removeItem(storageKey);}catch(_){}
        recovery=null;ui.Recovery.hidden=true;
        controls();checkpoint();
    });
    ui.Recovery.hidden=!recovery;controls();
    window.addEventListener('beforeunload',event=>{
        if(navigating || (!dirty&&!pending&&!saving))return;event.preventDefault();event.returnValue='';
    });
    if(categoryId){
        showCategoryOption(categoryId,seed.category_name || 'دسته انتخابی');
        hasMore=true;page();
    }
});
