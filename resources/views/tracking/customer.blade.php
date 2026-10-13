<!doctype html>
<html lang="fa" dir="rtl">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow,noarchive">
	<meta name="referrer" content="no-referrer">
	<meta name="tracking-data-url" content="{{ $dataUrl }}">
	<meta name="tracking-mode" content="{{ $mode ?? 'customer' }}">
	@if (($mode ?? 'customer') === 'hall')
		<meta name="hall-unlock-url" content="{{ route('hall.unlock') }}">
	@endif
	<meta name="theme-color" content="#102f39">
	<title>پیگیری سفارش‌ها | آریا جانبی</title>
	<link rel="stylesheet" href="{{ asset('tracking/tracking.css') }}">
	<script src="{{ asset('tracking/tracking.js') }}" defer></script>
</head>
<body class="{{ ($mode ?? 'customer') === 'hall' ? 'hall-mode' : 'customer-mode' }}">
<div class="page-frame">
	<header class="site-header" aria-label="سربرگ رهگیری">
		<div class="container header-inner">
			<div class="brand">
				<span class="brand-mark" aria-hidden="true">آ</span>
				<span class="brand-copy"><strong>آریا جانبی</strong><small>سامانه پیگیری سفارش</small></span>
			</div>
			<div class="header-access" title="نمایش صرفاً با لینک اختصاصی">
				<span class="access-dot" aria-hidden="true"></span>
				<span>{{ ($mode ?? 'customer') === 'hall' ? 'نمایش عمومی سفارش‌ها' : 'لینک اختصاصی شما' }}</span>
			</div>
		</div>
	</header>

	<main class="container">
		<section class="hero" aria-labelledby="hero-title">
			<div class="hero-text">
				<div class="eyebrow"><span class="eyebrow-line"></span> {{ ($mode ?? 'customer') === 'hall' ? 'نمایش زنده وضعیت سفارش‌ها' : 'وضعیت سفارش‌ها، یکجا' }}</div>
				<h1 id="hero-title">@if (($mode ?? 'customer') === 'hall') سفارش‌ها،<br><span>لحظه‌به‌لحظه.</span> @else سفارش‌های شما،<br><span>شفاف و در دسترس.</span> @endif</h1>
				<p class="hero-desc">{{ ($mode ?? 'customer') === 'hall' ? 'نمایش زنده وضعیت سفارش‌های در حال انجام و سفارش‌های اخیراً تکمیل‌شده، بدون اطلاعات شخصی مشتریان.' : 'در این صفحه می‌توانید آخرین وضعیت فاکتورها و پیش‌فاکتورهای خود را مشاهده کنید؛ بدون نیاز به ورود به حساب کاربری.' }}</p>
				@if (($mode ?? 'customer') !== 'hall')
					<div class="hero-customer">
						<span class="avatar" aria-hidden="true">ش</span>
						<div><small>پنل سفارش‌های</small><strong id="customer-name">در حال بارگذاری…</strong></div>
					</div>
				@endif
			</div>
			<div class="hero-art" aria-hidden="true">
				<div class="art-halo art-halo-one"></div>
				<div class="art-halo art-halo-two"></div>
				<div class="art-sheet art-back"><div></div><div></div><div></div></div>
				<div class="art-sheet art-front"><span class="art-kicker">وضعیت سفارش</span><div class="art-progress"><i></i><i></i><i></i><i></i></div><div class="art-line"></div><div class="art-line short"></div><div class="art-done">✓</div></div>
				<span class="art-spark art-spark-one">✳</span><span class="art-spark art-spark-two">✦</span>
			</div>
		</section>



		<section class="documents" aria-labelledby="documents-title">
			<div class="section-heading"><div><span class="section-eyebrow">{{ ($mode ?? 'customer') === 'hall' ? 'تابلوی سالن' : 'آرشیو سفارش‌ها' }}</span><h2 id="documents-title">فاکتورها و پیش‌فاکتورها</h2><p>{{ ($mode ?? 'customer') === 'hall' ? 'به‌روزرسانی خودکار هر ۳۰ ثانیه؛ بدون نمایش نام مشتری یا اطلاعات مالی.' : 'اطلاعات این صفحه از وضعیت فعلی سیستم انبارداری خوانده می‌شود.' }}</p></div><div class="last-updated" id="last-updated" aria-live="polite"></div></div>
			<div class="toolbar">
				<div class="tabs" role="group" aria-label="نوع سند">
					<button type="button" class="tab active" data-type="all" aria-pressed="true">همه اسناد</button>
					<button type="button" class="tab" data-type="invoice" aria-pressed="false">فاکتورها</button>
					<button type="button" class="tab" data-type="preinvoice" aria-pressed="false">پیش‌فاکتورها</button>
				</div>
				<label class="search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><span class="sr-only">جستجو در اسناد</span><input id="document-search" autocomplete="off" placeholder="شماره سند، وضعیت یا فروشنده…" type="search"></label>
			</div>
			<div id="result-meta" class="result-meta" aria-live="polite"></div>
			<div id="loading" class="loading-grid" aria-live="polite"><div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div></div>
			<div id="error" class="message-state hidden" role="alert"><div class="message-symbol">!</div><h3>بارگذاری اطلاعات ممکن نشد</h3><p id="error-text">لطفاً صفحه را دوباره بارگذاری کنید.</p><button type="button" id="retry" class="primary-btn">تلاش دوباره</button></div>
			<div id="empty" class="message-state hidden"><div class="message-symbol">⌕</div><h3>سندی پیدا نشد</h3><p>در حال حاضر سندی با این جستجو وجود ندارد.</p></div>
			<div class="cards-grid" id="document-list" aria-live="polite"></div>
		</section>

		<section class="summary" aria-label="خلاصه سفارش‌ها">
			<div class="stat"><span class="stat-icon total" aria-hidden="true">▦</span><div><small>مجموع اسناد</small><strong id="total-count">—</strong></div></div>
			<div class="stat"><span class="stat-icon invoice" aria-hidden="true">▤</span><div><small>فاکتورها</small><strong id="invoice-count">—</strong></div></div>
			<div class="stat"><span class="stat-icon preinvoice" aria-hidden="true">◫</span><div><small>پیش‌فاکتورها</small><strong id="preinvoice-count">—</strong></div></div>
		</section>

		<div class="privacy-note"><span aria-hidden="true">ⓘ</span><p>{{ ($mode ?? 'customer') === 'hall' ? 'شماره سند و وضعیت آن نمایش داده می‌شود؛ نام مشتری، شماره تماس و اطلاعات مالی منتشر نمی‌شود؛ نام فروشنده نمایش داده می‌شود. برای جزئیات سفارش خود، از لینک پیامکی اختصاصی استفاده کنید.' : 'این صفحه فقط اطلاعات عمومی پیگیری سفارش را نشان می‌دهد. برای حفاظت از اطلاعات شما، مبالغ، آدرس، شماره تماس و جزئیات مالی نمایش داده نمی‌شود. لینک اختصاصی خود را در اختیار افراد ناشناس قرار ندهید.' }}</p></div>	</main>
	<footer class="footer"><div class="container footer-inner"><strong>آریا جانبی</strong><span>پیگیری آسان و شفاف سفارش‌ها</span><span>© {{ date('Y') }}</span></div></footer>
</div>

<div class="drawer-backdrop hidden" id="drawer-backdrop"></div>
<section class="drawer hidden" id="detail-drawer" role="dialog" aria-modal="true" aria-labelledby="drawer-title" tabindex="-1">
	<div class="drawer-handle" aria-hidden="true"></div>
	<div class="drawer-header"><div><span class="drawer-kicker">جزئیات سند</span><h2 id="drawer-title">پیگیری سفارش</h2></div><button type="button" id="drawer-close" class="close-btn" aria-label="بستن جزئیات">×</button></div>
	<div id="drawer-content" class="drawer-content"></div>
</section>


</body>
</html>
