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
        if (!panel || !bell) {
            return;
        }

        const countUrl = document.body.dataset.notificationsCountUrl;
        const latestUrl = document.body.dataset.notificationsLatestUrl;
        const readAllUrl = document.body.dataset.notificationsReadAllUrl;
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const faNumber = value => new Intl.NumberFormat('fa-IR').format(Number(value || 0));
        const state = {
            timer: null,
            countRequest: null,
            listController: null,
            listSequence: 0,
        };

        const escapeHtml = value => String(value ?? '').replace(
            /[&<>'"]/g,
            character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[character]
        );

        const typeLabel = type => ({
            preinvoice_submitted: 'پیش‌فاکتور',
            preinvoice_finance_approved: 'مالی',
            preinvoice_returned_to_sales: 'فوری',
            preinvoice_cancelled_by_finance: 'فوری',
            invoice_pending_finance_reapproval: 'مالی',
            invoice_finance_reapproved: 'مالی',
            invoice_ready_to_ship: 'ارسال',
            invoice_shipped: 'ارسال',
            invoice_created_for_collection: 'انبار',
        })[type] || 'اعلان';

        const priorityClass = priority => {
            if (priority === 'urgent') {
                return 'notif-card--urgent';
            }
            if (priority === 'important') {
                return 'notif-card--important';
            }
            return '';
        };

        const setPanelOpen = open => {
            panel.classList.toggle('is-open', open);
            panel.setAttribute('aria-hidden', open ? 'false' : 'true');
            bell.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                loadNotificationList();
            } else {
                state.listController?.abort();
            }
        };

        const updateNotificationCount = count => {
            const badge = document.getElementById('notifBadge');
            const unreadText = document.getElementById('notifUnreadText');
            if (badge) {
                badge.textContent = count > 99 ? '+99' : faNumber(count);
                badge.title = `${faNumber(count)} اعلان خوانده‌نشده`;
                badge.classList.toggle('d-none', count <= 0);
            }
            if (unreadText) {
                unreadText.textContent = `${faNumber(count)} خوانده‌نشده`;
            }
        };

        async function refreshNotificationCount() {
            if (!countUrl || state.countRequest) {
                return;
            }

            state.countRequest = new AbortController();
            const controller = state.countRequest;
            try {
                const response = await fetch(countUrl, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!response.ok) {
                    throw new Error('Notification count request failed');
                }

                const count = Math.max(0, Number((await response.json()).count || 0));
                updateNotificationCount(count);

                if (panel.classList.contains('is-open')) {
                    loadNotificationList();
                }
            } catch (error) {
                if (error.name !== 'AbortError') {
                    console.debug('Notification count refresh failed.');
                }
            } finally {
                if (state.countRequest === controller) {
                    state.countRequest = null;
                }
            }
        }

        async function loadNotificationList() {
            if (!latestUrl || !panel.classList.contains('is-open')) {
                return;
            }

            state.listController?.abort();
            const controller = new AbortController();
            const sequence = ++state.listSequence;
            state.listController = controller;
            const list = document.getElementById('notifList');
            if (list) {
                list.innerHTML = '<div class="notif-empty"><span class="notif-empty__dot"></span>در حال دریافت اعلان‌ها...</div>';
            }

            try {
                const response = await fetch(latestUrl, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!response.ok) {
                    throw new Error('Notification list request failed');
                }
                const notifications = await response.json();
                if (sequence !== state.listSequence || !panel.classList.contains('is-open') || !list) {
                    return;
                }
                if (notifications.length === 0) {
                    list.innerHTML = '<div class="notif-empty"><span class="notif-empty__dot"></span>اعلانی برای نمایش وجود ندارد.</div>';
                    return;
                }
                list.innerHTML = notifications.map(notification => {
                    const unread = !notification.read_at;
                    const url = notification.open_url || `/notifications/${notification.id}/open`;
                    return `<a class="notif-card ${unread ? 'notif-card--unread' : ''} ${priorityClass(notification.priority || 'normal')}" href="${escapeHtml(url)}">
                        <span class="notif-card__dot"></span>
                        <span class="notif-card__body">
                            <span class="notif-card__top">
                                <strong class="notif-card__title">${escapeHtml(notification.title)}</strong>
                                <span class="notif-card__badge">${escapeHtml(typeLabel(notification.type))}</span>
                            </span>
                            <span class="notif-card__message">${escapeHtml(notification.message || '')}</span>
                            <span class="notif-card__meta">
                                <span>${escapeHtml(notification.created_at_human || notification.created_at || '')}</span>
                                <span>مشاهده</span>
                            </span>
                        </span>
                    </a>`;
                }).join('');
            } catch (error) {
                if (error.name !== 'AbortError' && list && panel.classList.contains('is-open')) {
                    list.innerHTML = '<div class="notif-empty">دریافت اعلان‌ها با خطا روبه‌رو شد.</div>';
                }
            } finally {
                if (state.listController === controller) {
                    state.listController = null;
                }
            }
        }

        const schedule = () => {
            window.clearTimeout(state.timer);
            state.timer = window.setTimeout(async () => {
                await refreshNotificationCount();
                schedule();
            }, document.hidden ? 60000 : 30000);
        };

        bell.addEventListener('click', () => setPanelOpen(!panel.classList.contains('is-open')));
        document.getElementById('notifCloseBtn')?.addEventListener('click', () => setPanelOpen(false));
        document.addEventListener('click', event => {
            if (panel.classList.contains('is-open') && !panel.contains(event.target) && !bell.contains(event.target)) {
                setPanelOpen(false);
            }
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && panel.classList.contains('is-open')) {
                event.preventDefault();
                setPanelOpen(false);
            }
        });
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                refreshNotificationCount();
            }
            schedule();
        });

        document.getElementById('notifReadAllBtn')?.addEventListener('click', async () => {
            if (!readAllUrl) {
                return;
            }
            try {
                const response = await fetch(readAllUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                if (!response.ok) {
                    throw new Error('Read-all request failed');
                }
                updateNotificationCount(0);
                await loadNotificationList();
            } catch (error) {
                const list = document.getElementById('notifList');
                if (list) {
                    list.insertAdjacentHTML('afterbegin', '<div class="alert alert-danger py-2 mb-2">خواندن اعلان‌ها با خطا روبه‌رو شد.</div>');
                }
            }
        });

        refreshNotificationCount().finally(schedule);
    }

    function initMoneyInputs() {
        const format = element => {
            const raw = (element.value || '').replace(/[^\d]/g, '');
            element.value = raw.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        };
        document.querySelectorAll('input.money').forEach(format);
        document.addEventListener('input', event => {
            if (event.target?.classList.contains('money')) {
                format(event.target);
            }
        });
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
