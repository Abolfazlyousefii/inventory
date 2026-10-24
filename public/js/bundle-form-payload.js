(() => {
    'use strict';
    const bound = new WeakSet();
    function attach(form, fields) {
        if (!form || bound.has(form)) return;
        bound.add(form);
        form.addEventListener('formdata', event => {
            const data = event.formData;
            const payload = Object.fromEntries(fields.map(field => [field, field === 'components' ? [] : {}]));
            for (const [name, value] of [...data.entries()]) {
                const match = /^(components|allocations|expected_allocations)\[(\d+)\](?:\[([a-z_]+)\])?$/.exec(name);
                if (!match || !fields.includes(match[1])) continue;
                const [, field, index, key] = match;
                if (key) {
                    payload[field][index] ??= {};
                    payload[field][index][key] = value;
                } else payload[field][index] = value;
                data.delete(name);
            }
            data.set('bundle_form_payload', JSON.stringify(payload));
        });
    }
    window.BundleFormPayload = {attach};
    const bind = () => document.querySelectorAll('form[data-bundle-payload]').forEach(form => attach(form, form.dataset.bundlePayload.split(' ')));
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
    else bind();
})();
