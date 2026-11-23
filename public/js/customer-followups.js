(() => {
    'use strict';
    let sequence=0,controller;
    async function refresh(url,push=false){
        const target=document.querySelector('#followupWorkspacePanel');if(!target)return;
        controller?.abort();controller=new AbortController();const current=++sequence;target.setAttribute('aria-busy','true');
        try{
            const response=await fetch(url,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest',Accept:'text/html'},signal:controller.signal});
            if(!response.ok||response.redirected)throw new Error('دریافت فهرست انجام نشد؛ صفحه را تازه کنید.');
            const next=new DOMParser().parseFromString(await response.text(),'text/html').querySelector('#followupWorkspacePanel');
            if(!next)throw new Error('پاسخ فهرست معتبر نیست.');
            if(current!==sequence)return;target.replaceWith(next);if(push)history.pushState({},'',url);
        }catch(error){if(error.name!=='AbortError'&&current===sequence)target.querySelector('[data-cf-list-message]').textContent=error.message;}
        finally{if(current===sequence)document.querySelector('#followupWorkspacePanel')?.removeAttribute('aria-busy');}
    }
    document.addEventListener('submit',async event=>{
        const form=event.target.closest('[data-cf-form]');if(!form)return;event.preventDefault();if(form.dataset.busy==='true')return;
        const payload=Object.fromEntries(new FormData(form));if(event.submitter?.name)payload[event.submitter.name]=event.submitter.value;
        const status=form.querySelector('[data-cf-message]');const buttons=[...form.querySelectorAll('button')];
        status.textContent='در حال ثبت…';form.dataset.busy='true';buttons.forEach(b=>b.disabled=true);
        try{
            const response=await fetch(form.action,{method:payload._method||'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify(payload)});
            const result=await response.json().catch(()=>({}));
            if(!response.ok||response.redirected)throw new Error(Object.values(result.errors||{}).flat().join('\n')||result.message||'ثبت انجام نشد؛ دوباره تلاش کنید.');
            status.textContent=result.message;
            document.dispatchEvent(new CustomEvent('customer-followup-saved'));
            if(document.querySelector('#followupWorkspacePanel'))await refresh(location.href);
        }catch(error){status.textContent=error.message;}
        finally{form.dataset.busy='false';buttons.forEach(b=>b.disabled=false);}
    });
    document.addEventListener('click',event=>{const link=event.target.closest('[data-cf-page]');if(!link||event.ctrlKey||event.metaKey||event.shiftKey)return;event.preventDefault();refresh(link.href,true);});
    window.addEventListener('popstate',()=>{if(document.querySelector('#followupWorkspacePanel'))refresh(location.href);});
})();
