(function (root) {
    'use strict';

    const fields = [
        ['product_id', 'شناسه کالا'],
        ['product_code', 'کد کالا'],
        ['product_sku', 'SKU کالا'],
        ['product_name', 'نام کالا'],
        ['category_name', 'دسته‌بندی'],
        ['variant_id', 'شناسه تنوع'],
        ['variant_code', 'کد تنوع / بارکد'],
        ['variant_name', 'نام تنوع'],
        ['brand_name', 'برند'],
        ['model_name', 'مدل'],
        ['color_name', 'رنگ'],
        ['variety_name', 'طرح / نوع'],
        ['stock', 'موجودی (عدد)'],
        ['reserved', 'رزروشده (عدد)'],
        ['sell_price_rial', 'قیمت فروش (ریال)'],
        ['sales_enabled', 'مجاز برای فروش'],
    ];

    // Excel formula injection defense for product names, codes and free text.
    // Do not change numeric quantities to strings; they must stay summable.
    function cell(value) {
        if (typeof value === 'boolean') return value ? 'بله' : 'خیر';
        if (typeof value === 'number' && Number.isFinite(value)) return String(value);
        let text = String(value ?? '').replace(/[\u0000-\u001f\u007f]/g, ' ').trim();
        if (/^[=+\-@\t]/.test(text)) text = "'" + text;
        return '"' + text.replace(/"/g, '""') + '"';
    }

    function header() {
        return '\ufeffsep=,\r\n' + fields.map(([, title]) => cell(title)).join(',') + '\r\n';
    }

    function rowsCsv(rows) {
        return rows.map(row => fields.map(([name]) => cell(row[name])).join(',') + '\r\n').join('');
    }

    const api = {header, rowsCsv, cell};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (root) root.ProductStockCsv = api;
})(typeof window !== 'undefined' ? window : null);
