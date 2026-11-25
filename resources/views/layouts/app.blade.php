<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="{{ asset('js/app-theme.js') }}?v=20261025d"></script>
    <script src="{{ asset('js/rial-inputs.js') }}?v=20261027a"></script>

    @if(session('preinvoice_submit_succeeded'))
        <script>
            try {
                localStorage.removeItem('aria_preinvoice_local_draft_create_v1');
                localStorage.removeItem('aria_preinvoice_reservation_token_v1');
            } catch (error) {
                // Browser storage may be unavailable; server-side submit remains valid.
            }
        </script>
    @endif

    @if(request()->routeIs('preinvoice.create'))
        <script src="{{ asset('js/preinvoice-submit-safety.js') }}"></script>
    @endif

    @php
        $appName = config('app.name', 'نرم افزار داخلی آریا گستر');
        $sectionDocumentTitle = trim((string) preg_replace('/\s+/u', ' ', strip_tags($__env->yieldContent('title'))));
        $componentDocumentTitle = isset($title)
            ? trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $title)))
            : '';
        $pageDocumentTitle = $sectionDocumentTitle !== '' ? $sectionDocumentTitle : $componentDocumentTitle;
        $documentTitle = match (true) {
            $pageDocumentTitle === '' => $appName,
            str_contains($pageDocumentTitle, $appName) => $pageDocumentTitle,
            default => $pageDocumentTitle.' | '.$appName,
        };
    @endphp
    <title>{{ $documentTitle }}</title>
    @yield('meta')

	<script src="{{ asset('lib/jquery-3.7.1.js') }}"></script>

	<script src="{{ asset('lib/datatables.min.js') }}"></script>
	<script src="{{ asset('lib/datatables.bootstrap5.min.js') }}"></script>

	<script src="{{ asset('lib/select2.min.js') }}"></script>
	<script src="{{ asset('lib/bootstrap.bundle.min.js') }}"></script>
	<script src="{{ asset('lib/jalalidatepicker.min.js') }}"></script>

	<link rel="stylesheet" href="{{ asset('lib/bootstrap.rtl.min.css') }}">
	<link rel="stylesheet" href="{{ asset('lib/datatables.bootstrap5.min.css') }}">

	<link rel="stylesheet" href="{{ asset('lib/select2.min.css') }}">
	<link rel="stylesheet" href="{{ asset('lib/jalalidatepicker.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/Vazirmatn.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin-theme.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app-shell.css') }}?v=20261125-notif7">
    <link rel="stylesheet" href="{{ asset('css/sidebar.css') }}?v=20261011">
	@vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
    <link rel="stylesheet" href="{{ asset('css/rial-inputs.css') }}?v=20261027a">
    <link id="app-theme-tokens" rel="stylesheet" href="{{ asset('css/app-theme.css') }}?v=20261025d">
    <script src="{{ asset('js/app-theme-compat.js') }}?v=20261025c" defer></script>
</head>
@php
    $backFallbackUrl = \Illuminate\Support\Facades\Route::has('dashboard')
        ? route('dashboard')
        : url('/');
    $authenticatedUser = auth()->user();
    $userName = trim((string) ($authenticatedUser?->name ?: 'کاربر'));
    $userInitial = mb_substr($userName, 0, 1) ?: 'ک';
    $userRole = $authenticatedUser && method_exists($authenticatedUser, 'getRoleNames')
        ? $authenticatedUser->getRoleNames()->first()
        : null;
    $pageTitle = trim($__env->yieldContent('page-title'));
    if ($pageTitle === '') {
        $pageTitle = $pageDocumentTitle;
    }
    if ($pageTitle === '') {
        $pageTitle = $appName;
    }
@endphp
<body class="app-body"
      data-notifications-count-url="{{ route('notifications.unread-count', [], false) }}"
      data-notifications-latest-url="{{ route('notifications.latest', [], false) }}"
      data-notifications-read-all-url="{{ route('notifications.read-all', [], false) }}">
