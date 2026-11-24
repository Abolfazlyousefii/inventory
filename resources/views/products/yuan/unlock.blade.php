<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="referrer" content="no-referrer">
    <title>ورود به مدیریت قیمت یوان | {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('hall-management/dashboard.css') }}?v={{ filemtime(public_path('hall-management/dashboard.css')) }}">
</head>
<body class="unlock-page">
<main class="unlock-box">
    <span class="brand-mark" aria-hidden="true">¥</span>
    <h1>مدیریت قیمت یوان</h1>
    <p>برای ورود به این بخش، رمز دسترسی را وارد کنید.</p>
    @if($configured)
        <form method="post" action="{{ route('products.yuan.unlock.submit') }}">
            @csrf
            <label for="access_key">رمز دسترسی</label>
            <input type="password" id="access_key" name="access_key" required autofocus autocomplete="off" maxlength="256">
            @error('access_key') <p role="alert" class="unlock-error">{{ $message }}</p> @enderror
            <button type="submit">ورود به پنل یوان</button>
        </form>
        <small>پس از ورود، دسترسی شما تا ۱۲ ساعت معتبر است.</small>
    @else
        <p role="alert" class="unlock-error">رمز این بخش هنوز توسط مدیر سیستم تنظیم نشده است.</p>
    @endif
</main>
</body>
</html>
