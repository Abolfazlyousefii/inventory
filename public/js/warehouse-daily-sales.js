(() => {
    'use strict';
    const root = document.querySelector('[data-daily-sales]');
    if (!root) return;
    const $ = id => root.querySelector('#wd-' + id);
    const fa = n => Number(n).toLocaleString('fa-IR');
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const digits = s => s.replace(/[۰-۹]/g, c => '۰۱۲۳۴۵۶۷۸۹'.indexOf(c)).replace(/[٠-٩]/g, c => '٠١٢٣٤٥٦٧٨٩'.indexOf(c));
    const key = r => `${r.product_id}:${r.variant_id}`;
    const drafts = new Map();
    let day = '', filter = 'all', page = 1, lastPage = 1, rows = [], sequence = 0, busy = false, saving = false, searchTimer, detailState, detailSequence = 0;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    // This page has one date-only picker; avoid the shell's datetime/auto-focus overlay.
    window.jalaliDatepicker?.updateOptions({selector:'#wd-day', autoShow:false, time:false, hideAfterChange:true});
    $('day').addEventListener('click', e => window.jalaliDatepicker?.show(e.target));
    $('day').addEventListener('input', e => { if (/^1[34]\d{2}[/-]\d{1,2}[/-]\d{1,2}$/.test(digits(e.target.value.trim()))) window.jalaliDatepicker?.hide(); });
    function message(text, success = false) {
        $('message').textContent = text;
        $('message').classList.toggle('wd-success', success);
        $('message').hidden = !text;
    }
    async function request(url, options = {}) {
        const response = await fetch(url, {...options, headers: {'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest', ...options.headers}});
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'دریافت اطلاعات انجام نشد؛ اتصال را بررسی کنید.');
            error.status = response.status;
            throw error;
        }
        return data;
    }
    function url(base, params) { const u = new URL(base, location.href); Object.entries(params).forEach(([k,v]) => u.searchParams.set(k, v)); return u; }
    function controls() {
        root.querySelectorAll('[data-wd-save]').forEach(b => b.disabled = busy || saving || b.dataset.unavailable === 'true');
        $('prev').disabled = busy || saving || page <= 1;
        $('next').disabled = busy || saving || page >= lastPage;
        $('day').disabled = busy || saving;
        $('date-form').querySelector('button').disabled = busy || saving;
        $('search').disabled = saving;
        root.querySelectorAll('[data-wd-filter]').forEach(b => b.disabled = saving);
        $('cards').setAttribute('aria-busy', String(busy));
    }
    function deltaText(qty, stock) {
        if (qty === '' || qty === null || qty === undefined) return 'هنوز شمارش نشده';
        if (stock === null) return 'مبنای شمارش نامشخص';
        const d = Number(qty) - Number(stock);
        return d === 0 ? 'بدون اختلاف' : fa(Math.abs(d)) + ' عدد ' + (d < 0 ? 'کسری' : 'اضافه');
    }
    function render() {
        $('cards').innerHTML = rows.length ? rows.map(r => {
            const draft = drafts.get(key(r)), counted = r.physical_quantity !== null;
            const qty = draft?.physical_quantity ?? (counted ? String(r.physical_quantity) : '');
            const snapshot = draft?.expected_stock ?? (counted ? Number(r.stock_snapshot) : r.current_stock);
            const issue = counted && Number(r.physical_quantity) !== Number(r.stock_snapshot);
            const stale = r.current_stock === null || (counted && Number(r.current_stock) !== Number(r.stock_snapshot)) || (draft && Number(draft.expected_stock) !== Number(r.current_stock));
            const note = draft?.note ?? r.note ?? '';
            const delta = Number(qty) - Number(snapshot);
            return `<article class="wd-card" data-key="${key(r)}"><div class="wd-identity"><div><div class="wd-name">${esc(r.name || 'کالای حذف‌شده')}</div><div class="wd-variant">${esc(r.variant_name)}<br><span dir="ltr">${esc(r.code || '—')}</span></div></div><span class="wd-tag ${counted ? issue ? 'wd-issue' : 'wd-ok' : ''}">${counted ? issue ? 'مغایرت ثبت‌شده' : 'مطابق شمارش' : 'بررسی‌نشده'}</span></div>
            <div class="wd-movement"><div><span>موجودی فعلی</span><b>${r.current_stock === null ? '—' : fa(r.current_stock)}</b></div><button class="wd-in" data-wd-detail="incoming" aria-label="جزئیات ورودی ${esc(r.name)}"><span>ورودی روز</span><b>${fa(r.incoming)}</b></button><button class="wd-out" data-wd-detail="outgoing" aria-label="جزئیات خروجی ${esc(r.name)}"><span>خروجی روز</span><b>${fa(r.outgoing)}</b></button><button data-wd-detail="sales" aria-label="جزئیات فروش ${esc(r.name)}"><span>فروش روز</span><b>${fa(r.sales)}</b></button></div>
            <div class="wd-count"><div class="wd-count-row"><label>شمارش<input class="wd-physical" inputmode="numeric" enterkeyhint="next" autocomplete="off" aria-label="شمارش ${esc(r.name)} ${esc(r.variant_name)}" placeholder="تعداد" value="${esc(qty === '' ? '' : qty)}" ${r.current_stock === null ? 'disabled' : ''}></label><output class="wd-delta ${delta < 0 ? 'wd-negative' : delta > 0 ? 'wd-positive' : ''}">${esc(deltaText(qty, snapshot))}</output></div>
            <textarea class="wd-note" maxlength="2000" aria-label="یادداشت ${esc(r.name)}" placeholder="توضیح مغایرت…" ${note ? '' : 'hidden'}>${esc(note)}</textarea><div class="wd-count-tools"><button class="wd-button wd-save" data-wd-save data-unavailable="${r.current_stock === null}">ثبت و بعدی</button><button class="wd-note-toggle" data-wd-note aria-label="یادداشت و توضیح">⋯</button></div>
            ${counted ? `<p class="wd-saved">مبنای شمارش: ${fa(r.stock_snapshot)} عدد · ${esc(r.counted_by || '')} · ${esc(r.counted_at || '')}</p>` : ''}
            ${stale ? `<p class="wd-stale">${r.current_stock === null ? 'گردش قدیمی بدون تنوع مشخص یا کالای حذف‌شده؛ ثبت شمارش مجاز نیست.' : 'موجودی پس از شمارش/نمایش تغییر کرده؛ پیش از ثبت، تعداد را دوباره بررسی و وارد کنید.'}</p>` : ''}</div></article>`;
        }).join('') : '<div class="wd-no-results">موردی با این روز و فیلتر پیدا نشد.</div>';
        controls();
    }
    async function load(wantedPage = page, requestedDay = day) {
        if (!requestedDay || saving) return false;
        const seq = ++sequence;
        busy = true; controls();
        try {
            const data = await request(url(root.dataset.url, {day:requestedDay, q:$('search').value.trim(), filter, page:wantedPage}));
            if (seq !== sequence) return false;
            if (day !== requestedDay) drafts.clear();
            day = requestedDay; page = data.page; lastPage = data.last_page; rows = data.rows;
            // A saved pending row can move the last page back by one.
            if (!rows.length && page > lastPage) { busy = false; return load(lastPage); }
            $('initial').hidden = true; $('report').hidden = false;
            $('total').textContent = fa(data.summary.total); $('reviewed').textContent = fa(data.summary.reviewed); $('issues').textContent = fa(data.summary.issues);
            $('day-label').textContent = 'گردش روز ' + data.day;
            $('read-at').textContent = 'موجودی فعلی · دریافت ' + data.read_at;
            $('page-label').textContent = `صفحه ${fa(page)} از ${fa(lastPage)} · ${fa(data.total)} ردیف`;
            $('download').href = url(root.dataset.exportUrl, {day, q:$('search').value.trim()});
            $('print').href = url(root.dataset.exportUrl, {day, q:$('search').value.trim(), filter, format:'print'});
            render();
            return true;
        } catch (e) { if (seq === sequence) message(e.message); return false; }
        finally { if (seq === sequence) { busy = false; controls(); } }
    }
    function getRow(card) { return rows.find(r => key(r) === card.dataset.key); }
    function draftFor(card) {
        const r = getRow(card);
        const d = drafts.get(key(r)) || {physical_quantity:card.querySelector('.wd-physical').value, note:card.querySelector('.wd-note').value, expected_stock:r.current_stock, version:r.version};
        drafts.set(key(r), d); return d;
    }
    $('cards').addEventListener('input', e => {
        const card = e.target.closest('.wd-card'); if (!card) return;
        const r = getRow(card), d = draftFor(card);
        if (e.target.matches('.wd-physical')) {
            const value = digits(e.target.value).replace(/[٬,\s]/g, '');
            d.physical_quantity = value; d.expected_stock = r.current_stock; d.version = r.version; d.recounted = true;
            const valid = /^\d+$/.test(value) && Number(value) <= 1000000000;
            const out = card.querySelector('.wd-delta');
            out.textContent = valid ? deltaText(value, r.current_stock) : 'تعداد معتبر وارد کنید';
            out.className = 'wd-delta ' + (Number(value) < r.current_stock ? 'wd-negative' : Number(value) > r.current_stock ? 'wd-positive' : '');
        } else if (e.target.matches('.wd-note')) d.note = e.target.value;
    });
    $('cards').addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.matches('.wd-physical')) { e.preventDefault(); e.target.closest('.wd-card').querySelector('[data-wd-save]').click(); } });
    $('cards').addEventListener('click', async e => {
        const b = e.target.closest('button'), card = b?.closest('.wd-card'); if (!card) return;
        const r = getRow(card);
        if (b.hasAttribute('data-wd-note')) { const t = card.querySelector('.wd-note'); t.hidden = !t.hidden; if (!t.hidden) t.focus(); }
        if (b.dataset.wdDetail) { detailState = {row:r, type:b.dataset.wdDetail, day}; $('detail').showModal(); await detailLoad(1); }
        if (!b.hasAttribute('data-wd-save') || saving || busy || r.current_stock === null) return;
        const d = draftFor(card);
        if (r.physical_quantity !== null && Number(r.stock_snapshot) !== Number(r.current_stock) && !d.recounted) { message('موجودی پس از شمارش قبلی تغییر کرده؛ دوباره شمارش کنید و تعداد را وارد کنید.'); card.querySelector('.wd-physical').focus(); return; }
        d.physical_quantity = digits(card.querySelector('.wd-physical').value).replace(/[٬,\s]/g, '');
        d.note = card.querySelector('.wd-note').value;
        if (!/^\d+$/.test(d.physical_quantity) || Number(d.physical_quantity) > 1000000000) { message('تعداد معتبر وارد کنید؛ صفر نیز قابل ثبت است.'); card.querySelector('.wd-physical').focus(); return; }
        message(''); saving = true; controls();
        try {
            await request(root.dataset.saveUrl, {method:'POST', headers:{'Content-Type':'application/json', 'X-CSRF-TOKEN':csrf}, body:JSON.stringify({day, product_id:r.product_id, variant_id:r.variant_id, ...d})});
            drafts.delete(key(r)); saving = false;
            message('شمارش و یادداشت ثبت شد؛ موجودی تغییر نکرد.', true);
            await load();
            const next = rows.find(item => item.physical_quantity === null && item.current_stock !== null);
            const input = next && [...$('cards').querySelectorAll('.wd-card')].find(el => el.dataset.key === key(next))?.querySelector('.wd-physical');
            if (input) { input.focus({preventScroll:true}); input.closest('.wd-card').scrollIntoView({block:'start', behavior:'smooth'}); }
        } catch (error) {
            saving = false;
            if (error.status === 409) await load();
            message(error.message);
        } finally { saving = false; controls(); }
    });
    async function detailLoad(wantedPage) {
        const state = detailState, seq = ++detailSequence;
        if (!state) return;
        $('detail-title').textContent = {incoming:'ورودی‌های روز', outgoing:'خروجی‌های روز', sales:'فروش روز'}[state.type];
        $('detail-body').textContent = 'در حال دریافت…';
        $('detail-prev').disabled = true; $('detail-next').disabled = true;
        try {
            const data = await request(url(root.dataset.detailUrl, {day:state.day, product_id:state.row.product_id, variant_id:state.row.variant_id, type:state.type, page:wantedPage}));
            if (seq !== detailSequence) return;
            $('detail-body').innerHTML = `<b>${esc(state.row.name)} · ${esc(state.row.variant_name)}</b>` + (data.rows.length ? data.rows.map(r => `<div class="wd-transaction"><div>${r.url ? `<a href="${esc(r.url)}" target="_blank" rel="noopener">${esc(r.label)}</a>` : `<b>${esc(r.label)}</b>`}<small>${esc(r.reason)}</small><small>${esc(r.time)}${r.actor ? ' · ' + esc(r.actor) : ''}</small>${r.url ? '' : '<small>سند مرتبط قابل دسترسی نیست؛ ممکن است قدیمی، حذف‌شده یا خارج از دسترسی شما باشد.</small>'}</div><strong>${fa(r.quantity)} عدد</strong></div>`).join('') : '<p>گردشی برای این روز ثبت نشده است.</p>');
            detailState.page = data.page;
            $('detail-page').textContent = `صفحه ${fa(data.page)} از ${fa(data.last_page)} · ${fa(data.total)} ردیف`;
            $('detail-prev').disabled = data.page <= 1; $('detail-next').disabled = data.page >= data.last_page;
        } catch (e) { if (seq === detailSequence) $('detail-body').textContent = e.message; }
    }
    $('detail-prev').onclick = () => detailLoad(detailState.page - 1);
    $('detail-next').onclick = () => detailLoad(detailState.page + 1);
    $('close').onclick = () => { detailSequence++; $('detail').close(); };
    $('date-form').addEventListener('submit', async e => {
        e.preventDefault(); if (busy || saving) return;
        if (drafts.size && !confirm('تعداد یا یادداشت ثبت‌نشده دارید. با تغییر روز کنار گذاشته شوند؟')) return;
        window.jalaliDatepicker?.hide(); $('day').blur(); message('');
        await load(1, digits($('day').value.trim()).replaceAll('-', '/'));
    });
    $('search').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => load(1), 350); });
    root.querySelectorAll('[data-wd-filter]').forEach(b => b.onclick = () => {
        filter = b.dataset.wdFilter;
        root.querySelectorAll('[data-wd-filter]').forEach(t => { t.classList.toggle('wd-active', t === b); t.setAttribute('aria-pressed', String(t === b)); });
        load(1);
    });
    $('prev').onclick = () => load(page - 1); $('next').onclick = () => load(page + 1);
    [$('download'), $('print')].forEach(a => a.addEventListener('click', e => { if (drafts.size && !confirm('بررسی‌های ثبت‌نشده در خروجی نیستند. ادامه می‌دهید؟')) e.preventDefault(); }));
    window.addEventListener('beforeunload', e => { if (drafts.size) { e.preventDefault(); e.returnValue = ''; } });
})();