<div class="app-layout">
    @include('layouts.sidebar')

    <div class="app-main-shell">
        <header class="app-topbar">
            <div class="app-topbar__start">
                <button type="button"
                        class="app-icon-btn app-menu-btn"
                        id="sidebarToggleBtn"
                        aria-label="باز کردن منوی اصلی"
                        aria-controls="appSidebar"
                        aria-expanded="false">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
                <button type="button"
                        class="app-icon-btn app-desktop-sidebar-toggle"
                        id="sidebarCollapseBtn"
                        aria-label="جمع‌کردن سایدبار"
                        aria-pressed="false"
                        title="جمع‌کردن سایدبار">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M15 18l-6-6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <img class="app-mobile-brand" src="{{ asset('logo.png') }}" alt="">
                <div class="app-topbar__titles">
                    <span class="app-topbar__app-name">{{ $appName }}</span>
                    <h1 class="app-page-title" title="{{ $pageTitle }}">{{ $pageTitle }}</h1>
                </div>
            </div>

            <div class="app-topbar__actions">
                @include('layouts.theme-toggle')
                <button class="app-icon-btn app-notification-btn"
                        type="button"
                        id="notifBell"
                        aria-controls="notifPanel"
                        aria-expanded="false"
                        aria-label="اعلان‌ها">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M18 8a6 6 0 10-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span id="notifBadge" class="app-notification-badge d-none">0</span>
                </button>

                <button type="button"
                        class="app-back-btn"
                        id="appBackBtn"
                        data-fallback-url="{{ $backFallbackUrl }}"
                        aria-label="بازگشت"
                        title="بازگشت">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M9 18l6-6-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="app-back-btn__text">بازگشت</span>
                </button>

                <div class="dropdown">
                    <button class="app-user-toggle dropdown-toggle"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false">
                        <span class="app-user-avatar" aria-hidden="true">{{ $userInitial }}</span>
                        <span class="app-user-copy">
                            <span class="app-user-name">{{ $userName }}</span>
                            <span class="app-user-role">{{ $userRole ?: 'کاربر سامانه' }}</span>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end app-user-menu">
                        <li class="app-user-menu__identity">
                            <strong>{{ $userName }}</strong>
                            @if($authenticatedUser?->email)
                                <small>{{ $authenticatedUser->email }}</small>
                            @endif
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        @if(\Illuminate\Support\Facades\Route::has('profile.edit'))
                            <li>
                                <a class="dropdown-item" href="{{ route('profile.edit') }}">
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M20 21a8 8 0 00-16 0M12 13a5 5 0 100-10 5 5 0 000 10z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                    </svg>
                                    پروفایل
                                </a>
                            </li>
                        @endif
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="dropdown-item text-danger" type="submit">
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M10 17l5-5-5-5M15 12H3M14 3h5a2 2 0 012 2v14a2 2 0 01-2 2h-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    خروج
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <main class="app-content @yield('content_class')">
            @include('inventory-count-freeze.banner')
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="بستن"></button>
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="بستن"></button>
                </div>
            @endif

            @if(isset($errors) && is_object($errors) && method_exists($errors, 'any') && $errors->any())
                <div class="alert alert-danger">
                    <div class="fw-bold mb-2">خطاها:</div>
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if(isset($slot))
                {{ $slot }}
            @else
                @yield('content')
            @endif
        </main>
    </div>
</div>

<div id="notificationBackdrop" class="notification-backdrop" aria-hidden="true" hidden></div>
<section id="notifPanel" class="notif-panel" role="dialog" aria-modal="true" aria-hidden="true"
         aria-labelledby="notifPanelTitle" data-notification-surface data-filter="unread" data-per-page="6" data-page="1">
    <div class="notif-panel__head">
        <div class="notif-panel__heading">
            <span class="notif-panel__symbol" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <div><h3 id="notifPanelTitle">اعلان‌ها</h3>
            <p id="notifUnreadText" data-notification-count aria-live="polite">در حال دریافت…</p></div>
        </div>
        <button type="button" class="app-icon-btn notif-panel__close" id="notifCloseBtn" aria-label="بستن اعلان‌ها">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
        </button>
    </div>
    <div class="notif-panel__tools">
        <form action="{{ route('notifications.read-all', [], false) }}" method="POST" data-notification-read-all>
            @csrf
            <button type="submit" class="notif-tool-btn notif-tool-btn--primary" id="notifReadAllBtn" disabled><span aria-hidden="true">✓</span> خواندن همه</button>
        </form>
    </div>
    <div class="notif-feedback" data-notification-error hidden><span data-notification-error-text role="alert"></span> <button type="button" class="notif-tool-btn" data-notification-retry>تلاش مجدد</button></div>
    <div id="notifList" class="notif-list" data-notification-list aria-busy="true">
        <div class="notif-empty notif-empty--loading" role="status"><span class="notif-empty__icon" aria-hidden="true">…</span><span>در حال دریافت اعلان‌ها…</span></div>
    </div>
    <nav class="notif-pagination" aria-label="صفحه‌بندی اعلان‌ها" data-notification-pagination hidden>
        <button type="button" class="notif-tool-btn" data-notification-step="-1"><span class="notif-page-arrow" aria-hidden="true"></span> قبلی</button>
        <span data-notification-page-label aria-live="polite"></span>
        <button type="button" class="notif-tool-btn" data-notification-step="1">بعدی <span class="notif-page-arrow" aria-hidden="true"></span></button>
    </nav>
    <div class="notif-panel__foot">
        <a href="{{ route('notifications.index', [], false) }}">مشاهده صندوق اعلان‌ها <span aria-hidden="true">←</span></a>
    </div>
</section>


<script src="{{ asset('js/app-shell.js') }}?v=20261125-notif5"></script>
@stack('scripts')
</body>
</html>
