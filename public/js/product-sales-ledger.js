(function () {
    'use strict';
    const field = document.getElementById('sl-customer');
    const $ = window.jQuery;
    if (!field || !$ || !$.fn.select2) return;
    const fa = value => String(value).replace(/[0-9]/g, digit => '۰۱۲۳۴۵۶۷۸۹'[digit]);
    $(field).select2({
        width: '100%',
        dir: 'rtl',
        placeholder: 'نام، موبایل یا کد مشتری…',
        allowClear: true,
        minimumInputLength: 2,
        dropdownCssClass: 'sl-customer-dropdown',
        language: { removeAllItems: () => 'پاک‌کردن مشتری',
            inputTooShort: () => 'حداقل ۲ کاراکتر وارد کنید',
            searching: () => 'در حال جست‌وجو…',
            noResults: () => 'مشتری پیدا نشد',
            errorLoading: () => 'جست‌وجو انجام نشد؛ دوباره تلاش کنید'
        },
        ajax: {
            url: field.dataset.searchUrl,
            dataType: 'json',
            delay: 250,
            data: params => ({lookup: 'customers', q: params.term || ''}),
            processResults: response => ({results: (response.results || []).map(customer => ({id: customer.id, text: fa(customer.text)}))})
        }
    });
})();
