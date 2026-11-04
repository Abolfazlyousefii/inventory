document.addEventListener('DOMContentLoaded',() => {
    'use strict';
    const form = document.getElementById('categoryFinalizeForm');
    if (!form) return;
    const button = document.getElementById('categoryFinalizeButton');
    const status = document.getElementById('categoryFinalizeStatus');
    const key = 'inventory.stocktake.category.finalize:' + form.dataset.userId + ':' + form.dataset.documentId;
    let pending = null, busy = false, navigating = false;
    const notice = text => {status.textContent = text;};
    try {pending = JSON.parse(sessionStorage.getItem(key) || 'null');} catch (_) {}
    function controls() {button.disabled = busy || navigating; button.textContent = pending ? 'بازیابی نتیجهٔ ثبت نهایی' : 'تأیید شمارش و اعمال کل سند';}
    function finish(result) {
        if (Number(result.document_id) !== Number(form.dataset.documentId) || result.document?.status !== 'finalized') throw new Error('پاسخ معتبر نیست؛ وضعیت سند را در آرشیو بررسی کنید.');
        const target = new URL(result.view_url,location.origin);
        if (target.origin !== location.origin) throw new Error('آدرس نتیجه معتبر نیست.');
        sessionStorage.removeItem(key); pending = null;
        notice('سند یکجا نهایی شد؛ در حال نمایش نتیجه...');
        navigating = true;
        location.assign(target.href);
    }
    async function recover() {
        if (!pending) return;
        const response = await fetch('/stock-count-documents/requests/'+encodeURIComponent(pending.request_id)+'?operation=unified_finalize', {credentials:'same-origin',headers:{Accept:'application/json'}});
        if (response.status === 404) {notice('نتیجهٔ ثبت قبلی هنوز مشخص نیست؛ برای ادامه فقط دکمهٔ بازیابی نتیجه را بزنید.');return;}
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'بازیابی نتیجه ممکن نشد.');
        finish(result);
    }
    button.addEventListener('click',async () => {
        if (busy || navigating) return;
        if (!pending && !confirm('کل '+form.dataset.totalCount+' ردیف این سند، شامل '+form.dataset.zeroCount+' ردیف با موجودی صفر، اعمال شود؟ پس از تأیید، موجودی مرکزی تغییر می‌کند.')) return;
        busy = true;
        try {
            if (!pending) pending = {request_id:crypto.randomUUID(),expected_review_token:form.dataset.reviewToken};
            sessionStorage.setItem(key,JSON.stringify(pending));
            controls(); notice('در حال اعمال یکجای سند؛ صفحه را نبندید...');
            const response = await fetch(form.dataset.finalizeUrl,{method:'POST',credentials:'same-origin',
                headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':form.querySelector('[name=_token]')?.value || ''},body:JSON.stringify(pending)});
            const result = await response.json();
            if (!response.ok) {
                if (response.status >= 400 && response.status < 500) {pending = null;sessionStorage.removeItem(key);}
                throw new Error([result.message,result.errors && Object.values(result.errors).flat().join('، '),...(result.conflicts || []).map(item=>item.reason)].filter(Boolean).join(' · ') || 'ثبت نهایی انجام نشد.');
            }
            finish(result);
        } catch(error) {notice((error.message || 'پاسخ دریافت نشد.')+(pending ? ' نتیجه هنوز مشخص نیست؛ برای جلوگیری از اعمال دوباره، فقط بازیابی نتیجه را بزنید.' : ''));}
        finally {busy = false;controls();}
    });
    window.addEventListener('beforeunload',event => {if (!navigating && (busy || pending)) {event.preventDefault();event.returnValue='';}});
    controls();
    if (pending) {busy = true;controls();recover().catch(error=>notice(error.message)).finally(()=>{busy=false;controls();});}
});
