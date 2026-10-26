(() => {
'use strict';
    const form = document.getElementById('financeEditForm');
    if (!form) return;
    const modalEl = document.getElementById('financePaymentComposer');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const field = id => document.getElementById(id);
    const read = id => field(id).value.trim();
    const math = window.SalesDiscountMath;
    const amount = value => Number(math.input(value));
    const fmt = value => Number(value).toLocaleString('en-US')+' ریال';
    let payments;
    try { payments = JSON.parse(field('financeStagedPayments').textContent); } catch { payments = []; }
    if (!Array.isArray(payments)) payments = [];
    payments = payments.filter(row => row && ['cash','cheque'].includes(row.method));
    let editing = null;
    let committing = false;
    const error = message => { field('paymentModalError').textContent = message; field('paymentModalError').classList.toggle('d-none', !message); };
    const toggle = () => { const cheque = read('paymentTypeInput') === 'cheque'; field('cashFields').classList.toggle('d-none',cheque); field('chequeFields').classList.toggle('d-none',!cheque); };
    function render() {
        const wrap = field('paymentRows'); wrap.replaceChildren();
        let cash = 0, cheque = 0;
        payments.forEach((payment,index) => {
            if (payment.method === 'cash') cash += Number(payment.amount); else cheque += Number(payment.amount);
            const row = document.createElement('div'); row.className = 'fr-payment-row';
            const content = document.createElement('div');
            const title = document.createElement('strong');
            title.textContent = (payment.method === 'cash' ? 'نقدی' : 'چک '+payment.cheque_number)+' · '+fmt(payment.amount);
            const note = document.createElement('small');
            const displayDate = value => value ? new Intl.DateTimeFormat('fa-IR').format(new Date(value+'T12:00:00')) : '—';
            try { note.textContent = 'تاریخ دریافت: '+displayDate(payment.paid_at)+(payment.method === 'cheque' ? ' · سررسید: '+displayDate(payment.cheque_due_date) : '')+' · '+(payment.note || 'بدون توضیح'); }
            catch { note.textContent = 'تاریخ پرداخت را با ویرایش بررسی کنید.'; }
            content.append(title,note);
            const actions = document.createElement('div');
            [['ویرایش',() => open(index)],['حذف',() => { payments.splice(index,1); render(); }]].forEach(([label,handler]) => {
                const button = document.createElement('button'); button.type='button'; button.className='btn btn-sm btn-outline-secondary'; button.textContent=label; button.addEventListener('click',handler); actions.append(button);
            });
            row.append(content,actions); wrap.append(row);
        });
        field('paymentGuide').hidden = payments.length > 0;
        field('cashTotalLabel').textContent = fmt(cash); field('chequeTotalLabel').textContent = fmt(cheque); field('allTotalLabel').textContent = fmt(cash+cheque);
        document.dispatchEvent(new Event('finance:payments-changed'));
    }
    function toJalali(value) {
        if (!value) return '';
        try { return new Intl.DateTimeFormat('en-US-u-ca-persian',{year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date(value+'T12:00:00')).filter(p => ['year','month','day'].includes(p.type)).sort((a,b) => ['year','month','day'].indexOf(a.type)-['year','month','day'].indexOf(b.type)).map(p=>p.value).join('/'); } catch { return ''; }
    }
    function open(index = null) {
        editing = index; committing = false; error('');
        field('savePaymentBtn').disabled = false;
        modalEl.querySelector('.modal-title').textContent = index === null ? 'افزودن پرداخت' : 'ویرایش پرداخت';
        modalEl.querySelectorAll('input,textarea').forEach(input => { input.value=''; input.setCustomValidity(''); input.removeAttribute('aria-invalid'); });
        const payment = index === null ? null : payments[index];
        field('paymentTypeInput').value = payment?.method || 'cash';
        field('cashPaidAtInput').value = toJalali(payment?.paid_at || new Date().toLocaleDateString('en-CA'));
        field('chequeStatusInput').value = payment?.cheque_status || 'unregistered';
        if (payment) {
            const prefix = payment.method === 'cash' ? 'cash' : 'cheque';
            field(prefix+'AmountInput').value = Number(payment.amount).toLocaleString('en-US');
            field(prefix+'NoteInput').value = payment.note || '';
            field(prefix+'BankNameInput').value = payment.cheque_bank_name || payment.bank_name || '';
            if (payment.method === 'cheque') {
                field('chequeNumberInput').value=payment.cheque_number || '';
                field('chequeReceivedAtInput').value=toJalali(payment.cheque_received_at || payment.paid_at);
                field('chequeDueDateInput').value=toJalali(payment.cheque_due_date);
            }
        }
        toggle(); modal.show();
        window.initJalaliDatepickers?.();
        window.jalaliDatepicker?.startWatch({minDate:'attr',maxDate:'attr'});
    }
    function div(a, b) { return ~~(a / b); }
    function pad(v) { return String(v).padStart(2, '0'); }
    function jalaliToGregorian(jy, jm, jd) {
      jy += 1595;
      let days = -355668 + (365 * jy) + div(jy, 33) * 8 + div((jy % 33) + 3, 4) + jd + ((jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
      let gy = 400 * div(days, 146097);
      days %= 146097;
      if (days > 36524) {
        gy += 100 * div(--days, 36524);
        days %= 36524;
        if (days >= 365) days++;
      }
      gy += 4 * div(days, 1461);
      days %= 1461;
      if (days > 365) {
        gy += div(days - 1, 365);
        days = (days - 1) % 365;
      }
      let gd = days + 1;
      const sal_a = [0,31,((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28,31,30,31,30,31,31,30,31,30,31];
      let gm = 0;
      for (gm = 1; gm <= 12 && gd > sal_a[gm]; gm++) gd -= sal_a[gm];
      return `${gy}-${pad(gm)}-${pad(gd)}`;
    }
    function normalizeDate(value) {
        const raw = math.input(value);
        const match = raw.match(/^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$/);
        if (!match) return '';
        const [year,month,day] = match.slice(1).map(Number);
        if (month<1 || month>12 || day<1 || day>31) return '';
        const gregorian = year<1700 ? jalaliToGregorian(year,month,day) : `${year}-${pad(month)}-${pad(day)}`;
        const date = new Date(gregorian+'T12:00:00');
        if (Number.isNaN(date.getTime())) return '';
        if (year<1700 && toJalali(gregorian) !== `${year}/${pad(month)}/${pad(day)}`) return '';
        if (year>=1700 && (date.getFullYear()!==year || date.getMonth()+1!==month || date.getDate()!==day)) return '';
        return gregorian;
    }
    field('addPaymentRow').addEventListener('click', () => open());
    field('paymentTypeInput').addEventListener('change',toggle);
    field('savePaymentBtn').addEventListener('click', () => {
        if (committing) return;
        const method=read('paymentTypeInput'), prefix=method==='cash'?'cash':'cheque';
        const rawAmount=math.input(read(prefix+'AmountInput'));
        const paidAt=normalizeDate(read(method==='cash'?'cashPaidAtInput':'chequeReceivedAtInput'));
        if (!/^\d+$/.test(rawAmount) || !Number.isSafeInteger(amount(rawAmount)) || amount(rawAmount)<=0 || !paidAt) { error('مبلغ صحیح و تاریخ معتبر دریافت را وارد کنید.'); return; }
        const payment={method,amount:amount(rawAmount),paid_at:paidAt,bank_name:read(prefix+'BankNameInput'),note:read(prefix+'NoteInput')};
        if (method==='cheque') {
            Object.assign(payment,{cheque_amount:payment.amount,cheque_number:read('chequeNumberInput'),cheque_bank_name:payment.bank_name,cheque_received_at:paidAt,cheque_due_date:normalizeDate(read('chequeDueDateInput')),cheque_status:read('chequeStatusInput')});
            if (!payment.cheque_due_date || !payment.cheque_number || !payment.cheque_bank_name) { error('شماره، بانک و تاریخ سررسید معتبر چک را وارد کنید.'); return; }
        }
        if (editing===null && payments.length>=100) { error('حداکثر ۱۰۰ پرداخت قابل ثبت است.'); return; }
        committing = true;
        field('savePaymentBtn').disabled = true;
        if (editing===null) payments.push(payment); else payments[editing]=payment;
        render(); modal.hide();
    });
    window.FinanceReviewPayments={rows:()=>payments.map(payment=>({...payment}))};
    render();
})();
