(() => {
    'use strict';
    const root = document.querySelector('[data-ce-list],[data-ce-profile]');
    if (!root) return;
    const error = root.querySelector('[data-ce-error]');
    const form = root.querySelector('[data-ce-filters]');
    const dialog = root.querySelector('[data-ce-document]');
    let timer, listController, listSequence = 0, documentController;
    async function html(url, signal) {
        const response = await fetch(url, {credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest',Accept:'text/html'}, signal});
        if (response.redirected) throw new Error('نشست تغییر کرده است؛ صفحه را تازه‌سازی کنید.');
        if (!response.ok) throw new Error(response.status===404 ? 'این پرونده یا سند در محدودهٔ دسترسی شما نیست.' : 'دریافت اطلاعات انجام نشد. دوباره تلاش کنید.');
        return response.text();
    }
    async function refresh(url, push = true) {
        listController?.abort(); const controller = new AbortController(); listController = controller;
        const sequence = ++listSequence;
        const selector = form ? '#customerWorkspaceList' : '#customerProfilePanel';
        const target = root.querySelector(selector); target.setAttribute('aria-busy','true'); error.textContent='';
        try {
            const source = await html(url, controller.signal);
            if (sequence !== listSequence) return;
            const parsed = new DOMParser().parseFromString(source,'text/html').querySelector(selector);
            if (!parsed) throw new Error('پاسخ معتبر نیست؛ صفحه را تازه‌سازی کنید.');
            target.replaceWith(parsed);
            if (push) history.pushState({},'',url);
            if (!form) {
                const tab = parsed.dataset.tab;
                root.querySelectorAll('.profile-tabs [data-ce-tab]').forEach(a => {
                    const active = new URL(a.href).searchParams.get('tab')===tab;
                    a.classList.toggle('active',active);
                    if (active) a.setAttribute('aria-current','page'); else a.removeAttribute('aria-current');
                });
            }
        } catch (e) { if (e.name!=='AbortError' && sequence===listSequence) error.textContent=e.message; }
        finally { if (sequence===listSequence) root.querySelector(selector)?.removeAttribute('aria-busy'); }
    }
    function filters() {
        clearTimeout(timer);
        const params = new URLSearchParams(new FormData(form));
        refresh(`${form.action}?${params}`);
    }
    function syncForm() {
        if (!form) return;
        const params = new URL(location.href).searchParams;
        for (const name of ['q','status','balance','seller_id','sort']) {
            const field = form.elements.namedItem(name);
            if (field) field.value = params.get(name) || ({status:'active',balance:'all',sort:'recent'}[name] || '');
        }
        root.querySelectorAll('[data-balance]').forEach(button => {
            const active=button.dataset.balance===form.elements.balance.value;
            button.classList.toggle('active',active); button.setAttribute('aria-pressed',String(active));
        });
    }
    form?.addEventListener('submit',e=>{e.preventDefault();filters();});
    form?.addEventListener('reset',e=>{
        e.preventDefault(); clearTimeout(timer);
        for (const [name,value] of Object.entries({q:'',status:'active',balance:'all',seller_id:'',sort:'recent'})) if (form.elements.namedItem(name)) form.elements.namedItem(name).value=value;
        root.querySelectorAll('[data-balance]').forEach(b=>{b.classList.toggle('active',b.dataset.balance==='all');b.setAttribute('aria-pressed',String(b.dataset.balance==='all'));}); filters();
    });
    form?.addEventListener('input',e=>{if(e.target.name==='q'){clearTimeout(timer);timer=setTimeout(filters,300);}});
    form?.addEventListener('change',e=>{if(e.target.tagName==='SELECT')filters();});
    async function documentView(url) {
        documentController?.abort(); const controller = new AbortController(); documentController=controller;
        const body=dialog.querySelector('[data-ce-document-body]'); body.textContent='در حال دریافت سند…';
        if (!dialog.open) dialog.showModal();
        try {const source=await html(url,controller.signal); if(!controller.signal.aborted)body.innerHTML=source;}
        catch(e){if(e.name!=='AbortError')body.textContent=e.message;}
    }
    root.addEventListener('click',e=>{
        const button=e.target.closest('[data-balance]');
        if(button){form.elements.balance.value=button.dataset.balance;root.querySelectorAll('[data-balance]').forEach(b=>{b.classList.toggle('active',b===button);b.setAttribute('aria-pressed',String(b===button));});filters();}
        const page=e.target.closest('[data-ce-page],[data-ce-tab]');
        if(page&&!e.ctrlKey&&!e.metaKey&&!e.shiftKey){e.preventDefault();clearTimeout(timer);refresh(page.href);}
        const doc=e.target.closest('[data-ce-doc]');
        if(doc&&dialog&&!e.ctrlKey&&!e.metaKey&&!e.shiftKey){e.preventDefault();documentView(doc.href);}
        if(e.target.closest('[data-ce-close]'))dialog.close();
    });
    dialog?.addEventListener('close',()=>documentController?.abort());
    dialog?.addEventListener('click',e=>{if(e.target===dialog){const r=dialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)dialog.close();}});
    window.addEventListener('popstate',()=>{clearTimeout(timer);syncForm();refresh(location.href,false);});
    document.addEventListener('customer-followup-saved',()=>{if(root.matches('[data-ce-profile]'))refresh(location.href,false);});
})();
