(() => {
    'use strict';
    const finder = document.getElementById('productFinderModal');
    const panel = document.getElementById('productModelList');
    if (!finder || !panel) return;
    const body = document.getElementById('productModelListBody');
    const select = document.getElementById('productModelListSelect');
    let controller, trigger, productId;
    const escape = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
    const siblings = [...panel.parentElement.children].filter(node => node !== panel);
    function close(restore = true) {
        controller?.abort(); panel.hidden = true; siblings.forEach(node => node.inert = false);
        if (restore && trigger?.isConnected) trigger.focus();
    }
    async function open(button) {
        close(false); trigger = button; productId = Number(button.dataset.modelList);
        panel.hidden = false; siblings.forEach(node => node.inert = true);
        panel.querySelector('[data-model-close]').focus(); select.disabled = true;
        body.textContent = 'در حال دریافت مدلیست…';
        controller = new AbortController(); const request = controller;
        const url = finder.dataset.modelsUrl.replace('__PRODUCT__', productId);
        try {
            const response = await fetch(url, {headers:{Accept:'application/json'}, credentials:'same-origin', signal:request.signal});
            if (!response.ok) throw new Error('unavailable');
            const data = (await response.json()).data;
            if (request !== controller || panel.hidden) return;
            const groups = new Map();
            (data.models || []).forEach(model => {const brand = model.brand || 'مدل‌های موجود'; if (!groups.has(brand)) groups.set(brand, []); groups.get(brand).push(model.name);});
            body.innerHTML = `<div class="product-model-list__name">${escape(data.name)}</div>` +
                (groups.size ? [...groups].map(([brand,names]) => `<h4>${escape(brand)}</h4><div class="product-model-list__models">${names.map(name => `<span dir="auto">${escape(name)}</span>`).join('')}</div>`).join('') : '<p>در حال حاضر مدل قابل سفارشی موجود نیست.</p>') +
                '<h4>رنگ‌بندی این محصول</h4>' + ((data.colors || []).length ? `<div class="product-model-list__models">${data.colors.map(name => `<span>${escape(name)}</span>`).join('')}</div><p class="product-model-list__hint">رنگ‌های قابل سفارش هر مدل در انتخاب تنوع مشخص می‌شود.</p>` : '<p class="product-model-list__hint">رنگ‌بندی برای این کالا ثبت نشده است.</p>');
            select.disabled = !groups.size;
        } catch (error) {
            if (error.name === 'AbortError') return;
            if (request !== controller || panel.hidden) return;
            body.textContent = 'دریافت مدلیست ممکن نشد؛ کالا ممکن است ناموجود یا غیرفعال شده باشد. دوباره تلاش کنید.';
        }
    }
    finder.addEventListener('click', event => {
        const button = event.target.closest('[data-model-list]');
        if (button) {event.preventDefault(); event.stopPropagation(); open(button);}
    });
    panel.querySelectorAll('[data-model-close]').forEach(button => button.addEventListener('click', () => close()));
    select.addEventListener('click', () => {const id = productId; close(false); finder.dispatchEvent(new CustomEvent('preinvoice:model-list-select', {detail:{productId:id}}));});
    finder.addEventListener('keydown', event => {
        if (panel.hidden) return;
        if (event.key === 'Escape') {event.preventDefault(); event.stopImmediatePropagation(); close();}
        if (event.key === 'Tab') {
            const buttons = [...panel.querySelectorAll('button:not(:disabled)')];
            if (event.shiftKey && document.activeElement === buttons[0]) {event.preventDefault(); buttons.at(-1).focus();}
            else if (!event.shiftKey && document.activeElement === buttons.at(-1)) {event.preventDefault(); buttons[0].focus();}
        }
    }, true);
    finder.addEventListener('hidden.bs.modal', () => close(false));
})();
