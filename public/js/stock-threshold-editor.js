(() => {
    const root = document.querySelector('[data-stock-threshold-editor]');
    if (!root) return;
    const type = document.getElementById('threshold-type'), target = document.getElementById('threshold-target');
    const input = document.getElementById('threshold-search'), label = document.getElementById('threshold-search-label');
    const measure = document.getElementById('threshold-measure'), status = document.getElementById('threshold-search-status');
    const categories = target.innerHTML;
    let timer, sequence = 0;
    type.addEventListener('change', () => {
        sequence++; clearTimeout(timer); input.value = ''; status.textContent = '';
        label.hidden = type.value === 'category';
        target.innerHTML = type.value === 'category' ? categories : '<option value="">ابتدا جست‌وجو کنید</option>';
        measure.value = type.value === 'variant' ? 'variant' : 'product';
        measure.querySelector('[value="product"]').disabled = type.value === 'variant';
    });
    input.addEventListener('input', () => {
        const seq = ++sequence; clearTimeout(timer); target.innerHTML = '<option value="">انتخاب کنید</option>';
        if (input.value.trim().length < 2) { status.textContent = 'حداقل دو حرف وارد کنید'; return; }
        timer = setTimeout(async () => {
            status.textContent = 'در حال جست‌وجو…';
            try {
                const url = new URL(root.dataset.searchUrl, location.origin);
                url.searchParams.set('type', type.value); url.searchParams.set('q', input.value.trim());
                const response = await fetch(url, {headers: {'Accept': 'application/json'}});
                if (!response.ok) throw new Error();
                const result = await response.json(); if (seq !== sequence) return;
                result.items.forEach(item => target.add(new Option(item.name, item.id)));
                status.textContent = result.items.length ? `${result.items.length} نتیجه؛ مورد مناسب را انتخاب کنید` : 'نتیجه‌ای یافت نشد';
            } catch (_) { if (seq === sequence) status.textContent = 'جست‌وجو انجام نشد؛ دوباره تلاش کنید.'; }
        }, 300);
    });
})();
