(function (root) {
    'use strict';
    function normalize(value) {
        return String(value ?? '').toLowerCase()
            .replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/ۀ/g, 'ه')
            .replace(/[۰-۹]/g, digit => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)))
            .replace(/[٠-٩]/g, digit => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)))
            .replace(/[\s\u200c\u00a0_\-/]+/g, ' ').replace(/([a-z])\s+(?=[0-9])/g, '$1').trim();
    }
    function matches(query, fields) {
        const terms = normalize(query).split(' ').filter(Boolean);
        const values = fields.map(value => normalize(value).replace(/ /g, ''));
        return terms.every(term => values.some(value => value.includes(term)));
    }
    root.InventoryVariantSearch = Object.freeze({matches});
})(window);
