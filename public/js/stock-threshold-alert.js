(() => {
    const dialog = document.getElementById('stockThresholdDialog');
    if (!dialog) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!csrf) return;

    const userId = dialog.dataset.userId || 'unknown';
    const lastCheckKey = `stock-threshold:last-check:${userId}`;
    const countKey = `stock-threshold:last-count:${userId}`;
    const cooldownMs = 5 * 60 * 1000;
    let running = false;

    const updateCount = count => {
        document.querySelectorAll('[data-stock-threshold-count]')
            .forEach(el => el.textContent = Number(count || 0).toLocaleString('fa-IR'));
    };

    const cachedCount = Number(localStorage.getItem(countKey));
    if (Number.isFinite(cachedCount)) updateCount(cachedCount);

    document.getElementById('stockThresholdClose')
        ?.addEventListener('click', () => dialog.close());

    async function check() {
        if (running || document.hidden || dialog.open) return;

        const lastCheck = Number(localStorage.getItem(lastCheckKey) || 0);
        if (Date.now() - lastCheck < cooldownMs) return;

        running = true;
        localStorage.setItem(lastCheckKey, String(Date.now()));

        try {
            const summaryResponse = await fetch(dialog.dataset.summaryUrl, {
                headers: {'Accept': 'application/json'},
            });
            if (!summaryResponse.ok) throw new Error('summary_failed');

            const summary = await summaryResponse.json();
            const count = Number(summary.count || 0);
            localStorage.setItem(countKey, String(count));
            updateCount(count);

            if (!count) return;

            const response = await fetch(dialog.dataset.dailyUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
            });
            if (!response.ok) throw new Error('daily_failed');

            const result = await response.json();
            if (!result.show) return;

            document.getElementById('stockThresholdMessage').textContent =
                `${Number(result.count).toLocaleString('fa-IR')} کالا به آستانه موجودی رسیده‌اند. در مجموع ${Number(result.rows).toLocaleString('fa-IR')} هشدار کالا و تنوع برای بررسی تأمین وجود دارد.`;
            dialog.showModal();
        } catch (_) {
            // Allow a retry on the next visible page instead of suppressing checks for five minutes.
            localStorage.removeItem(lastCheckKey);
        } finally {
            running = false;
        }
    }

    check();
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) check();
    });
})();
