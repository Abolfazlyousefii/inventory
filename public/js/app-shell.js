(() => {
    'use strict';

    const collapsedStorageKey = 'aria_sidebar_collapsed';
    const accordionStorageKey = 'inventory.sidebar.open-section';
    const readStorage = (key, fallback = null) => {
        try {
            return window.localStorage.getItem(key) ?? fallback;
        } catch (error) {
            return fallback;
        }
    };

    const writeStorage = (key, value) => {
        try {
            if (value === null) {
                window.localStorage.removeItem(key);
            } else {
                window.localStorage.setItem(key, value);
            }
        } catch (error) {
            // The shell remains usable when storage is blocked.
        }
    };

    if (readStorage(collapsedStorageKey) === '1' && !window.matchMedia('(max-width: 991.98px)').matches) {
        document.body.classList.add('sidebar-collapsed');
    }

    document.addEventListener('DOMContentLoaded', () => {
        initSidebar();
        initBackButton();
        initNotifications();
        initMoneyInputs();
        initJalaliDatepickers();
    });

    function initSidebar() {
        const sidebar = document.getElementById('appSidebar');
        if (!sidebar) {
            return;
        }

        const openButton = document.getElementById('sidebarToggleBtn');
        const closeButton = document.getElementById('sidebarCloseBtn');
        const collapseButton = document.getElementById('sidebarCollapseBtn');
        const backdrop = document.getElementById('sidebarBackdrop');
        const accordionItems = Array.from(sidebar.querySelectorAll('[data-accordion-section]'));
        const initialOpenSection = sidebar.dataset.initialOpenSection || null;
        let mobileReturnFocus = null;

        const isMobile = () => window.matchMedia('(max-width: 991.98px)').matches;
        const panelFor = item => item.querySelector('[data-accordion-panel]');

        const updateCollapseButton = () => {
            const collapsed = document.body.classList.contains('sidebar-collapsed');
            collapseButton?.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
            collapseButton?.setAttribute('aria-label', collapsed ? 'بازکردن سایدبار' : 'جمع‌کردن سایدبار');
            collapseButton?.setAttribute('title', collapsed ? 'بازکردن سایدبار' : 'جمع‌کردن سایدبار');
        };

        const setCollapsed = collapsed => {
            document.body.classList.toggle('sidebar-collapsed', collapsed);
            writeStorage(collapsedStorageKey, collapsed ? '1' : '0');
            updateCollapseButton();
            requestAnimationFrame(refreshOpenPanelHeights);
        };

        const setPanelState = (item, open) => {
            const panel = panelFor(item);
            const trigger = item.querySelector('[data-accordion-trigger]');
            if (!panel || !trigger) {
                return;
            }

            item.classList.toggle('is-open', open);
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            panel.style.maxHeight = open ? `${panel.scrollHeight}px` : '0px';
        };

        const openOnlySection = sectionId => {
            accordionItems.forEach(item => {
                setPanelState(item, item.dataset.accordionSection === sectionId);
            });
        };

        function refreshOpenPanelHeights() {
            accordionItems.forEach(item => {
                if (item.classList.contains('is-open')) {
                    const panel = panelFor(item);
                    if (panel) {
                        panel.style.maxHeight = `${panel.scrollHeight}px`;
                    }
                }
            });
        }

        const storedSection = readStorage(accordionStorageKey);
        const desiredSection = initialOpenSection || storedSection;
        const hasDesiredSection = accordionItems.some(item => item.dataset.accordionSection === desiredSection);
        openOnlySection(hasDesiredSection ? desiredSection : null);
        updateCollapseButton();

        const openMobileSidebar = () => {
            if (!isMobile()) {
                return;
            }
            mobileReturnFocus = document.activeElement;
            document.body.classList.add('sidebar-open');
            openButton?.setAttribute('aria-expanded', 'true');
            window.requestAnimationFrame(() => closeButton?.focus());
        };

        const closeMobileSidebar = (restoreFocus = true) => {
            const wasOpen = document.body.classList.contains('sidebar-open');
            document.body.classList.remove('sidebar-open');
            openButton?.setAttribute('aria-expanded', 'false');
            if (wasOpen && restoreFocus && mobileReturnFocus instanceof HTMLElement) {
                mobileReturnFocus.focus();
            }
        };

        openButton?.addEventListener('click', openMobileSidebar);
        closeButton?.addEventListener('click', () => closeMobileSidebar());
        backdrop?.addEventListener('click', () => closeMobileSidebar());
        collapseButton?.addEventListener('click', () => {
            if (!isMobile()) {
                setCollapsed(!document.body.classList.contains('sidebar-collapsed'));
            }
        });

        accordionItems.forEach(item => {
            const trigger = item.querySelector('[data-accordion-trigger]');
            trigger?.addEventListener('click', () => {
                const sectionId = item.dataset.accordionSection;
                const openSection = () => {
                    const willOpen = !item.classList.contains('is-open');
                    openOnlySection(willOpen ? sectionId : null);
                    writeStorage(accordionStorageKey, willOpen ? sectionId : null);
                };

                if (!isMobile() && document.body.classList.contains('sidebar-collapsed')) {
                    setCollapsed(false);
                    window.requestAnimationFrame(() => {
                        openOnlySection(sectionId);
                        writeStorage(accordionStorageKey, sectionId);
                    });
                    return;
                }

                openSection();
            });
        });

        sidebar.addEventListener('click', event => {
            const link = event.target.closest('a');
            if (link && isMobile()) {
                window.setTimeout(() => closeMobileSidebar(false), 50);
            }
        });

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
                event.preventDefault();
                closeMobileSidebar();
                return;
            }

            if (event.key !== 'Tab' || !isMobile() || !document.body.classList.contains('sidebar-open')) {
                return;
            }

            const focusable = Array.from(sidebar.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )).filter(element => element.offsetParent !== null);
            if (focusable.length === 0) {
                return;
            }

            const first = focusable[0];
            const last = focusable.at(-1);
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        window.addEventListener('resize', () => {
            if (!isMobile()) {
                closeMobileSidebar(false);
                document.body.classList.toggle('sidebar-collapsed', readStorage(collapsedStorageKey) === '1');
                updateCollapseButton();
            } else {
                document.body.classList.remove('sidebar-collapsed');
            }
            refreshOpenPanelHeights();
        });
    }

    function initBackButton() {
        const button = document.getElementById('appBackBtn');
        button?.addEventListener('click', () => {
            const fallbackUrl = button.dataset.fallbackUrl || '/';
            if (window.history.length > 1) {
                window.history.back();
                return;
            }
            window.location.href = fallbackUrl;
        });
    }

    function initNotifications() {
        const panel = document.getElementById('notifPanel');
        const bell = document.getElementById('notifBell');
        if (!panel || !bell) return;

        const { notificationsCountUrl: countUrl, notificationsLatestUrl: latestUrl } = document.body.dataset;
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const faNumber = value => new Intl.NumberFormat('fa-IR').format(value);
        const surfaces = Array.from(document.querySelectorAll('[data-notification-surface]')).map(element => ({
            element,
            filter: element.dataset.filter,
            perPage: Number(element.dataset.perPage),
            page: Number(element.dataset.page) || 1,
            lastPage: 1,
            signature: null,
        }));
        const state = { timer: null, controller: null, sequence: 0, busy: false, count: null };
        const activeSurfaces = () => surfaces.filter(surface => surface.element !== panel || panel.classList.contains('is-open'));
        const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g,
            character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[character]);
        const localUrl = value => {
            if (typeof value !== 'string' || !value.trim()) throw new Error('Missing notification URL');
            const url = new URL(value, window.location.href);
            if (url.origin !== window.location.origin || !['http:', 'https:'].includes(url.protocol)) throw new Error('Invalid notification URL');
            return url.href;
        };
        const showError = (surface, message = '') => {
            const error = surface.element.querySelector('[data-notification-error]');
            error.querySelector('[data-notification-error-text]').textContent = message;
            error.hidden = !message;
        };
        const updateControls = () => {
            document.querySelectorAll('[data-notification-read-all] button').forEach(button => {
                button.disabled = state.busy || state.count === null || state.count === 0;
            });
        };
        const updateNotificationCount = count => {
            if (!Number.isSafeInteger(count) || count < 0) throw new Error('Invalid unread count');
            state.count = count;
            const badge = document.getElementById('notifBadge');
            badge.textContent = faNumber(count);
            badge.title = `${faNumber(count)} اعلان خوانده‌نشده`;
            badge.classList.toggle('d-none', count === 0);
            bell.setAttribute('aria-label', `اعلان‌ها، ${faNumber(count)} خوانده‌نشده`);
            document.querySelectorAll('[data-notification-count]').forEach(element => {
                element.textContent = `${faNumber(count)} خوانده‌نشده`;
            });
            updateControls();
        };
        const request = async (url, options = {}) => {
            const response = await fetch(localUrl(url), {
                cache: 'no-store', credentials: 'same-origin', ...options,
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Notification request failed');
            return response.json();
        };
        // The previous API returned an array; accept it during a staged deployment.
        const categoryFor = notification => {
            if (notification.category) return notification.category;
            const type = String(notification.type || '').toLowerCase();
            if (type.includes('personnel')) return 'پرسنل';
            if (/finance|payment/.test(type) || type === 'preinvoice_submitted') return 'مالی';
            if (/warehouse|inventory|ship|collection|stock/.test(type)) return 'انبار';
            if (/invoice|customer|sales/.test(type)) return 'فروش';
            return 'عمومی';
        };
        const safeDestination = value => {
            if (!value) return null;
            try { return localUrl(value); } catch { return null; }
        };
        const inboxUrl = () => panel.querySelector('.notif-panel__foot a')?.getAttribute('href') || '/notifications';
        const card = notification => {
            const unread = !notification.read_at;
            const openUrl = localUrl(notification.open_url);
            const readUrl = notification.read_url || openUrl.replace(/\/open(?:\?.*)?$/, '/read');
            const category = categoryFor(notification);
            return `<form method="POST" action="${escapeHtml(openUrl)}" data-notification-open data-read-url="${escapeHtml(localUrl(readUrl))}" data-notification-destination="${escapeHtml(safeDestination(notification.url) || '')}" data-notification-id="${escapeHtml(notification.id)}">
                <input type="hidden" name="_token" value="${escapeHtml(csrfToken)}">
                <button type="submit" class="notif-card ${unread ? 'notif-card--unread' : ''}">
                    <span class="notif-card__body">
                        <span class="notif-card__top"><span class="notif-card__badge" data-category="${escapeHtml(category)}">${escapeHtml(category)}</span><time datetime="${escapeHtml(notification.created_at)}">${escapeHtml(notification.created_at_human || notification.created_at)}</time></span>
                        <span class="notif-card__title-line"><span class="notif-card__dot" aria-hidden="true"></span><strong class="notif-card__title">${escapeHtml(notification.title)}</strong></span>
                        ${notification.message ? `<span class="notif-card__message">${escapeHtml(notification.message)}</span>` : ''}
                        <span class="notif-card__meta"><span class="notif-card__link">مشاهده جزئیات <span aria-hidden="true">←</span></span></span>
                        <span class="visually-hidden">${unread ? 'خوانده‌نشده' : 'خوانده‌شده'}</span>
                    </span>
                </button>
            </form>`;
        };
        const emptyState = (message, kind = 'empty') => `<div class="notif-empty notif-empty--${kind}"><span class="notif-empty__icon" aria-hidden="true">${kind === 'error' ? '!' : kind === 'loading' ? '…' : '✓'}</span><span>${escapeHtml(message)}</span></div>`;
        const countFrom = async (data, options) => Number.isSafeInteger(data?.count) && data.count >= 0 ? data.count : (await request(countUrl, options)).count;
        async function normalizeListing(raw, surface, options) {
            if (!Array.isArray(raw)) {
                if (!Array.isArray(raw?.items) || !Number.isSafeInteger(raw.page) || !Number.isSafeInteger(raw.last_page) || raw.page < 1 || raw.last_page < raw.page) throw new Error('Invalid notification list');
                if (!Number.isSafeInteger(raw.count) || raw.count < 0) throw new Error('Invalid unread count');
                return raw;
            }
            const count = await countFrom({}, options);
            const items = raw.filter(row => surface.filter === 'all' || Boolean(row.read_at) === (surface.filter === 'read'));
            // Old servers expose a bounded list, not real pagination. Never invent pages.
            return { items, count, page: 1, last_page: 1, legacy: true };
        }
        const render = (surface, data) => {
            const list = surface.element.querySelector('[data-notification-list]');
            const signature = JSON.stringify([data.items, data.legacy, data.legacy ? data.count : null]);
            if (signature !== surface.signature) {
                const focusedId = document.activeElement?.closest('[data-notification-id]')?.dataset.notificationId;
                const scrollTop = list.scrollTop;
                const incomplete = data.legacy && surface.filter === 'unread' && data.count > data.items.length;
                const message = surface.filter === 'unread' ? 'همه اعلان‌ها را خوانده‌اید.' : 'اعلانی برای نمایش وجود ندارد.';
                list.innerHTML = data.items.length ? data.items.map(card).join('') : emptyState(incomplete ? 'اعلان‌های بیشتری در صندوق شما وجود دارد.' : message);
                if (incomplete) list.innerHTML += '<p class="notif-legacy-note">برای مشاهده فهرست کامل، صندوق اعلان‌ها را باز کنید.</p>';
                list.scrollTop = scrollTop;
                if (focusedId) Array.from(list.querySelectorAll('[data-notification-id]')).find(form => form.dataset.notificationId === focusedId)?.querySelector('button')?.focus({ preventScroll: true });
                surface.signature = signature;
            }
            surface.page = data.page;
            surface.lastPage = data.last_page;
            const pagination = surface.element.querySelector('[data-notification-pagination]');
            pagination.hidden = Boolean(data.legacy);
            pagination.querySelector('[data-notification-page-label]').textContent = `صفحه ${faNumber(data.page)} از ${faNumber(data.last_page)}`;
            pagination.querySelectorAll('[data-notification-step]').forEach(control => {
                const next = data.page + Number(control.dataset.notificationStep);
                const disabled = next < 1 || next > data.last_page;
                control.setAttribute('aria-disabled', String(disabled));
                if (control.tagName === 'BUTTON') control.disabled = disabled;
                else {
                    const url = new URL(window.location.href);
                    url.searchParams.set('page', next);
                    control.href = disabled ? '#' : url.href;
                }
            });
            list.setAttribute('aria-busy', 'false');
        };

        // Every refresh has a generation. A write cancels stale reads before it changes the count.
        function cancelRefresh() {
            state.sequence++;
            state.controller?.abort();
            state.controller = null;
        }
        async function refresh() {
            if (state.busy || !countUrl || !latestUrl) return;
            cancelRefresh();
            const sequence = state.sequence;
            const controller = new AbortController();
            state.controller = controller;
            const current = () => sequence === state.sequence && !state.busy;
            try {
                const visible = activeSurfaces();
                if (!visible.length) {
                    const data = await request(countUrl, { signal: controller.signal });
                    if (current()) updateNotificationCount(data.count);
                    return;
                }
                for (const surface of visible) {
                    const url = new URL(latestUrl, window.location.href);
                    url.searchParams.set('filter', surface.filter);
                    url.searchParams.set('per_page', surface.perPage);
                    url.searchParams.set('page', surface.page);
                    const raw = await request(url.href, { signal: controller.signal });
                    const data = await normalizeListing(raw, surface, { signal: controller.signal });
                    if (!current()) return;
                    // Preserve the server-rendered full page when a legacy API cannot paginate it.
                    updateNotificationCount(data.count);
                    if (!data.legacy || surface.element === panel) render(surface, data);
                    showError(surface);
                }
            } catch (error) {
                if (error.name !== 'AbortError' && current()) {
                    activeSurfaces().forEach(surface => {
                        surface.element.querySelector('[data-notification-list]').setAttribute('aria-busy', 'false');
                        if (surface.element === panel && surface.signature === null) surface.element.querySelector('[data-notification-list]').innerHTML = emptyState('بارگذاری اعلان‌ها انجام نشد.', 'error');
                        showError(surface, 'دریافت اعلان‌ها ناموفق بود. دوباره تلاش کنید.');
                    });
                }
            } finally {
                if (state.controller === controller) state.controller = null;
            }
        }
        const backdrop = document.getElementById('notificationBackdrop');
        const pageShell = document.querySelector('.app-layout');
        const setPanelOpen = open => {
            if (backdrop) backdrop.hidden = !open;
            if (pageShell && backdrop) pageShell.inert = open;
            if (!open) bell.focus({ preventScroll: true });
            document.body.classList?.toggle('notification-dialog-open', open);
            panel.classList.toggle('is-open', open);
            panel.setAttribute('aria-hidden', String(!open));
            bell.setAttribute('aria-expanded', String(open));
            if (open) {
                surfaces.find(surface => surface.element === panel).page = 1;
                document.getElementById('notifCloseBtn')?.focus();
            }
            refresh();
        };
        async function mutate(form, surface) {
            if (state.busy) return;
            const isAll = form.hasAttribute('data-notification-read-all');
            const submit = form.querySelector('button');
            cancelRefresh();
            state.busy = true;
            updateControls();
            submit.disabled = true;
            showError(surface);
            let destination = null;
            try {
                const data = await request(isAll ? form.action : form.dataset.readUrl, { method: 'POST' });
                if (!data || data.ok === false) throw new Error('Notification write failed');
                // Legacy write endpoints acknowledge success without returning the new count.
                let count = null;
                try { count = await countFrom(data); updateNotificationCount(count); }
                catch { state.count = null; updateControls(); }
                if (!isAll) destination = safeDestination(data.url) || safeDestination(form.dataset.notificationDestination) || safeDestination(inboxUrl()) || localUrl('/notifications');
                // Remove affected unread rows immediately; the refresh fills any remaining page slots.
                surfaces.filter(item => item.filter === 'unread').forEach(item => {
                    const list = item.element.querySelector('[data-notification-list]');
                    if (isAll) list.innerHTML = count === 0 ? emptyState('همه اعلان‌ها را خوانده‌اید.') : emptyState('در حال دریافت اعلان‌ها…', 'loading');
                    else list.querySelectorAll('[data-notification-id]').forEach(row => {
                        if (row.dataset.notificationId === form.dataset.notificationId) row.remove();
                    });
                    item.signature = null;
                    item.page = 1;
                    item.element.querySelector('[data-notification-pagination]').hidden = true;
                });
            } catch (error) {
                showError(surface, 'ثبت وضعیت خواندن ناموفق بود. دوباره تلاش کنید.');
                return;
            } finally {
                state.busy = false;
                submit.disabled = false;
                updateControls();
            }
            if (destination) {
                window.location.assign(destination);
                return;
            }
            await refresh();
        }
        surfaces.forEach(surface => {
            surface.element.addEventListener('submit', event => {
                const form = event.target;
                if (!form.matches('[data-notification-open], [data-notification-read-all]')) return;
                event.preventDefault();
                mutate(form, surface);
            });
            surface.element.addEventListener('click', event => {
                if (event.target.closest('[data-notification-retry]')) { refresh(); return; }
                const step = event.target.closest('[data-notification-step]');
                if (!step) return;
                event.preventDefault();
                if (state.busy || step.getAttribute('aria-disabled') === 'true') return;
                surface.page += Number(step.dataset.notificationStep);
                surface.element.querySelector('[data-notification-list]').scrollTop = 0;
                if (surface.element !== panel) {
                    const url = new URL(window.location.href);
                    url.searchParams.set('page', surface.page);
                    window.history.replaceState(null, '', url.href);
                }
                refresh();
            });
        });
        const schedule = () => {
            window.clearTimeout(state.timer);
            state.timer = window.setTimeout(async () => {
                await refresh();
                schedule();
            }, document.hidden ? 60000 : 30000);
        };
        bell.addEventListener('click', () => setPanelOpen(!panel.classList.contains('is-open')));
        document.getElementById('notifCloseBtn')?.addEventListener('click', () => setPanelOpen(false));
        backdrop?.addEventListener('click', () => setPanelOpen(false));
        document.addEventListener('click', event => {
            if (!backdrop && panel.classList.contains('is-open') && !panel.contains(event.target) && !bell.contains(event.target)) setPanelOpen(false);
        });
        document.addEventListener('keydown', event => {
            if (!panel.classList.contains('is-open')) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                setPanelOpen(false);
            } else if (event.key === 'Tab') {
                const focusable = Array.from(panel.querySelectorAll('button:not(:disabled), a[href]:not([aria-disabled="true"])')).filter(node => node.getClientRects().length);
                const first = focusable[0], last = focusable[focusable.length - 1];
                if (!first) return;
                if (event.shiftKey && (document.activeElement === first || !panel.contains(document.activeElement))) {
                    event.preventDefault(); last.focus();
                } else if (!event.shiftKey && (document.activeElement === last || !panel.contains(document.activeElement))) {
                    event.preventDefault(); first.focus();
                }
            }
        });
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) refresh();
            schedule();
        });
        window.addEventListener('pageshow', event => { if (event.persisted) refresh(); });
        refresh().finally(schedule);
    }

    function initMoneyInputs() {
        window.RialInput?.install();
    }

    function initJalaliDatepickers() {
        if (!window.jalaliDatepicker) {
            return;
        }

        const div = (first, second) => Math.trunc(first / second);
        const pad = value => String(value).padStart(2, '0');

        const gregorianToJalali = (year, month, day) => {
            const monthDays = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
            let jalaliYear = year <= 1600 ? 0 : 979;
            year -= year <= 1600 ? 621 : 1600;
            const adjustedYear = month > 2 ? year + 1 : year;
            let days = (365 * year) + div(adjustedYear + 3, 4) - div(adjustedYear + 99, 100)
                + div(adjustedYear + 399, 400) - 80 + day + monthDays[month - 1];
            jalaliYear += 33 * div(days, 12053);
            days %= 12053;
            jalaliYear += 4 * div(days, 1461);
            days %= 1461;
            if (days > 365) {
                jalaliYear += div(days - 1, 365);
                days = (days - 1) % 365;
            }
            const jalaliMonth = days < 186 ? 1 + div(days, 31) : 7 + div(days - 186, 30);
            const jalaliDay = 1 + (days < 186 ? days % 31 : (days - 186) % 30);
            return [jalaliYear, jalaliMonth, jalaliDay];
        };

        const jalaliToGregorian = (year, month, day) => {
            year += 1595;
            let days = -355668 + (365 * year) + div(year, 33) * 8
                + div((year % 33) + 3, 4) + day + (month < 7 ? (month - 1) * 31 : ((month - 7) * 30) + 186);
            let gregorianYear = 400 * div(days, 146097);
            days %= 146097;
            if (days > 36524) {
                gregorianYear += 100 * div(--days, 36524);
                days %= 36524;
                if (days >= 365) {
                    days++;
                }
            }
            gregorianYear += 4 * div(days, 1461);
            days %= 1461;
            if (days > 365) {
                gregorianYear += div(days - 1, 365);
                days = (days - 1) % 365;
            }
            let gregorianDay = days + 1;
            const monthLengths = [
                0, 31,
                ((gregorianYear % 4 === 0 && gregorianYear % 100 !== 0) || gregorianYear % 400 === 0) ? 29 : 28,
                31, 30, 31, 30, 31, 31, 30, 31, 30, 31,
            ];
            let gregorianMonth = 1;
            while (gregorianMonth <= 12 && gregorianDay > monthLengths[gregorianMonth]) {
                gregorianDay -= monthLengths[gregorianMonth++];
            }
            return [gregorianYear, gregorianMonth, gregorianDay];
        };

        const gregorianStringToJalali = value => {
            const match = ((value || '').split('T')[0] || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (!match) {
                return '';
            }
            const jalali = gregorianToJalali(Number(match[1]), Number(match[2]), Number(match[3]));
            return `${jalali[0]}/${pad(jalali[1])}/${pad(jalali[2])}`;
        };

        const jalaliStringToGregorian = value => {
            const match = (value || '').trim().match(/^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$/);
            if (!match) {
                return '';
            }
            const gregorian = jalaliToGregorian(Number(match[1]), Number(match[2]), Number(match[3]));
            return `${gregorian[0]}-${pad(gregorian[1])}-${pad(gregorian[2])}`;
        };

        const bindDateInput = element => {
            if (element.dataset.faDateBound === '1') {
                return;
            }
            element.dataset.faDateBound = '1';
            const dateTime = element.type === 'datetime-local';
            const initialGregorian = element.value || '';
            const initialTime = dateTime && initialGregorian.includes('T')
                ? initialGregorian.split('T')[1].slice(0, 5)
                : '';

            element.type = 'text';
            element.autocomplete = 'off';
            element.dir = 'ltr';
            element.setAttribute('data-jdp', '');
            if (!dateTime) {
                element.setAttribute('data-jdp-only-date', '');
            }
            if (initialGregorian) {
                const jalali = gregorianStringToJalali(initialGregorian);
                element.value = dateTime ? `${jalali} ${initialTime}`.trim() : jalali;
            }

            const form = element.closest('form');
            if (!form || form.dataset.faDateSubmitBound === '1') {
                return;
            }
            form.dataset.faDateSubmitBound = '1';
            form.addEventListener('submit', () => {
                form.querySelectorAll('input[data-fa-date-bound="1"]').forEach(dateInput => {
                    const raw = (dateInput.value || '').trim();
                    if (!raw) {
                        return;
                    }
                    const [datePart, timePart] = raw.split(' ');
                    const gregorianDate = jalaliStringToGregorian(datePart);
                    if (!gregorianDate) {
                        return;
                    }
                    dateInput.value = dateInput.dataset.faOriginalType === 'datetime-local' && timePart
                        ? `${gregorianDate}T${timePart}`
                        : gregorianDate;
                });
            });
        };

        document.querySelectorAll('input[type="date"], input[type="datetime-local"]').forEach(element => {
            element.dataset.faOriginalType = element.type;
            bindDateInput(element);
        });

        window.jalaliDatepicker.startWatch({
            minDate: 'attr',
            maxDate: 'attr',
            time: true,
        });
    }
})();
