(function (root) {
    'use strict';
    const latin = value => String(value ?? '').trim().replace(/[۰-۹]/g, c => '۰۱۲۳۴۵۶۷۸۹'.indexOf(c)).replace(/[٠-٩]/g, c => '٠١٢٣٤٥٦٧٨٩'.indexOf(c)).replace(/٫/g, '.');
    function quote(value, rate) {
        const text = latin(value);
        if (!/^\d{1,6}(?:\.\d{1,4})?$/.test(text)) throw Error('مبلغ مثبت با حداکثر چهار رقم اعشار وارد کنید.');
        const [whole, fraction = ''] = text.split('.');
        const units = BigInt(whole) * 10000n + BigInt(fraction.padEnd(4, '0'));
        if (units < 1n || units > 1000000000n || !Number.isInteger(rate) || rate < 1 || rate > 100000000) throw Error('مبلغ یوانی یا نرخ معتبر نیست.');
        const base = units * BigInt(rate);
        const buy = (base + 5000n) / 10000n;
        const divisor = 10000n * 70n * 10000n;
        const sale = ((base * 102n + divisor / 2n) / divisor) * 10000n;
        if (sale < 1n) throw Error('قیمت پس از گردکردن باید بیشتر از صفر باشد.');
        const micro = (units * 10200n + 35n) / 70n;
        return { buy: Number(buy), sale: Number(sale), saleCny: `${micro / 1000000n}.${String(micro % 1000000n).padStart(6, '0')}`, units: Number(units), amount: text };
    }
    const api = { quote, latin };
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.AriyaCny = api;
})(typeof window === 'undefined' ? {} : window);
