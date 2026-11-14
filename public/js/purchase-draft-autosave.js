(function(root){
    'use strict';
    const prefix=user=>'ariya.purchase.draft.v1:'+user+':';
    const valid=r=>r && typeof r.token==='string' && r.payload?.version===1 && Array.isArray(r.payload.cards);
    function records(storage,user){
        const list=[];
        try{for(let i=0;i<storage.length;i++){const key=storage.key(i);if(key?.startsWith(prefix(user))){try{const r=JSON.parse(storage.getItem(key));if(valid(r))list.push({key,...r});}catch{}}}}catch{}
        return list.sort((a,b)=>b.updatedAt-a.updatedAt);
    }
    function removeToken(storage,user,token){for(const r of records(storage,user))if(r.token===token)storage.removeItem(r.key);}
    async function request(url,options={}){
        const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),10000);
        try{
            const response=await fetch(url,{...options,signal:controller.signal,credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||'',...options.headers}});
            let body;try{body=await response.json();}catch{throw new Error('پاسخ ذخیره‌سازی معتبر نیست. اطلاعات روی این دستگاه نگه داشته می‌شود.');}
            if(!response.ok){const error=new Error(body.message||'ارتباط با سرور برقرار نشد.');error.status=response.status;error.draft=body.draft;throw error;}
            return body;
        }finally{clearTimeout(timer);}
    }
    async function cleanCompleted(config){
        for(const r of records(localStorage,config.userId).filter(r=>r.submitting)){
            try{const {draft}=await request(config.baseUrl+'/'+encodeURIComponent(r.token));if(draft.status==='submitted')removeToken(localStorage,config.userId,r.token);}catch{}
        }
    }
    async function start(config){
        const form=config.form,status=document.getElementById('purchaseDraftStatus'),actions=document.getElementById('purchaseDraftActions');
        const uuid=()=>crypto.randomUUID();
        let tabId;try{tabId=sessionStorage.getItem(prefix(config.userId)+'tab');if(!tabId){tabId=uuid();sessionStorage.setItem(prefix(config.userId)+'tab',tabId);}}catch{tabId=uuid();}
        const key=prefix(config.userId)+tabId;
        let paused=true,blocked=false,inFlight=false,timer,poll,retryTimer,record={token:config.token(),revision:0,payload:null,updatedAt:0},lastSaved='',lastLocal='';
        const message=text=>{status.textContent=text;};
        function button(label,callback){const b=document.createElement('button');b.type='button';b.className='btn btn-sm btn-outline-primary';b.textContent=label;b.addEventListener('click',callback);actions.appendChild(b);return b;}
        function enable(){paused=false;form.inert=false;}
        function persist(){
            if(paused)return false;
            const payload=config.snapshot(),serialized=JSON.stringify(payload);
            if(!record.payload && !config.hasContent(payload))return false;
            if(serialized===lastLocal)return true;
            record={...record,payload,updatedAt:Date.now()};lastLocal=serialized;
            try{localStorage.setItem(key,JSON.stringify(record));message(navigator.onLine===false?'آفلاین؛ اطلاعات روی این دستگاه محفوظ است.':'روی این دستگاه ذخیره شد؛ در انتظار ذخیره در سرور.');return true;}
            catch{lastLocal='';message('ذخیره روی دستگاه ممکن نیست؛ برای حفظ اطلاعات، صفحه را نبندید.');return false;}
        }
        function conflict(draft){
            if(draft?.status==='submitted'){
                paused=true;form.inert=true;removeToken(localStorage,config.userId,record.token);actions.replaceChildren();message('این خرید قبلاً ثبت شده است؛ سند تکراری ایجاد نشد.');
                if(draft.purchase_url){const a=document.createElement('a');a.href=draft.purchase_url;a.textContent='مشاهده خرید ثبت‌شده';a.className='btn btn-sm btn-outline-success';actions.appendChild(a);}return;
            }
            blocked=true;actions.replaceChildren();message('این پیش‌نویس در صفحهٔ دیگری تغییر کرده است؛ نسخهٔ شما روی همین دستگاه محفوظ است.');
            button('ادامهٔ این نسخه به‌عنوان خرید جدا',()=>{record.token=uuid();record.revision=0;record.submitting=false;config.setToken(record.token);blocked=false;lastSaved='';lastLocal='';actions.replaceChildren();persist();schedule();});
        }
        async function sync(){
            if(paused||blocked||inFlight||!record.payload||navigator.onLine===false)return;
            const serialized=JSON.stringify(record.payload);if(serialized===lastSaved)return;
            inFlight=true;const token=record.token;
            try{
                const {draft}=await request(config.saveUrl,{method:'POST',body:JSON.stringify({token,revision:record.revision,draft_json:serialized})});
                if(record.token!==token)return;
                record.revision=draft.revision;lastSaved=serialized;
                let localSaved=true;try{localStorage.setItem(key,JSON.stringify(record));}catch{localSaved=false;lastLocal='';}
                if(JSON.stringify(record.payload)===serialized)message(localSaved?'پیش‌نویس روی دستگاه و سرور ذخیره شد.':'پیش‌نویس روی سرور ذخیره شد؛ ذخیره روی این دستگاه ممکن نیست.');
            }catch(e){
                if(e.status===409)conflict(e.draft);
                else {
                    message('ارتباط با سرور برقرار نشد؛ '+(lastLocal?'نسخهٔ این دستگاه محفوظ است.':'برای حفظ اطلاعات، صفحه را نبندید.'));
                    if(!e.status||e.status>=500||e.status===429){clearTimeout(retryTimer);retryTimer=setTimeout(sync,10000);}
                }
            }finally{inFlight=false;if(!blocked && JSON.stringify(record.payload)!==serialized)schedule();}
        }
        function schedule(){clearTimeout(timer);timer=setTimeout(sync,1200);}
        function changed(){if(paused)return;persist();schedule();}
        async function recoverSubmission(){
            paused=true;form.inert=true;actions.replaceChildren();message('در حال بررسی نتیجهٔ ثبت خرید…');
            try{
                let draft=null;try{draft=(await request(config.baseUrl+'/'+encodeURIComponent(record.token))).draft;}catch(e){if(e.status!==404)throw e;}
                if(draft?.status==='submitted'){conflict(draft);return;}
                if(draft?.status==='discarded'){conflict(draft);form.inert=false;paused=false;return;}
                if(draft)record.revision=draft.revision;
                record.submitting=false;delete form.dataset.submitting;
                form.querySelectorAll?.('button[type="submit"]').forEach(b=>{b.disabled=false;if(b.dataset.originalText)b.textContent=b.dataset.originalText;});
                enable();message('ثبت نهایی تأیید نشده است؛ اطلاعات خرید محفوظ است و می‌توانید ادامه دهید.');
            }catch{message('نتیجهٔ ثبت هنوز مشخص نیست؛ اطلاعات محفوظ است. پس از اتصال، نتیجه را بررسی کنید.');button('بررسی نتیجهٔ ثبت خرید',recoverSubmission);}
        }
        async function restore(candidate,server){
            actions.replaceChildren();message('در حال بازیابی اطلاعات خرید…');
            try{
                await config.restore(candidate.payload);
                record={...candidate};delete record.key;
                record.submitting=false;
                // Never overwrite a newer tab/device's server revision with older local edits.
                if(server && server.revision!==candidate.revision && JSON.stringify(server.payload)!==JSON.stringify(candidate.payload)){
                    record.token=uuid();record.revision=0;record.submitting=false;
                }else if(server)record.revision=server.revision;
                config.setToken(record.token);lastLocal='';lastSaved='';enable();persist();schedule();
            }catch(e){message('بازیابی کامل نشد؛ اصل پیش‌نویس محفوظ است. '+e.message);button('تلاش دوباره برای بازیابی',()=>restore(candidate,server));}
        }
        async function offer(local,server){
            const candidate=local||{token:server.token,revision:server.revision,payload:server.payload,updatedAt:Date.parse(server.updated_at)};
            message('خرید ناتمام دارید؛ ادامه می‌دهید؟');actions.replaceChildren();
            const different=local&&server&&server.revision!==local.revision&&JSON.stringify(local.payload)!==JSON.stringify(server.payload);
            button(different?'ادامهٔ نسخهٔ این دستگاه به‌عنوان خرید جدا':'ادامهٔ خرید ناتمام',()=>restore(candidate,server));
            if(different)button('بازیابی نسخهٔ سرور',()=>restore({token:server.token,revision:server.revision,payload:server.payload},server));
            button('کنار گذاشتن و خرید جدید',async()=>{
                if(!window.confirm('پیش‌نویس انتخاب‌شده کنار گذاشته شود و خرید جدید باز شود؟'))return;
                try{
                    let current=server;
                    if(!current&&local){try{current=(await request(config.baseUrl+'/'+encodeURIComponent(local.token))).draft;}catch(e){if(e.status!==404)throw e;}}
                    if(current)await request(config.baseUrl+'/'+encodeURIComponent(current.token),{method:'DELETE',body:JSON.stringify({revision:current.revision})});
                    if(local)removeToken(localStorage,config.userId,local.token);
                    record={token:uuid(),revision:0,payload:null,updatedAt:0};config.setToken(record.token);actions.replaceChildren();enable();message('خرید جدید؛ ذخیرهٔ خودکار فعال است.');
                }catch(e){message('کنار گذاشتن انجام نشد؛ پیش‌نویس محفوظ است. دوباره تلاش کنید.');}
            });
        }
        form.addEventListener('input',changed);form.addEventListener('change',changed);
        form.addEventListener('submit',event=>{if(paused||blocked)event.preventDefault();},true);
        new MutationObserver(changed).observe(config.cardsElement,{childList:true,subtree:true});
        window.addEventListener('pagehide',persist);
        window.addEventListener('pageshow',event=>{if(event.persisted&&record.submitting)return recoverSubmission();});
        document.addEventListener('visibilitychange',()=>{if(document.hidden){persist();sync();}});
        window.addEventListener('online',()=>{persist();sync();});
        window.addEventListener('offline',()=>{if(persist())message('آفلاین؛ اطلاعات روی این دستگاه محفوظ است.');});
        form.addEventListener('submit',event=>{
            if(event.defaultPrevented)return;
            if(paused||blocked){event.preventDefault();return;}
            persist();record.submitting=true;try{localStorage.setItem(key,JSON.stringify(record));}catch{}
        });
        form.inert=true;
        const saved=records(localStorage,config.userId);
        const local=config.hasInitial?saved.find(r=>r.token===config.token()):(saved.find(r=>r.key===key)||saved[0]);
        let server=null;
        try{
            if(local||config.hasInitial){
                try{server=(await request(config.baseUrl+'/'+encodeURIComponent(local?.token||config.token()))).draft;}catch(e){if(e.status!==404)throw e;}
                if(server?.status==='submitted'){record=local||{token:config.token()};conflict(server);return;}
                if(server?.status==='discarded'){await offer({...local,payload:local?.payload||config.snapshot(),token:uuid(),revision:0},null);return;}
            }
            if(!server&&!local&&!config.hasInitial)server=(await request(config.latestUrl)).draft;
        }catch{message('اتصال به سرور برقرار نیست؛ ذخیرهٔ محلی فعال است.');}
        if(config.hasInitial){
            // Validation redirects already contain the operator's latest values.
            record={token:config.token(),revision:server?.token===config.token()?server.revision:0,payload:null,updatedAt:Date.now()};enable();persist();schedule();
        }else if(local||server?.payload){await offer(local,server);}
        else{enable();message('ذخیرهٔ خودکار فعال است.');}
        poll=setInterval(()=>{if(!paused){const before=lastLocal;persist();if(lastLocal!==before)schedule();}},2000);
        return {persist,sync,stop(){clearTimeout(timer);clearTimeout(retryTimer);clearInterval(poll);},get record(){return record;}};
    }
    const api={records,removeToken,start,cleanCompleted};root.PurchaseDraftAutosave=api;
    if(typeof module!=='undefined')module.exports=api;
})(typeof window==='undefined'?globalThis:window);
