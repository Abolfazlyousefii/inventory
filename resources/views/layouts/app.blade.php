<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name', 'سیستم انبار آریا جانبی'))</title>
    @yield('meta')

    <script src="{{ asset('lib/jquery-3.7.1.js') }}"></script>
    <script src="{{ asset('lib/select2.min.js') }}"></script>
    <script src="{{ asset('lib/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('lib/jalalidatepicker.min.js') }}"></script>

    <link rel="stylesheet" href="{{ asset('lib/bootstrap.rtl.min.css') }}"/>
    <link rel="stylesheet" href="{{ asset('lib/select2.min.css') }}"/>
    <link rel="stylesheet" href="{{ asset('lib/jalalidatepicker.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}"/>
    <link rel="stylesheet" href="{{ asset('css/Vazirmatn.css') }}">

    <style>
        body, button, input, select, textarea {
  font-family: "Vazirmatn", system-ui, -apple-system, "Segoe UI", Tahoma, Arial, sans-serif !important;
        }
    </style>
</head>

<style>
  /* Topbar روی موبایل ثابت/چسبنده */
  .app-topbar{
    position: sticky;
    top: 0;
    z-index: 1180;
    background: rgba(255,255,255,.96) !important;
    backdrop-filter: blur(6px);
    max-width: 100vw;
    min-width: 0;
  }
  .app-main-shell{
    min-width: 0;
    width: 100%;
  }
  .app-content-wide{
    max-width: none !important;
    width: 100% !important;
  }

  .app-topbar__brand,
  .app-topbar__actions{
    min-width: 0;
  }
  .app-topbar__brand{
    flex: 1 1 auto;
  }
  .app-topbar__actions{
    flex: 0 0 auto;
  }
  .app-notif-menu{
    max-width: calc(100vw - 1.5rem);
  }
  @media (min-width: 992px){
    .app-topbar{ position: static; }
  }

  .app-menu-btn{
    width: 40px;
    height: 40px;
    padding: 0;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 18px rgba(15,23,42,.06);
  }
  .app-menu-btn svg{ width: 22px; height: 22px; }

  .app-back-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    height:34px;
    padding:0 10px;
    border:1px solid rgba(12,83,103,.16);
    background:#fff;
    color:#0c5367;
    border-radius:10px;
    font-size:13px;
    font-weight:800;
    cursor:pointer;
    transition:all .15s ease;
    white-space:nowrap;
  }
  .app-back-btn:hover{
    background:rgba(51,199,192,.08);
    border-color:rgba(51,199,192,.35);
    color:#083d50;
  }
  .app-back-btn .back-icon{
    font-size:22px;
    line-height:1;
    font-weight:900;
  }
  @media (max-width:575.98px){
    .app-topbar{
      padding-inline: .5rem !important;
      gap: .5rem;
    }
    .app-topbar__brand img{
      width: 30px !important;
      height: 30px !important;
    }
    .app-topbar__actions{
      gap: .35rem !important;
    }
    .app-topbar__actions .dropdown-toggle{
      max-width: 92px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .app-content{
      padding-inline: .75rem !important;
    }
    .app-back-btn{
      width:36px;
      min-width:36px;
      padding:0;
      border-radius:10px;
    }
    .app-back-btn .back-text{ display:none; }
    .app-back-btn .back-icon{ font-size:24px; }
  }
</style>
<body class="bg-light">
<div class="d-flex" style="min-height: 100vh">

    {{-- Sidebar --}}
    @include('layouts.sidebar')

    {{-- Main --}}
    <div class="flex-grow-1 app-main-shell">
        {{-- Topbar --}}
@php
    $backFallbackUrl = url('/');
    if (\Illuminate\Support\Facades\Route::has('dashboard')) {
        $backFallbackUrl = route('dashboard');
    }
@endphp
<div class="app-topbar bg-white border-bottom py-2 px-3 d-flex justify-content-between align-items-center">
    <div class="app-topbar__brand d-flex align-items-center gap-2 fw-bold text-muted">

        {{-- Mobile Menu Button (فقط موبایل) --}}
        <button type="button" class="btn btn-sm btn-outline-secondary d-lg-none app-menu-btn"
                id="sidebarToggleBtn" aria-label="باز کردن منو">
            <svg viewBox="0 0 24 24" fill="none">
                <path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
        </button>

        <img src="{{ asset('logo.png') }}" alt="{{ config('app.name') }}"
             style="height: 34px; width: 34px; object-fit: contain;">
        <span class="text-truncate">{{ config('app.name','سیستم انبار آریا جانبی') }}</span>
    </div>

    <div class="app-topbar__actions d-flex align-items-center gap-2">
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary position-relative" data-bs-toggle="dropdown" id="notifBell">
                🔔
                <span id="notifBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none">0</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end p-2 app-notif-menu shadow" style="width: min(390px, calc(100vw - 1rem)); max-width: calc(100vw - 1rem); max-height: min(75vh, 560px); overflow:auto;">
                <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
                    <div><strong>اعلان‌ها</strong> <span id="notifUnreadText" class="badge bg-primary-subtle text-primary-emphasis">۰ خوانده‌نشده</span></div>
                    <button class="btn btn-sm btn-link p-0" id="notifReadAllBtn">خواندن همه</button>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary w-100 mb-2" id="notifSoundBtn">فعال‌سازی صدای اعلان</button>
                <div id="notifList" class="small text-muted">در حال بارگذاری...</div>
                <div class="mt-2 pt-2 border-top text-center">
                    <a href="{{ route('notifications.index') }}" class="small fw-bold">مشاهده همه آلارم‌ها</a>
                </div>
            </div>
        </div>
        <button type="button"
                class="app-back-btn"
                id="appBackBtn"
                data-fallback-url="{{ $backFallbackUrl }}"
                title="بازگشت">
            <span class="back-icon">‹</span>
            <span class="back-text">بازگشت</span>
        </button>

        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                {{ auth()->user()->name ?? 'کاربر' }}
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><span class="dropdown-item-text text-muted small">{{ auth()->user()->email ?? '' }}</span></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="dropdown-item text-danger">خروج</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</div>

        <main class="container py-4 app-content @yield('content_class')">
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if(isset($errors) && is_object($errors) && method_exists($errors, 'any') && $errors->any())
                <div class="alert alert-danger">
                    <div class="fw-bold mb-2">خطاها:</div>
                    <ul class="mb-0">
                        @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </main>
    <div id="notifToastStack" class="position-fixed top-0 end-0 p-3" style="z-index:1080; max-width:min(360px, calc(100vw - 1rem));"></div>
    </div>

</div>


{{-- فرمت هزارگان برای ورودی‌های money --}}
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const backBtn = document.getElementById('appBackBtn');
    if (!backBtn) return;

    backBtn.addEventListener('click', function () {
      const fallbackUrl = this.dataset.fallbackUrl || '/';
      if (window.history.length > 1) {
        window.history.back();
        return;
      }
      window.location.href = fallbackUrl;
    });
  });

  const notifState = {
    timer: null,
    initialized: false,
    latestIds: new Set(),
  };
  const notifSeenKey = 'notificationsSeenIds';
  const notifSoundKey = 'notificationsSoundEnabled';

  function notifSeenIds(){
    try { return new Set(JSON.parse(localStorage.getItem(notifSeenKey) || '[]')); } catch(e) { return new Set(); }
  }
  function saveNotifSeenIds(ids){ localStorage.setItem(notifSeenKey, JSON.stringify(Array.from(ids).slice(0, 80))); }
  function escapeHtml(value){
    return String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
  }
  function notifTypeLabel(type){
    if ((type || '').startsWith('preinvoice')) return 'پیش‌فاکتور';
    if ((type || '').includes('ship')) return 'ارسال';
    if ((type || '').includes('collection') || (type || '').includes('warehouse')) return 'انبار';
    if ((type || '').includes('finance')) return 'مالی';
    if ((type || '').startsWith('invoice')) return 'فاکتور';
    return 'اعلان';
  }
  function playNotificationBeep(){
    if (localStorage.getItem(notifSoundKey) !== '1') return;
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    if (!AudioContext) return;
    const ctx = new AudioContext();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine'; osc.frequency.value = 660; gain.gain.value = 0.035;
    osc.connect(gain); gain.connect(ctx.destination); osc.start();
    setTimeout(() => { osc.stop(); ctx.close(); }, 120);
  }
  function showNotificationToast(n){
    const stack = document.getElementById('notifToastStack'); if (!stack) return;
    while (stack.children.length >= 3) stack.firstElementChild.remove();
    const el = document.createElement('div');
    el.className = 'toast show border-0 shadow mb-2';
    el.innerHTML = `<div class="toast-header bg-primary text-white"><strong class="me-auto">${escapeHtml(n.title)}</strong><button type="button" class="btn-close btn-close-white ms-2" aria-label="Close"></button></div><div class="toast-body bg-white"><div class="small text-muted mb-2">${escapeHtml(n.message || '').slice(0,140)}</div><a class="btn btn-sm btn-primary" href="${escapeHtml(n.open_url || ('/notifications/'+n.id+'/open'))}">مشاهده</a></div>`;
    el.querySelector('.btn-close')?.addEventListener('click', () => el.remove());
    stack.appendChild(el); setTimeout(() => el.remove(), 6500);
  }
  async function loadNotifications(){
    const [cRes,lRes] = await Promise.all([
      fetch('{{ route('notifications.unread-count') }}'),
      fetch('{{ route('notifications.latest') }}')
    ]);
    const count = (await cRes.json()).count || 0;
    const badge = document.getElementById('notifBadge');
    badge.textContent = count;
    badge.classList.toggle('d-none', count <= 0);
    const unreadText = document.getElementById('notifUnreadText');
    if (unreadText) unreadText.textContent = `${count} خوانده‌نشده`;

    const list = await lRes.json();
    const seen = notifSeenIds();
    const newOnes = list.filter(n => !n.read_at && !seen.has(String(n.id)));
    if (notifState.initialized && newOnes.length) {
      newOnes.slice(0,3).forEach(showNotificationToast);
      playNotificationBeep();
    }
    list.forEach(n => seen.add(String(n.id)));
    saveNotifSeenIds(seen);
    notifState.initialized = true;

    const wrap = document.getElementById('notifList');
    if (!list.length) { wrap.innerHTML = '<div class="text-muted p-3 text-center">اعلانی وجود ندارد.</div>'; return; }
    wrap.innerHTML = list.map(n => {
      const priority = n.priority || 'normal';
      const tone = priority === 'urgent' ? 'danger' : (priority === 'important' ? 'primary' : 'secondary');
      const bg = n.read_at ? 'bg-light' : 'bg-info bg-opacity-10 border-info-subtle';
      return `<a class="d-block text-decoration-none p-2 mb-2 rounded border ${bg}" href="${escapeHtml(n.open_url || ('/notifications/'+n.id+'/open'))}">
        <div class="d-flex gap-2 align-items-start"><span class="badge rounded-pill bg-${tone}">&nbsp;</span><div class="flex-grow-1 min-w-0">
        <div class="d-flex justify-content-between gap-2"><div class="fw-bold text-dark text-truncate">${escapeHtml(n.title)}</div><span class="badge bg-${tone}-subtle text-${tone}-emphasis">${notifTypeLabel(n.type)}</span></div>
        <div class="text-muted small" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">${escapeHtml(n.message || '')}</div>
        <div class="text-muted mt-1" style="font-size:.72rem">${escapeHtml(n.created_at_human || '')}</div></div></div>
      </a>`;
    }).join('');
  }
  function scheduleNotifications(){
    clearTimeout(notifState.timer);
    notifState.timer = setTimeout(async () => { await loadNotifications(); scheduleNotifications(); }, document.hidden ? 60000 : 30000);
  }
  document.addEventListener('DOMContentLoaded', function(){
    loadNotifications().finally(scheduleNotifications);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) loadNotifications(); scheduleNotifications(); });
    document.getElementById('notifReadAllBtn')?.addEventListener('click', async function(){
      await fetch('{{ route('notifications.read-all') }}', {method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}'}});
      const seen = notifSeenIds(); document.querySelectorAll('#notifList a[href*="/notifications/"]').forEach(a => { const m = a.href.match(/notifications\/(\d+)\/open/); if (m) seen.add(m[1]); }); saveNotifSeenIds(seen);
      loadNotifications();
    });
    const soundBtn = document.getElementById('notifSoundBtn');
    if (soundBtn) {
      const refreshSoundLabel = () => soundBtn.textContent = localStorage.getItem(notifSoundKey) === '1' ? 'صدای اعلان فعال است (کلیک برای غیرفعال‌سازی)' : 'فعال‌سازی صدای اعلان';
      refreshSoundLabel();
      soundBtn.addEventListener('click', () => { const enabled = localStorage.getItem(notifSoundKey) === '1'; localStorage.setItem(notifSoundKey, enabled ? '0' : '1'); refreshSoundLabel(); if (!enabled) playNotificationBeep(); });
    }
  });

  function formatMoneyInput(el){
    const raw = (el.value || '').replace(/[^\d]/g,'');
    el.value = raw.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  }
  document.addEventListener('input', function(e){
    if(e.target && e.target.classList.contains('money')) formatMoneyInput(e.target);
  });
  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('input.money').forEach(formatMoneyInput);
  });
