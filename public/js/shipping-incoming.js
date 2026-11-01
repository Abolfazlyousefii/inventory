(() => {
    const modal = document.getElementById('shippingIncomingModal');
    if (!modal) return;
    const list = document.getElementById('shippingIncomingList');
    const state = document.getElementById('shippingIncomingState');
    const intro = document.getElementById('shippingIncomingIntro');
    const badge = document.getElementById('shippingIncomingCount');
    const more = document.getElementById('shippingIncomingMore');
    const retry = document.getElementById('shippingIncomingRetry');
    const fa = value => Number(value).toLocaleString('fa-IR');
    let controller, sequence = 0, nextPage = null, failedPage = 1, loading = false;
    function node(tag, className, text) {
        const el = document.createElement(tag);
        el.className = className;
        if (text !== undefined) el.textContent = text;
        return el;
    }
    function invoiceRow(invoice) {
        const article = node('article', 'incoming-invoice');
        const top = node('div', 'incoming-top');
        top.appendChild(node('strong', 'incoming-customer', invoice.customer_name));
        const number = node('span', 'incoming-number', 'فاکتور ');
        number.appendChild(node('b', '', invoice.uuid));
        top.appendChild(number);
        article.appendChild(top);
        article.appendChild(node('span', 'incoming-status' + (invoice.status === 'pending_collection' ? ' is-waiting' : ''), invoice.status_label));
        article.appendChild(node('p', 'incoming-address' + (!invoice.address ? ' is-missing' : ''), invoice.address || 'آدرس ارسال ثبت نشده است.'));
        return article;
    }
    async function load(page = 1) {
        if (loading && page !== 1) return;
        controller?.abort();
        controller = new AbortController();
        const currentController = controller, seq = ++sequence;
        loading = true;
        failedPage = page;
        if (page === 1) list.replaceChildren();
        nextPage = null;
        more.hidden = retry.hidden = true;
        state.classList.remove('is-error');
        state.textContent = 'در حال دریافت فاکتورها…';
        const timeout = setTimeout(() => currentController.abort(), 20000);
        try {
            const url = new URL(modal.dataset.url, location.origin);
            url.searchParams.set('page', page);
            const response = await fetch(url, {headers: {Accept:'application/json'}, signal:currentController.signal, credentials:'same-origin', cache:'no-store'});
            if (!response.ok) throw new Error(response.status === 403 ? 'دسترسی مشاهده این فهرست را ندارید.' : 'دریافت فاکتورها انجام نشد. دوباره تلاش کنید.');
            const data = await response.json();
            if (seq !== sequence) return;
            if (!Array.isArray(data.data)) throw new Error('پاسخ دریافت‌شده معتبر نیست.');
            const fragment = document.createDocumentFragment();
            data.data.forEach(invoice => fragment.appendChild(invoiceRow(invoice)));
            list.appendChild(fragment);
            badge.textContent = fa(data.total);
            intro.textContent = `${fa(data.total)} فاکتور در صف جمع‌آوری؛ هنوز آمادهٔ ارسال نیستند.`;
            state.textContent = Number(data.total) === 0 ? 'فعلاً فاکتوری در راه ارسال نیست.' : '';
            nextPage = data.next_page;
            more.hidden = !nextPage;
        } catch (error) {
            if (seq !== sequence) return;
            state.classList.add('is-error');
            state.textContent = error.name === 'AbortError' ? 'دریافت اطلاعات بیش از حد طول کشید. دوباره تلاش کنید.' : error.message;
            retry.hidden = false;
        } finally {
            clearTimeout(timeout);
            if (seq === sequence) loading = false;
        }
    }
    modal.addEventListener('show.bs.modal', () => load(1));
    modal.addEventListener('hidden.bs.modal', () => { ++sequence; controller?.abort(); loading = false; });
    more.addEventListener('click', () => { if (nextPage) load(nextPage); });
    retry.addEventListener('click', () => load(failedPage));
})();
