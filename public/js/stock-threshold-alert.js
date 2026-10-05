(() => {
    const dialog = document.getElementById('stockThresholdDialog'); if (!dialog) return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!csrf) return;
    let running = false;
    document.getElementById('stockThresholdClose').addEventListener('click', () => dialog.close());
    async function check() {
        if (running || document.hidden || dialog.open) return;
        running = true;
        try {
            const summaryResponse = await fetch(dialog.dataset.summaryUrl, {headers: {'Accept': 'application/json'}});
            if (!summaryResponse.ok) return;
            const summary = await summaryResponse.json();
            document.querySelectorAll('[data-stock-threshold-count]').forEach(el => el.textContent = Number(summary.count).toLocaleString('fa-IR'));
            if (!summary.count) return;
            const response = await fetch(dialog.dataset.dailyUrl, {method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}});
            if (!response.ok) return;
            const result = await response.json();
            if (!result.show) return;
            document.getElementById('stockThresholdMessage').textContent = `${Number(result.count).toLocaleString('fa-IR')} کالا به آستانه موجودی رسیده‌اند. در مجموع ${Number(result.rows).toLocaleString('fa-IR')} هشدار کالا و تنوع برای بررسی تأمین وجود دارد.`;
            dialog.showModal();
        } catch (_) { /* Transient failures must not interfere with the current page. */ }
        finally { running = false; }
    }
    check(); setInterval(check, 300000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
})();
