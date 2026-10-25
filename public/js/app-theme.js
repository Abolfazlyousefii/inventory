/* Runs in the head: apply the preference before the first paint. */
(() => {
    'use strict';
    const key = 'ariya.display-theme.v1';
    const root = document.documentElement;
    let theme = 'light';
    try { if (window.localStorage.getItem(key) === 'dark') theme = 'dark'; } catch (_) { /* Optional browser storage. */ }
    const apply = value => {
        theme = value === 'dark' ? 'dark' : 'light';
        root.setAttribute('data-app-theme', theme);
        root.setAttribute('data-bs-theme', theme);
    };
    apply(theme);
    document.addEventListener('DOMContentLoaded', () => {
        const button = document.getElementById('appThemeToggle');
        const updateButton = () => {
            if (!button) return;
            const label = theme === 'dark' ? 'فعال کردن حالت روز' : 'فعال کردن حالت شب';
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
            button.setAttribute('aria-pressed', String(theme === 'dark'));
        };
        updateButton();
        button?.addEventListener('click', () => {
            apply(theme === 'dark' ? 'light' : 'dark');
            try { window.localStorage.setItem(key, theme); } catch (_) { /* Still usable without storage. */ }
            updateButton();
        });
        window.addEventListener('storage', event => {
            if (event.key === key || event.key === null) { apply(event.newValue); updateButton(); }
        });
    });
})();
