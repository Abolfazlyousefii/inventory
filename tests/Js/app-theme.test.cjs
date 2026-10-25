const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../public/js/app-theme.js'), 'utf8');
function boot(saved, blocked = false) {
    const attrs = {};
    const listeners = {};
    const context = {document: {documentElement: {setAttribute: (k, v) => attrs[k] = v}, addEventListener: (k, f) => listeners[k] = f},
        window: {localStorage: {getItem() {if (blocked) throw Error(); return saved;}}, addEventListener: (k, f) => listeners[k] = f}};
    vm.runInNewContext(source, context);
    return {attrs, listeners};
}
test('applies the saved theme before DOM readiness, defaults to light', () => {
    assert.equal(boot('dark').attrs['data-app-theme'], 'dark');
    assert.equal(boot('dark').attrs['data-bs-theme'], 'dark');
    assert.equal(boot('invalid').attrs['data-app-theme'], 'light');
    assert.equal(boot(null, true).attrs['data-app-theme'], 'light');
});
const compatibility = fs.readFileSync(require('node:path').join(__dirname, '../../public/js/app-theme-compat.js'), 'utf8');
const context = {module: {exports: {}}};
vm.runInNewContext(compatibility, context);
const {convert,scope,mapColor,luminance,declarations} = context.module.exports;
test('every selector is scoped, including root, pseudo classes and quoted commas', () => {
    assert.equal(scope('body, .card'), 'html[data-app-theme="dark"] body,html[data-app-theme="dark"] .card');
    assert.equal(scope(':root, html, :is(.a,.b), [data-x="a,b"]'), 'html[data-app-theme="dark"],html[data-app-theme="dark"],html[data-app-theme="dark"] :is(.a,.b),html[data-app-theme="dark"] [data-x="a,b"]');
});
test('role aliases preserve nested var fallbacks and do not touch embedded SVG images', () => {
    assert.equal(convert('var(--brand, var(--other, #fff))','text'), 'var(--aria-text-brand,var(--brand, var(--aria-text-other,var(--other, rgba(255,255,255,1)))))');
    const image = 'url("data:image/svg+xml,%3Csvg fill=\'#fff\'%3E")';
    assert.equal(convert(image,'surface'), image);
    assert.equal(convert('transparent','surface'), 'transparent');
    assert.equal(convert('var(--blue)','text'), 'var(--aria-text-blue,var(--blue))');
    assert.equal(convert('var(--bg-white)','surface'), 'var(--aria-surface-bg-white,var(--bg-white))');
    assert.equal(convert('rgba(0,0,0,0)','surface'), 'rgba(0,0,0,0)');
});
test('dark text and status hues remain readable on converted pale surfaces', () => {
    const rgb = value => value.match(/[\d.]+/g).slice(0,3).map(Number);
    const background = rgb(mapColor('#ffffff','surface'));
    for (const color of ['#16354f','#000000','#71808c','#16805c','#c54b4b','#c98216']) {
        const contrast = (luminance(rgb(mapColor(color,'text')))+.05)/(luminance(background)+.05);
        assert.ok(contrast >= 4.5, `${color}: ${contrast}`);
    }
});
test('shorthand color variables survive CSSOM empty longhands without copying dimensions', () => {
    const values = {'background':'var(--panel)', 'background-color':'', 'background-image':'', 'width':'200px', '--panel':'#fff'};
    const style = ['background-color','background-image','width','--panel'];
    style.getPropertyValue = key => values[key] || '';
    style.getPropertyPriority = () => '';
    const css = declarations(style);
    assert.ok(css.includes('background:var(--aria-surface-panel,var(--panel))'));
    assert.ok(css.includes('--aria-surface-panel:rgba(24,35,49,1)'));
    assert.ok(!css.includes('width:'));
    assert.ok(!css.includes('background-color:;'));
});
test('toggle is usable without storage, updates its accessible name and accepts cross-tab changes', () => {
    for (const blocked of [false,true]) {
        const attrs = {}, buttonAttrs = {}, listeners = {}, clicks = {};
        let saved = null;
        const context = {document: {
            documentElement: {setAttribute: (k,v) => attrs[k]=v},
            addEventListener: (k,f) => listeners[k]=f,
            getElementById: () => ({setAttribute:(k,v)=>buttonAttrs[k]=v,addEventListener:(k,f)=>clicks[k]=f})
        },window:{localStorage:{getItem:()=>saved,setItem:(k,v)=>{if(blocked) throw Error(); saved=v;}},addEventListener:(k,f)=>listeners[k]=f}};
        vm.runInNewContext(source,context);
        listeners.DOMContentLoaded();
        clicks.click();
        assert.equal(attrs['data-app-theme'],'dark');
        assert.equal(buttonAttrs['aria-pressed'],'true');
        assert.equal(buttonAttrs['aria-label'],'فعال کردن حالت روز');
        if (!blocked) assert.equal(saved,'dark');
        clicks.click();
        assert.equal(attrs['data-app-theme'],'light');
        listeners.storage({key:'ariya.display-theme.v1',newValue:'dark'});
        assert.equal(attrs['data-app-theme'],'dark');
        listeners.storage({key:'unrelated',newValue:'light'});
        assert.equal(attrs['data-app-theme'],'dark');
    }
});
