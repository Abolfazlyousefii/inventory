document.addEventListener('DOMContentLoaded', () => {
    const card = document.getElementById('monthlyReportsCard');

    if (!card) {
        return;
    }

    const endpoint = card.dataset.endpoint;
    const monthSelect = document.getElementById('reportMonthSelect');
    const yearSelect = document.getElementById('reportYearSelect');
    const rangeLabel = document.getElementById('monthlyReportRange');
    const chart = document.getElementById('monthlyHorizontalChart');
    const error = document.getElementById('monthlyReportError');
    const initialData = document.getElementById('monthlyReportInitialData');
    const numberFormatter = new Intl.NumberFormat('fa-IR');

    const renderChart = (report) => {
        chart.replaceChildren();

        (Array.isArray(report.metrics) ? report.metrics : []).slice(0, 5).forEach((metric) => {
            const row = document.createElement('div');
            row.className = 'seller-chart-row';

            const meta = document.createElement('div');
            meta.className = 'seller-chart-row__meta';

            const label = document.createElement('span');
            label.textContent = String(metric.label ?? '');

            const value = document.createElement('span');
            value.textContent = `${numberFormatter.format(Number(metric.value ?? 0))} ${String(metric.unit ?? '')}`;

            const track = document.createElement('div');
            track.className = 'seller-chart-row__track';
            track.setAttribute('role', 'progressbar');
            track.setAttribute('aria-label', String(metric.label ?? ''));

            const percent = Math.max(0, Math.min(100, Number(metric.percent ?? 0)));
            track.setAttribute('aria-valuemin', '0');
            track.setAttribute('aria-valuemax', '100');
            track.setAttribute('aria-valuenow', String(percent));

            const bar = document.createElement('div');
            const allowedColors = ['success', 'warning', 'secondary'];
            const color = allowedColors.includes(metric.color) ? ` seller-chart-row__bar--${metric.color}` : '';
            bar.className = `seller-chart-row__bar${color}`;
            bar.style.width = `${percent}%`;

            meta.append(label, value);
            track.append(bar);
            row.append(meta, track);
            chart.append(row);
        });
    };

    const renderReport = (report) => {
        rangeLabel.textContent = `بازه: ${String(report.range_label ?? '—')}`;
        renderChart(report);
    };

    try {
        renderReport(JSON.parse(initialData?.textContent || '{}'));
    } catch {
        error.textContent = 'نمایش گزارش اولیه با خطا روبه‌رو شد.';
        error.hidden = false;
    }

    const fetchReport = async () => {
        if (!endpoint || !monthSelect || !yearSelect) {
            return;
        }

        card.classList.add('is-loading');
        card.setAttribute('aria-busy', 'true');
        error.hidden = true;

        const url = new URL(endpoint, window.location.origin);
        url.searchParams.set('report_month', monthSelect.value);
        url.searchParams.set('report_year', yearSelect.value);

        try {
            const response = await fetch(url, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('Monthly report request failed');
            }

            renderReport(await response.json());
        } catch {
            error.textContent = 'دریافت گزارش ماهانه انجام نشد. دوباره تلاش کنید.';
            error.hidden = false;
        } finally {
            card.classList.remove('is-loading');
            card.removeAttribute('aria-busy');
        }
    };

    monthSelect?.addEventListener('change', fetchReport);
    yearSelect?.addEventListener('change', fetchReport);
});
