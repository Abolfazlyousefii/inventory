document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    const drawer = document.getElementById('bundleComponentsDrawer');
    if (!drawer) return;

    const title = document.getElementById('bundleDetailTitle');
    const code = document.getElementById('bundleDetailCode');
    const mode = document.getElementById('bundleDetailMode');
    const count = document.getElementById('bundleDetailCount');
    const units = document.getElementById('bundleDetailUnits');
    const shown = document.getElementById('bundleDetailShown');
    const list = document.getElementById('bundleDetailList');
    const status = document.getElementById('bundleDetailState');
    const search = document.getElementById('bundleDetailSearch');
    const moreButton = document.getElementById('bundleDetailMore');
    const closeButton = drawer.querySelector('.bundle-detail-close');

    let productId = null;
    let cursor = null;
    let hasMore = true;
    let totalCount = 0;
    let displayed = 0;
    let controller = null;
    let sequence = 0;
    let loading = false;
    let debounce = null;
    let returnFocus = null;
    let previousOverflow = '';

    function number(value) {
        return new Intl.NumberFormat('fa-IR').format(Number(value) || 0);
    }

    function create(tag, className, content) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (content !== undefined && content !== null) node.textContent = String(content);
        return node;
    }

    function componentCard(row) {
        const article = create('article', 'bundle-detail-item');
        const icon = create('span', 'bundle-detail-item-icon', '▧');
        icon.setAttribute('aria-hidden', 'true');
        const content = create('div', 'bundle-detail-item-main');
        content.appendChild(create('div', 'bundle-detail-item-name', row.product_name || 'کالا'));
        if (row.variant_name && row.variant_name !== row.product_name) {
            content.appendChild(create('div', 'bundle-detail-item-variant', row.variant_name));
        }
        const productCode = row.variant_code || row.product_code;
        if (productCode) {
            content.appendChild(create('div', 'bundle-detail-item-code', productCode));
        }
        const qty = create('div', 'bundle-detail-qty');
        qty.append(create('strong', '', number(row.quantity_per_bundle) + ' ×'));
        qty.append(create('small', '', 'در هر بسته'));
        article.append(icon, content, qty);
        return article;
    }

    function setStatus(message, error = false) {
        status.replaceChildren();
        status.textContent = message;
        status.classList.toggle('is-error', error);
        status.hidden = !message;
        if (error) {
            const retry = create('button', 'bundle-detail-retry', 'تلاش مجدد');
            retry.type = 'button';
            retry.addEventListener('click', () => load(displayed === 0 && cursor === null));
            status.appendChild(retry);
        }
    }

    function updateCount() {
        shown.textContent = 'نمایش ' + number(displayed) + ' از ' + number(totalCount) + ' قلم';
    }

    function close() {
        if (!drawer.classList.contains('is-open')) return;
        sequence++;
        clearTimeout(debounce);
        controller?.abort();
        controller = null;
        loading = false;
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = previousOverflow;
        returnFocus?.focus();
    }

    function open(id) {
        if (!Number.isSafeInteger(id) || id < 1) return;
        close();
        productId = id;
        const row = document.querySelector('[data-product-id="' + id + '"]');
        returnFocus = row?.querySelector('[data-product-actions]') || null;
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        title.textContent = 'اقلام بسته ترکیبی';
        code.textContent = '';
        mode.textContent = '—';
        count.textContent = '—';
        units.textContent = '—';
        search.value = '';
        closeButton.focus();
        resetAndLoad();
    }

    function resetAndLoad() {
        sequence++;
        controller?.abort();
        controller = null;
        loading = false;
        cursor = null;
        hasMore = true;
        totalCount = 0;
        displayed = 0;
        list.replaceChildren();
        moreButton.hidden = true;
        shown.textContent = '';
        setStatus('');
        load(true);
    }

    async function load(firstPage) {
        if (loading || !hasMore || !drawer.classList.contains('is-open')) return;
        const term = search.value.trim();
        if (term.length === 1) {
            setStatus('برای جستجو حداقل دو حرف وارد کنید.');
            return;
        }
        loading = true;
        controller = new AbortController();
        const current = ++sequence;
        const url = new URL('/products/' + productId + '/bundle-components', location.origin);
        url.searchParams.set('limit', '60');
        if (cursor !== null) url.searchParams.set('cursor', String(cursor));
        if (term.length >= 2) url.searchParams.set('q', term);
        moreButton.disabled = true;
        moreButton.hidden = true;
        setStatus(firstPage ? 'در حال دریافت اقلام بسته...' : '');
        try {
            const response = await fetch(url, {
                headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin',
                signal: controller.signal,
            });
            if (!response.ok) {
                throw new Error(response.status === 403
                    ? 'شما مجوز مشاهده اقلام این بسته را ندارید.'
                    : 'دریافت اطلاعات بسته با خطا مواجه شد.');
            }
            const data = await response.json();
            if (current !== sequence || !drawer.classList.contains('is-open')) return;

            if (firstPage) {
                title.textContent = data.product?.name || 'اقلام بسته ترکیبی';
                code.textContent = 'کد بسته: ' + (data.product?.short_code || '—');
                mode.textContent = data.product?.stock_mode === 'automatic' ? 'بسته خودکار' : 'بسته فیزیکی';
                totalCount = Number(data.meta?.total?.items) || 0;
                count.textContent = number(totalCount);
                units.textContent = number(data.meta?.total?.units);
            }

            const fragment = document.createDocumentFragment();
            const rows = Array.isArray(data.data) ? data.data : [];
            rows.forEach(row => fragment.appendChild(componentCard(row)));
            list.appendChild(fragment);
            displayed += rows.length;
            updateCount();
            hasMore = Boolean(data.meta?.has_more);
            const nextCursor = data.meta?.next_cursor;
            if (hasMore && (!Number.isSafeInteger(nextCursor) || nextCursor <= (cursor ?? 0))) {
                throw new Error('صفحه‌بندی اقلام بسته نامعتبر است.');
            }
            cursor = hasMore ? nextCursor : null;
            moreButton.hidden = !hasMore;
            setStatus(displayed === 0
                ? (term ? 'قلمی با این جستجو پیدا نشد.' : 'هنوز اقلامی برای این بسته ثبت نشده است.')
                : (!hasMore ? 'تمام اقلام بسته نمایش داده شدند.' : ''));
        } catch (error) {
            if (current === sequence && error.name !== 'AbortError') {
                setStatus(error.message || 'دریافت اقلام ناموفق بود.', true);
                moreButton.hidden = true;
            }
        } finally {
            if (current === sequence) {
                loading = false;
                moreButton.disabled = false;
            }
        }
    }

    // Capture before the existing action-menu handler removes the menu.
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-action="bundle"]');
        if (!button) return;
        event.preventDefault();
        open(Number(button.dataset.id));
    }, true);

    drawer.querySelectorAll('[data-bundle-close]').forEach(button =>
        button.addEventListener('click', close)
    );

    search.addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(resetAndLoad, 300);
    });
    moreButton.addEventListener('click', () => load(false));

    document.addEventListener('keydown', event => {
        if (!drawer.classList.contains('is-open')) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
        }
        if (event.key === 'Tab') {
            const focusables = [...drawer.querySelectorAll('button:not([disabled]), input:not([disabled])')]
                .filter(element => !element.hidden && element.getClientRects().length);
            if (!focusables.length) return;
            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault(); last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault(); first.focus();
            }
        }
    });
});
