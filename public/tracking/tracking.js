(() => {
	'use strict';

	const endpoint = document.querySelector('meta[name="tracking-data-url"]')?.content;
	const hall = document.querySelector('meta[name="tracking-mode"]')?.content === 'hall';
	const state = { documents: [], currentType: 'all', query: '', active: null, page: 0 };
	const hallPageSize = () => 12;
	const $ = (id) => document.getElementById(id);
	const digits = (n) => new Intl.NumberFormat('fa-IR').format(Number(n) || 0);
	const text = (id, value) => { const el = $(id); if (el) el.textContent = value; };
	const hide = (id, hidden) => $(id)?.classList.toggle('hidden', hidden);
	const stages = ['ثبت سفارش', 'بررسی', 'آماده‌سازی', 'بسته‌بندی', 'ارسال'];
	let previousFocus = null;

	function el(tag, className, value) {
		const node = document.createElement(tag);
		if (className) node.className = className;
		if (value !== undefined) node.textContent = String(value);
		return node;
	}

	function row(label, value) {
		const container = el('div', 'info-row');
		container.append(el('span', '', label), el('strong', '', value ?? '—'));
		return container;
	}

	function renderCard(document) {
		const card = el('article', 'doc-card');
		const header = el('div', 'doc-head');
		const type = el('span', 'doc-type');
		type.append(el('span', 'doc-icon', document.type === 'invoice' ? '▤' : '◫'), el('span', '', document.type_label));
		const status = el('span', 'status-pill ' + document.tone, document.status);
		header.append(type, status);
		const label = el('small', 'doc-number-label', 'شماره سند');
		const number = el('h3', 'doc-number', document.number);
		const info = el('div', 'doc-info');
		if (hall) {
			info.append(row('مشتری:', document.customer || 'ثبت نشده'));
		} else {
			info.append(row('تاریخ سند', document.date), row('فروشنده', document.seller), row('تعداد اقلام', digits(document.item_count)));
			if (document.public_reference) info.append(row('کد نمایشگر', document.public_reference));
		}
		const action = el('div', 'doc-action');
		const open = el('button', 'detail-btn');
		open.type = 'button';
		open.append(el('span', '', '↗'), el('span', '', 'مشاهده وضعیت و جزئیات'));
		open.addEventListener('click', () => openDetails(document));
		if (!hall) action.append(open);
		card.append(header, label, number, info);
		if (!hall) card.append(action);
		return card;
	}

	function render() {
		const filtered = state.documents.filter(doc => {
			if (state.currentType !== 'all' && doc.type !== state.currentType) return false;
			const haystack = [doc.number, hall ? doc.customer || '' : doc.seller || '', doc.status, doc.type_label, doc.related_number || ''].join(' ').toLocaleLowerCase('fa-IR');
			return haystack.includes(state.query);
		});
		const list = $('document-list');
		list.replaceChildren();
		const batch = document.createDocumentFragment();
		const pageCount = Math.max(1, Math.ceil(filtered.length / hallPageSize()));
		state.page %= pageCount;
		const displayed = hall ? filtered.slice(state.page * hallPageSize(), (state.page + 1) * hallPageSize()) : filtered;
		displayed.forEach(doc => batch.append(renderCard(doc)));
		list.append(batch);
		text('result-meta', hall
			? 'نمایش ' + digits(displayed.length) + ' سفارش از ' + digits(filtered.length) + ' سفارش  |  صفحه ' + digits(state.page + 1) + ' از ' + digits(pageCount)
			: 'نمایش ' + digits(filtered.length) + ' سند از ' + digits(state.documents.length) + ' سند');
		hide('empty', filtered.length !== 0);
	}

	function openDetails(doc) {
		previousFocus = document.activeElement;
		state.active = doc;
		text('drawer-title', doc.type_label + ' ' + doc.number);
		const wrapper = $('drawer-content');
		wrapper.replaceChildren();
		wrapper.append(el('div', 'drawer-sublabel', 'شماره ' + doc.type_label), el('div', 'drawer-big-number', doc.number));
		const status = el('div', 'drawer-status ' + doc.tone);
		status.append(el('span', '', 'وضعیت فعلی'), el('strong', '', doc.status));
		wrapper.append(status, el('h3', 'details-title', 'مشخصات سند'));
		const grid = el('div', 'detail-grid');
		const pairs = [
			['نوع سند', doc.type_label], ['تاریخ', doc.date],
			['فروشنده', doc.seller], ['تعداد ردیف کالا', digits(doc.item_count)],
			['تعداد کل کالا', digits(doc.quantity)],
		];
		if (doc.related_number) pairs.push([doc.type === 'invoice' ? 'پیش‌فاکتور مرتبط' : 'فاکتور مرتبط', doc.related_number]);
		for (const [name, value] of pairs) {
			const cell = el('div', 'detail-cell');
			cell.append(el('small', '', name), el('strong', '', value));
			grid.append(cell);
		}
		wrapper.append(grid);
		if (doc.tone === 'cancelled') {
			wrapper.append(el('p', 'cancel-note', 'این سند در وضعیت فعال قرار ندارد. برای اطلاعات بیشتر با فروشنده خود تماس بگیرید.'));
		} else {
			wrapper.append(el('h3', 'details-title', 'مراحل پیگیری سفارش'));
			const steps = el('div', 'progress-steps ' + doc.tone);
			stages.forEach((stage, index) => {
				const part = el('div', 'progress-node' + (index <= doc.step ? ' done' : ''));
				part.append(el('div', 'progress-dot', index <= doc.step ? '✓' : ''), el('span', '', stage));
				steps.append(part);
			});
			wrapper.append(steps, el('p', 'progress-foot', 'مراحل بالا نمایی ساده از وضعیت فعلی سفارش هستند، نه سابقه ثبت‌شده تمام تغییرات.'));
		}
		hide('drawer-backdrop', false);
		hide('detail-drawer', false);
		document.body.classList.add('modal-open');
		$('detail-drawer').focus();
	}

	function closeDetails() {
		state.active = null;
		hide('drawer-backdrop', true);
		hide('detail-drawer', true);
		document.body.classList.remove('modal-open');
		previousFocus?.focus?.();
	}

	async function load() {
		if (!state.documents.length) hide('loading', false);
		hide('error', true);
		hide('empty', true);
		try {
			if (!endpoint) throw new Error('missing-endpoint');
			const response = await fetch(endpoint, { headers: { Accept: 'application/json' }, credentials: hall ? 'same-origin' : 'omit', cache: 'no-store', referrerPolicy: 'no-referrer' });
			if (!response.ok) {
				if (hall && (response.status === 403 || response.status === 401)) {
					const url = document.querySelector('meta[name="hall-unlock-url"]')?.content;
					if (url) window.location.replace(url);
					return;
				}
				if (response.status === 403 || response.status === 401) throw new Error('expired');
				throw new Error('server');
			}
			const payload = await response.json();
			if (!Array.isArray(payload.documents)) throw new Error('invalid-data');
			state.documents = payload.documents;
			if (!hall) text('customer-name', payload.customer?.name || 'مشتری گرامی');
			text('total-count', digits(payload.summary?.total));
			text('invoice-count', digits(payload.summary?.invoices));
			text('preinvoice-count', digits(payload.summary?.preinvoices));
			text('last-updated', 'آخرین بروزرسانی: ' + new Date().toLocaleTimeString('fa-IR', {hour:'2-digit',minute:'2-digit'}));
			hide('loading', true); // Measure the grid after the skeleton is removed.
			render();
		} catch (err) {
			if (hall && state.documents.length) text('last-updated', 'اتصال به سامانه قطع شده؛ تلاش مجدد خودکار…');
			if (!state.documents.length) { hide('error', false); hide('empty', true); }
			text('error-text', err.message === 'expired'
				? 'اعتبار این لینک پایان یافته است. برای دریافت لینک جدید با فروشنده تماس بگیرید.'
				: 'دریافت وضعیت سفارش‌ها امکان‌پذیر نیست. لطفاً مجدداً تلاش کنید.');
		} finally { hide('loading', true); }
	}

	document.querySelectorAll('.tab').forEach(button => button.addEventListener('click', () => {
		document.querySelectorAll('.tab').forEach(tab => {
			const selected = tab === button;
			tab.classList.toggle('active', selected);
			tab.setAttribute('aria-pressed', String(selected));
		});
		state.currentType = button.dataset.type;
		state.page = 0;
		render();
	}));
	$('document-search').addEventListener('input', event => {
		state.query = event.target.value.trim().toLocaleLowerCase('fa-IR');
		render();
	});
	$('drawer-close').addEventListener('click', closeDetails);
	$('drawer-backdrop').addEventListener('click', closeDetails);
	$('retry').addEventListener('click', load);
	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && state.active) closeDetails();
		if (event.key === 'Tab' && state.active) {
			const elements = [...$('detail-drawer').querySelectorAll('button:not([disabled]),[href],input:not([disabled])')];
			if (elements.length === 0) return;
			const first = elements[0];
			const last = elements[elements.length - 1];
			if (event.shiftKey && document.activeElement === first) {event.preventDefault();last.focus();}
			else if (!event.shiftKey && document.activeElement === last) {event.preventDefault();first.focus();}
		}
	});
	load();
	if (hall) {
		setInterval(() => { if (!document.hidden) load(); }, 30000);
		setInterval(() => {
			if (!document.hidden && state.documents.length > hallPageSize()) {
				state.page += 1;
				render();
			}
		}, 12000);
		document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
		window.addEventListener('resize', render);
	}
})();
