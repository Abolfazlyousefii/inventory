(function (root) {
    'use strict';
    const latin = value => String(value ?? '').trim().replace(/[۰-۹]/g, c => '۰۱۲۳۴۵۶۷۸۹'.indexOf(c)).replace(/[٠-٩]/g, c => '٠١٢٣٤٥٦٧٨٩'.indexOf(c)).replace(/٫/g, '.');
    function quote(value, rate, margin = '30') {
        const text = latin(value);
        if (!/^\d{1,6}(?:\.\d{1,4})?$/.test(text)) throw Error('مبلغ مثبت با حداکثر چهار رقم اعشار وارد کنید.');
        const [whole, fraction = ''] = text.split('.');
        const units = BigInt(whole) * 10000n + BigInt(fraction.padEnd(4, '0'));
        if (units < 1n || units > 1000000000n || !Number.isInteger(rate) || rate < 1 || rate > 100000000) throw Error('مبلغ یوانی یا نرخ معتبر نیست.');
        const percent = latin(margin);
        if (!/^\+?(?:0|[1-9]\d?)(?:\.\d{1,2})?$/.test(percent)) throw Error('درصد سود باید بیشتر از صفر و حداکثر ۹۹ با دو رقم اعشار باشد.');
        const [wholePercent, decimalPercent = ''] = percent.replace(/^\+/, '').split('.');
        const basisPoints = BigInt(wholePercent) * 100n + BigInt(decimalPercent.padEnd(2, '0') || '0');
        if (basisPoints < 1n || basisPoints > 9900n) throw Error('درصد سود باید بیشتر از صفر و حداکثر ۹۹ باشد.');
        const denominator = 10000n - basisPoints;
        const base = units * BigInt(rate);
        const buy = (base + 5000n) / 10000n;
        const divisor = 10000n * denominator * 10000n;
        const sale = ((base * 10200n + divisor - 1n) / divisor) * 10000n;
        if (sale < 1n) throw Error('قیمت پس از گردکردن باید بیشتر از صفر باشد.');
        const micro = (units * 1020000n + denominator / 2n) / denominator;
        return { buy: Number(buy), sale: Number(sale), saleCny: `${micro / 1000000n}.${String(micro % 1000000n).padStart(6, '0')}`, units: Number(units), amount: text };
    }
    const api = { quote, latin };
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.AriyaCny = api;
})(typeof window === 'undefined' ? {} : window);
