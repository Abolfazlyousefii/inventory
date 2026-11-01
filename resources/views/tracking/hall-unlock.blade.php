<!doctype html>
<html lang="fa" dir="rtl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive"><title>ورود مدیریت | نبض آریا</title>
<meta name="referrer" content="no-referrer"><link rel="icon" href="data:,">
<link rel="stylesheet" href="{{ asset('hall-management/dashboard.css') }}?v={{ filemtime(public_path('hall-management/dashboard.css')) }}">
</head><body class="unlock-page">
<main class="unlock-box">
  <span class="brand-mark" aria-hidden="true">آ</span>
  <h1>نبض آریا</h1>
  <p>ورود به نمایشگر خصوصی مدیریت</p>
  <form method="post" action="{{ route('hall.unlock.submit') }}">
    @csrf
    <label for="access_key">کلید دسترسی مدیریت</label>
    <input type="password" id="access_key" name="access_key" required autocomplete="off" maxlength="256">
    @error('access_key') <p role="alert" class="unlock-error">{{ $message }}</p> @enderror
    <button type="submit">ورود به داشبورد</button>
  </form>
  <small>این صفحه شامل اطلاعات مالی و مشتریان است.</small>
</main></body></html>
