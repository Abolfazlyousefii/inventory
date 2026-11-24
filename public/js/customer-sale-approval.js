(() => {
    'use strict';
    const root = document.getElementById('customerSaleApproval');
    if (!root) return;
    const find = s => root.querySelector(s);
    const context = () => document.getElementById('sale_authorization_context').value;
    const panel = find('[data-sale-code-panel]'), status = find('[data-sale-status]');
    let selected = null, approvalId = null, busy = false, generation = 0;
    const digits = s => String(s).replace(/[۰-۹]/g,c=>String('۰۱۲۳۴۵۶۷۸۹'.indexOf(c))).replace(/[٠-٩]/g,c=>String('٠١٢٣٤٥٦٧٨٩'.indexOf(c)));
    function message(text, error = false) { status.textContent = text; status.dataset.error = String(error); }
    function reset() {
        generation++; selected = null; approvalId = null; busy = false;
        root.hidden = panel.hidden = true; find('#saleApprovalCode').value = ''; message('');
        root.querySelectorAll('button,input').forEach(el=>el.disabled=false);
    }
    document.addEventListener('customer-sale-selection-cleared', reset);
    document.addEventListener('customer-sale-approval-required', e => {
        reset(); selected = e.detail; root.hidden = false;
        find('[data-sale-owner]').textContent = `مسئول مشتری: ${selected.owner_name || 'تعیین نشده'}`;
        find('[data-sale-request]').hidden = !selected.can_request;
        message(selected.can_request ? 'برای انتخاب این مشتری، کد تأیید مسئول او لازم است؛ فقط برای همین سند.' : 'این مشتری مسئول فعال ندارد؛ برای تخصیص آن با مدیریت هماهنگ کنید.');
    });
    async function post(url, body) {
        const response = await fetch(url, {method:'POST', credentials:'same-origin', headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify(body)});
        const json = await response.json().catch(()=>({}));
        if (response.status >= 500) throw new Error('درخواست تکمیل نشد؛ در صورت دریافت پیامک، تا ثبت درخواست جدید از کد قبلی استفاده نکنید. دوباره تلاش کنید.');
        if (!response.ok) throw new Error(Object.values(json.errors || {}).flat()[0] || json.message || 'عملیات انجام نشد؛ دوباره تلاش کنید.');
        return json;
    }
    root.addEventListener('click', async e => {
        const button = e.target.closest('button');
        if (!button || busy || !selected) return;
        if (button.matches('[data-sale-verify]') && !/^\d{4}$/.test(digits(find('#saleApprovalCode').value))) {
            message('کد تأیید چهاررقمی را وارد کنید.', true);
            find('#saleApprovalCode').focus();
            return;
        }
        const version = generation, customer = selected, token = context();
        busy = true;
        root.querySelectorAll('button,input').forEach(el=>el.disabled=true);
        try {
            if (button.matches('[data-sale-request]')) {
                const json = await post(root.dataset.request, {customer_id:customer.id,context:token});
                if (version !== generation) return;
                approvalId = json.id; panel.hidden = false; find('#saleApprovalCode').value = ''; message(json.message);
            } else if (button.matches('[data-sale-verify]') && approvalId) {
                await post(`${root.dataset.verifyBase}/${approvalId}/verify`, {context:token, code:digits(find('#saleApprovalCode').value)});
                if (version !== generation) return;
                find('#saleApprovalCode').value = '';
                const query = new URLSearchParams({sale_authorization_context:token});
                const response = await fetch(`${root.dataset.customerBase}/${customer.id}?${query}`, {headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store'});
                const json = await response.json();
                if (version !== generation) return;
                if (!response.ok || !json.data?.customer) throw new Error('مجوز معتبر نیست؛ دوباره کد تأیید دریافت کنید.');
                document.dispatchEvent(new CustomEvent('customer-sale-approved', {detail:json.data.customer}));
                message('تأیید شد؛ فاکتور و پورسانت به نام شما ثبت می‌شود و مسئول مشتری تغییر نمی‌کند.');
                panel.hidden = true; find('[data-sale-request]').hidden = true;
            }
        } catch (error) { if (version === generation) message(error.message, true); }
        finally {
            if (version === generation) { busy = false; root.querySelectorAll('button,input').forEach(el=>el.disabled=false); if (!panel.hidden) find('#saleApprovalCode').focus(); }
        }
    });
    find('#saleApprovalCode').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();find('[data-sale-verify]').click();}});
})();