</script>


<script>
  function initJalaliDatepickers(){
    if (!window.jalaliDatepicker) return;

    function div(a, b) { return ~~(a / b); }
    function pad(v) { return String(v).padStart(2, '0'); }

    function gregorianToJalali(gy, gm, gd) {
      const g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
      let jy = (gy <= 1600) ? 0 : 979;
      gy -= (gy <= 1600) ? 621 : 1600;
      const gy2 = (gm > 2) ? (gy + 1) : gy;
      let days = (365 * gy) + div(gy2 + 3, 4) - div(gy2 + 99, 100) + div(gy2 + 399, 400) - 80 + gd + g_d_m[gm - 1];
      jy += 33 * div(days, 12053);
      days %= 12053;
      jy += 4 * div(days, 1461);
      days %= 1461;
      if (days > 365) {
        jy += div(days - 1, 365);
        days = (days - 1) % 365;
      }
      const jm = (days < 186) ? 1 + div(days, 31) : 7 + div(days - 186, 30);
      const jd = 1 + ((days < 186) ? (days % 31) : ((days - 186) % 30));
      return [jy, jm, jd];
    }

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
      return [gy, gm, gd];
    }

    function gregorianStringToJalali(str) {
      const datePart = (str || '').split('T')[0] || '';
      const m = datePart.match(/^(\d{4})-(\d{2})-(\d{2})$/);
      if (!m) return '';
      const j = gregorianToJalali(Number(m[1]), Number(m[2]), Number(m[3]));
      return `${j[0]}/${pad(j[1])}/${pad(j[2])}`;
    }

    function jalaliStringToGregorian(str) {
      const m = (str || '').trim().match(/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/);
      if (!m) return '';
      const g = jalaliToGregorian(Number(m[1]), Number(m[2]), Number(m[3]));
      return `${g[0]}-${pad(g[1])}-${pad(g[2])}`;
    }

    function bindDateInput(el){
      if (el.dataset.faDateBound === '1') return;
      el.dataset.faDateBound = '1';

      const isDateTime = el.type === 'datetime-local';
      const initialGregorian = el.value || '';
      const initialTime = isDateTime && initialGregorian.includes('T')
        ? initialGregorian.split('T')[1].slice(0,5)
        : '';

      el.type = 'text';
      el.setAttribute('autocomplete', 'off');
      el.setAttribute('dir', 'ltr');

      el.setAttribute('data-jdp', '');
      if (!isDateTime) el.setAttribute('data-jdp-only-date', '');

      if (initialGregorian) {
        const jalali = gregorianStringToJalali(initialGregorian);
        el.value = isDateTime ? `${jalali} ${initialTime}`.trim() : jalali;
      }

      const form = el.closest('form');
      if (!form || form.dataset.faDateSubmitBound === '1') return;
      form.dataset.faDateSubmitBound = '1';

      form.addEventListener('submit', function(){
        form.querySelectorAll('input[data-fa-date-bound="1"]').forEach(function(dateInput){
          const raw = (dateInput.value || '').trim();
          if (!raw) return;

          const [datePart, timePart] = raw.split(' ');
          const gregorianDate = jalaliStringToGregorian(datePart);
          if (!gregorianDate) return;

          dateInput.value = dateInput.dataset.faOriginalType === 'datetime-local' && timePart
            ? `${gregorianDate}T${timePart}`
            : gregorianDate;
        });
      });
    }

    document.querySelectorAll('input[type="date"], input[type="datetime-local"]').forEach(function(el){
      el.dataset.faOriginalType = el.type;
      bindDateInput(el);
    });

    jalaliDatepicker.startWatch({
      minDate: 'attr',
      maxDate: 'attr',
      time: true
    });
  }

  document.addEventListener('DOMContentLoaded', initJalaliDatepickers);
</script>

@stack('scripts')
</body>
</html>
