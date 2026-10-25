/* Color-only bridge for legacy page styles. Never changes element styles, layout,
 * form values, images, or source stylesheets. Print does not use these rules. */
(() => {
    'use strict';
    const colorPattern = /#[\da-f]{3,8}\b|rgba?\([^)]*\)|(?<![\w-])(?:white|black|whitesmoke|ivory|navy|red|green|blue|gray|grey|silver)(?![\w-])/gi;
    const named = {white:'#ffffff',black:'#000000',whitesmoke:'#f5f5f5',ivory:'#fffff0',navy:'#000080',red:'#ff0000',green:'#008000',blue:'#0000ff',gray:'#808080',grey:'#808080',silver:'#c0c0c0'};
    const parse = value => {
        value = named[value.toLowerCase()] || value;
        if (value[0] === '#') {
            let hex = value.slice(1);
            if (hex.length === 3 || hex.length === 4) hex = [...hex].map(x => x + x).join('');
            if (hex.length !== 6 && hex.length !== 8) return null;
            return [parseInt(hex.slice(0,2),16),parseInt(hex.slice(2,4),16),parseInt(hex.slice(4,6),16),hex.length === 8 ? parseInt(hex.slice(6),16)/255 : 1];
        }
        const parts = value.match(/[\d.]+%?/g);
        if (!parts || parts.length < 3) return null;
        return parts.map((x,i) => x.endsWith('%') ? parseFloat(x)*(i === 3 ? .01 : 2.55) : +x).concat(parts.length === 3 ? [1] : []);
    };
    const luminance = rgb => rgb.slice(0,3).map(x => { x /= 255; return x <= .04045 ? x/12.92 : ((x+.055)/1.055)**2.4; }).reduce((a,x,i) => a+x*[.2126,.7152,.0722][i],0);
    const mapColor = (value, role) => {
        const rgb = parse(value);
        if (!rgb || rgb[3] === 0) return value;
        const [r,g,b,a] = rgb;
        const light = luminance(rgb);
        const spread = Math.max(r,g,b)-Math.min(r,g,b);
        let mapped;
        if (role === 'text') {
            // Retain bright text; lift dark ink and status hues against navy surfaces.
            mapped = light > .65 ? rgb.slice(0,3) : rgb.slice(0,3).map(x => Math.round(x*.38+158));
        } else if (role === 'border') {
            mapped = spread < 22 ? [62,82,102] : rgb.slice(0,3).map(x => Math.round(x*.27+43));
        } else {
            // Pale panels retain a hint of their original hue. Brand buttons stay dark.
            mapped = light > .55 ? [r,g,b].map((x,i) => Math.round([24,35,49][i]+(x-Math.min(r,g,b))*.13))
                : light > .12 ? [r,g,b].map(x => Math.round(x*.58)) : [r,g,b];
        }
        return `rgba(${mapped.join(',')},${a})`;
    };
    const roleFor = property => /^(?:color|fill|stroke|text-decoration-color|caret-color)$/.test(property) ? 'text'
        : /border|outline|shadow|column-rule/.test(property) ? 'border' : 'surface';
    const colorProperty = property => /^(?:color|background(?:-color|-image)?|border(?:-(?:top|right|bottom|left))?(?:-color)?|outline(?:-color)?|box-shadow|text-shadow|text-decoration-color|caret-color|fill|stroke|column-rule-color)$/.test(property);
    // CSSOM expands shorthand variables to empty longhands. Read the original
    // shorthand as well, otherwise background:var(--panel) silently disappears.
    const properties = style => new Set([...style,'background','border','border-top','border-right','border-bottom','border-left','outline']);
    const convert = (value, role) => {
        const urls = [];
        let source = value.replace(/url\((?:"[^"]*"|'[^']*'|[^)])*\)/gi, url => `__ARIA_IMAGE_${urls.push(url)-1}__`);
        source = source.replace(colorPattern, token => mapColor(token, role));
        function vars(text) {
            let result = '', cursor = 0;
            while (true) {
                const start = text.indexOf('var(', cursor);
                if (start < 0) return result + text.slice(cursor);
                result += text.slice(cursor, start);
                let depth = 1, end = start+4;
                for (; end < text.length && depth; end++) { if(text[end] === '(') depth++; if(text[end] === ')') depth--; }
                if (depth) return result + text.slice(start);
                const inner = text.slice(start+4,end-1);
                const name = inner.match(/^\s*(--[\w-]+)/)?.[1];
                result += name ? `var(--aria-${role}-${name.slice(2)},var(${vars(inner)}))` : text.slice(start,end);
                cursor = end;
            }
        }
        return vars(source).replace(/__ARIA_IMAGE_(\d+)__/g, (_, index) => urls[+index]);
    };
    const declarations = style => {
        let result = '';
        for (const property of properties(style)) {
            const value = style.getPropertyValue(property);
            if (!value) continue;
            const priority = style.getPropertyPriority(property) ? ' !important' : '';
            if (property === 'color-scheme') result += `color-scheme:dark${priority};`;
            else if (property.startsWith('--')) {
                // Shared semantic tokens are deliberately authored in app-theme.css.
                if (property.startsWith('--app-')) continue;
                // Only alias color variables; sizes, URLs, numbers and content remain intact.
                colorPattern.lastIndex = 0;
                if (!colorPattern.test(value) && !value.includes('var(')) continue;
                for (const role of ['text','surface','border']) result += `--aria-${role}-${property.slice(2)}:${convert(value,role)}${priority};`;
            } else if (colorProperty(property)) result += `${property}:${convert(value,roleFor(property))}${priority};`;
        }
        return result;
    };
    const scope = selectors => {
        const parts = [];
        let start = 0, depth = 0, quote = '';
        for (let i=0;i<selectors.length;i++) {
            const char = selectors[i];
            if (quote) { if(char === quote && selectors[i-1] !== '\\') quote = ''; continue; }
            if (char === '"' || char === "'") quote = char;
            if (char === '(' || char === '[') depth++;
            if (char === ')' || char === ']') depth--;
            if (char === ',' && depth === 0) { parts.push(selectors.slice(start,i).trim()); start=i+1; }
        }
        parts.push(selectors.slice(start).trim());
        return parts.map(selector => /^(?:html\b|:root\b)/.test(selector)
            ? selector.replace(/^(?:html|:root)/,'html[data-app-theme="dark"]')
            : `html[data-app-theme="dark"] ${selector}`).join(',');
    };
    const rules = (list, depth = 0) => {
        if (depth > 8) return '';
        let output = '';
        for (const rule of list) {
            if (rule.type === 1) {
                const body = declarations(rule.style);
                if (body) output += `${scope(rule.selectorText)}{${body}}`;
            } else if (rule.type === 3) {
                // Some page themes are imported inside a Blade style block.
                const media = rule.media?.mediaText;
                if (media && /print/i.test(media)) continue;
                try {
                    const imported = rules(rule.styleSheet.cssRules,depth+1);
                    output += media ? `@media ${media}{${imported}}` : imported;
                } catch (_) { /* A cross-origin import cannot be inspected. */ }
            } else if (rule.type === 4 && !/print/i.test(rule.conditionText)) output += `@media ${rule.conditionText}{${rules(rule.cssRules,depth+1)}}`;
            else if (rule.type === 12) output += `@supports ${rule.conditionText}{${rules(rule.cssRules,depth+1)}}`;
        }
        return output;
    };
    if (typeof module !== 'undefined' && module.exports) module.exports = {mapColor,convert,luminance,scope,declarations,rules};
    if (typeof document === 'undefined') return;
    document.addEventListener('DOMContentLoaded', () => {
        const output = document.createElement('style');
        output.id = 'app-theme-page-colors';
        output.media = 'screen';
        document.head.append(output);
        let timer;
        let inlineSequence = 0;
        let sheetCache = new WeakMap();
        const refresh = () => {
            let css = '';
            for (const sheet of document.styleSheets) {
                if (sheet.ownerNode === output || sheet.ownerNode?.id === 'app-theme-tokens' || sheet.disabled) continue;
                if (sheet.href && (!sheet.href.startsWith(location.origin) || /\/lib\/(?!select2\.min|jalalidatepicker\.min)/.test(sheet.href) || /\/(?:app-theme|admin-theme)\.css/.test(sheet.href))) continue;
                if (sheet.media?.mediaText && /print/i.test(sheet.media.mediaText)) continue;
                try {
                    let content = sheetCache.get(sheet);
                    if (content === undefined) { content = rules(sheet.cssRules); sheetCache.set(sheet,content); }
                    css += sheet.media?.mediaText ? `@media ${sheet.media.mediaText}{${content}}` : content;
                } catch (_) { /* Cross-origin or unavailable styles are handled by shared tokens. */ }
            }
            for (const element of document.querySelectorAll('[style]')) {
                if (element.namespaceURI !== 'http://www.w3.org/1999/xhtml') continue;
                let body = '';
                for (const property of properties(element.style)) {
                    if (property.startsWith('--')) {
                        const value = element.style.getPropertyValue(property);
                        colorPattern.lastIndex = 0;
                        if (colorPattern.test(value) || value.includes('var(')) {
                            for (const role of ['text','surface','border']) body += `--aria-${role}-${property.slice(2)}:${convert(value,role)} !important;`;
                        }
                        continue;
                    }
                    if (!colorProperty(property)) continue;
                    const value = element.style.getPropertyValue(property);
                    if (!value) continue;
                    colorPattern.lastIndex = 0;
                    if (!colorPattern.test(value)) continue;
                    body += `${property}:${convert(value,roleFor(property))} !important;`;
                }
                if (!body) continue;
                if (!element.dataset.ariaThemeInline) element.dataset.ariaThemeInline = String(++inlineSequence);
                css += `html[data-app-theme="dark"] [data-aria-theme-inline="${element.dataset.ariaThemeInline}"]{${body}}`;
            }
            if (output.textContent !== css) output.textContent = css;
        };
        const schedule = () => { clearTimeout(timer); timer = setTimeout(refresh, 30); };
        const observer = new MutationObserver(records => {
            const sourceStylesChanged = records.some(record => record.target !== output && !output.contains(record.target) &&
                (record.target.nodeName === 'STYLE' || record.target.parentElement?.nodeName === 'STYLE' ||
                    [...record.addedNodes,...record.removedNodes].some(node => node.nodeType === 1 && (node.matches('style,link[rel="stylesheet"]') || node.querySelector('style,link[rel="stylesheet"]')))));
            if (sourceStylesChanged) sheetCache = new WeakMap();
            if (records.some(record => record.target !== output && !output.contains(record.target) &&
                (sourceStylesChanged || record.type === 'attributes' || [...record.addedNodes,...record.removedNodes].some(node => node.nodeType === 1 && (node.matches('style,link[rel="stylesheet"],[style]') || node.querySelector('style,link[rel="stylesheet"],[style]')))))) schedule();
        });
        observer.observe(document.documentElement,{childList:true,subtree:true,characterData:true,attributes:true,attributeFilter:['style','disabled','media']});
        document.addEventListener('load', event => { if(event.target.matches?.('style,link[rel="stylesheet"]')) { sheetCache = new WeakMap(); schedule(); } },true);
        window.addEventListener('load', () => { sheetCache = new WeakMap(); schedule(); },{once:true});
        refresh();
    });
})();
