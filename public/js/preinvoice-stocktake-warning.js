(function (root) {
    'use strict';

    root.PreinvoiceStocktakeWarning = {
        render(warning) {
            const panel = document.getElementById('preinvoiceStocktakeWarning');
            const message = document.getElementById('preinvoiceStocktakeWarningMessage');
            const items = document.getElementById('preinvoiceStocktakeWarningItems');
            if (!panel || !message || !items) return;

            // The latest server response replaces the initial document-specific list.
            for (const initial of document.querySelectorAll('[data-count-freeze-order-items]')) initial.hidden = true;

            const text = typeof warning?.message === 'string' ? warning.message.trim() : '';
            message.textContent = text;
            items.replaceChildren();
            if (text && Array.isArray(warning.blocked_product_names)) {
                for (const name of new Set(warning.blocked_product_names.filter(name => typeof name === 'string' && name.trim()))) {
                    const item = document.createElement('li');
                    item.textContent = name;
                    items.append(item);
                }
            }
            panel.hidden = text === '';
        },
    };
})(globalThis);
