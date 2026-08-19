(()=>{
    const drawer=document.getElementById('wiqDrawer');
    if(!drawer)return;
    const content=document.getElementById('wiqDrawerContent');
    let controller=null;
    const close=()=>{drawer.classList.remove('open','wiq-drawer-content-ready');drawer.setAttribute('aria-hidden','true');content.innerHTML='';controller?.abort();};
    const bind=()=>{
        content.querySelectorAll('[data-wiq-close]').forEach(btn=>btn.addEventListener('click',close));
        const all=drawer.querySelector('[data-wiq-fill-all]');
        all?.addEventListener('click',()=>{drawer.querySelectorAll('.wiq-qty').forEach(input=>{input.value=input.dataset.expected;updateQty(input);});syncReviewNoteRequirement();});
        drawer.querySelectorAll('.wiq-qty').forEach(input=>{updateQty(input);input.addEventListener('input',()=>{updateQty(input);syncReviewNoteRequirement();});});
        drawer.querySelectorAll('select[name*="received_warehouse_id"]').forEach(select=>select.addEventListener('change',syncReviewNoteRequirement));
        drawer.querySelectorAll('input[name*="[note]"]').forEach(input=>input.addEventListener('input',syncReviewNoteRequirement));
        syncReviewNoteRequirement();
        const form=drawer.querySelector('[data-wiq-form]');
        form?.addEventListener('submit',()=>{form.classList.add('is-submitting');const btn=form.querySelector('[data-wiq-submit]');if(btn){btn.disabled=true;btn.textContent='در حال ثبت...';}});
    };
    const syncReviewNoteRequirement=()=>{
        const note=content.querySelector('[data-wiq-review-note]');
        if(!note)return;
        const qtyMismatch=[...content.querySelectorAll('.wiq-qty')].some(input=>Number(input.value||0)!==Number(input.dataset.expected||0));
        const warehouseMismatch=[...content.querySelectorAll('[data-wiq-item] select[name*="received_warehouse_id"]')].some(select=>{
            const row=select.closest('[data-wiq-item]');
            const suggested=row?.dataset.suggestedWarehouseId;
            return suggested && String(select.value)!==String(suggested);
        });
        const unexplained=[...content.querySelectorAll('[data-wiq-item]')].some(row=>{
            const qty=row.querySelector('.wiq-qty');
            const destination=row.querySelector('select[name*="received_warehouse_id"]');
            const itemNote=row.querySelector('input[name*="[note]"]');
            const mismatch=(qty&&Number(qty.value||0)!==Number(qty.dataset.expected||0))||(destination&&row.dataset.suggestedWarehouseId&&String(destination.value)!==String(row.dataset.suggestedWarehouseId));
            return mismatch&&!String(itemNote?.value||'').trim();
        });
        note.required=unexplained;
        content.querySelector('[data-wiq-warning]')?.classList.toggle('d-none',!qtyMismatch);
    };
    const updateQty=input=>{
        const expected=Number(input.dataset.expected||0);let value=Number(input.value||0);
        if(value<0){value=0;input.value='0'}
        const diff=input.parentElement.querySelector('.wiq-qty-diff');
        const difference=value-expected;
        input.classList.toggle('is-diff',difference!==0);
        if(diff)diff.textContent=difference===0?'بدون مغایرت':difference<0?`${Math.abs(difference).toLocaleString('fa-IR')} عدد کسری`:`${difference.toLocaleString('fa-IR')} عدد اضافه`;
        const badge=input.closest('[data-wiq-item]')?.querySelector('[data-wiq-difference]');
        if(badge){
            badge.textContent=difference===0?'بدون مغایرت':difference<0?`کسری ${Math.abs(difference).toLocaleString('fa-IR')}`:`اضافه ${difference.toLocaleString('fa-IR')}`;
            badge.classList.remove('wiq-badge-discrepancy','wiq-badge-pending','wiq-badge-received');
            badge.classList.add(difference<0?'wiq-badge-discrepancy':difference>0?'wiq-badge-pending':'wiq-badge-received');
        }
    };
    document.addEventListener('click',async e=>{
        const opener=e.target.closest('[data-wiq-open]');
        if(!opener)return;
        e.preventDefault();controller?.abort();controller=new AbortController();
        drawer.classList.add('open');drawer.classList.remove('wiq-drawer-content-ready');drawer.setAttribute('aria-hidden','false');content.innerHTML='';
        try{
            const response=await fetch(opener.dataset.wiqOpen,{headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},signal:controller.signal});
            if(!response.ok)throw new Error('load');
            const data=await response.json();content.innerHTML=data.html||'';drawer.classList.add('wiq-drawer-content-ready');bind();
        }catch(error){if(error.name==='AbortError')return;content.innerHTML='<div class="p-4 text-danger">دریافت اطلاعات رسید با خطا مواجه شد. صفحه را بروزرسانی و دوباره تلاش کنید.</div>';drawer.classList.add('wiq-drawer-content-ready');}
    });
    drawer.querySelector('.wiq-backdrop')?.addEventListener('click',close);
    document.addEventListener('keydown',e=>{if(e.key==='Escape'&&drawer.classList.contains('open'))close();});
})();
