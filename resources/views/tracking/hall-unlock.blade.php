<!doctype html>
<html lang="fa" dir="rtl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>فعال‌سازی نمایشگر | آریا جانبی</title>
<link rel="stylesheet" href="{{ asset('tracking/tracking.css') }}">
</head><body class="hall-unlock-page">
<main class="hall-unlock-box">
  <div class="brand"><span class="brand-mark">آ</span><span class="brand-copy"><strong>آریا جانبی</strong><small>نمایشگر سالن</small></span></div>
  <h1>فعال‌سازی نمایشگر</h1>
  <p>کلید اختصاصی نمایشگر را فقط یک‌بار در دستگاه سالن وارد کنید. این کلید برای مشتریان نیست.</p>
  <form method="post" action="{{ route('hall.unlock.submit') }}">
    @csrf
    <label for="access_key">کلید نمایشگر</label>
    <input type="password" id="access_key" name="access_key" required autocomplete="off" maxlength="256">
    @error('access_key') <p role="alert" class="hall-form-error">{{ $message }}</p> @enderror
    <button type="submit" class="primary-btn">فعال‌سازی نمایشگر</button>
  </form>
</main></body></html>
