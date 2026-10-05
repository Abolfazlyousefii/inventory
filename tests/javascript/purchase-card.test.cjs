const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const {test} = require('node:test');
const source = fs.readFileSync(process.env.PURCHASE_VIEW || 'resources/views/purchases/create.blade.php', 'utf8');
function extract(start, end) {
  const offset = source.indexOf(start);
  assert(offset >= 0, `Missing ${start}`);
  return source.slice(offset, source.indexOf(end, offset));
}
const context = vm.createContext({
  realVariantsForProduct: product => product.variants,
  escapeHtml: value => String(value ?? '').replaceAll('<', '&lt;'),
  variantSearchText: (_, variant) => variant.name,
  normalizePurchaseSearchText: value => value,
  variantLabel: variant => variant.name,
  formatFa: value => String(value),
  formatMoney: value => `${value} ریال`,
  categoryName: () => 'آداپتور',
});
vm.runInContext(extract('function currentSalePriceMessage(', 'function calculateDiscount('), context);
vm.runInContext(extract('function productCardTemplate(', 'async function addProductCard('), context);
test('purchase card renders real variants, missing prices and blocked legacy rows', () => {
  const html = context.productCardTemplate({id: 1121, name: 'شارژر 67w', code: '051059', variants: [
    {id: 19409, name: 'مشکی', sell_price: 0},
    {id: 19410, name: 'سفید', sell_price: 6950000},
    {id: 19408, name: 'قدیمی', purchase_blocked: true},
  ]});
  assert(html.includes('data-product-id="1121"'));
  assert(html.includes('قیمت فروش فعلی موجود نیست'));
  assert(html.includes('قیمت فروش فعلی: 6950000 ریال'));
  assert(html.includes('data-purchase-blocked="1"'));
  assert(html.includes('data-qty value="" disabled'));
});
