<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'نرم افزار داخلی آریا گستر') }}</title>
    <link rel="stylesheet" href="{{ asset('css/Vazirmatn.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin-theme.css') }}">
    <style>
        *{box-sizing:border-box}body{min-height:100vh;margin:0;display:grid;place-items:center;padding:20px;color:var(--app-text);background:var(--app-bg);font-family:"Vazirmatn",sans-serif}.welcome-card{width:min(430px,100%);padding:32px;text-align:center;background:var(--app-surface);border:1px solid var(--app-border);border-radius:var(--app-radius-lg);box-shadow:var(--app-shadow-sm)}.welcome-card img{width:72px;height:72px;object-fit:contain}.welcome-card h1{margin:16px 0 6px;color:var(--app-primary);font-size:1.25rem}.welcome-card p{margin:0 0 22px;color:var(--app-muted);font-size:.85rem}.welcome-card a{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:8px 18px;color:#fff;background:var(--app-primary);border-radius:var(--app-radius-sm);font-size:.82rem;font-weight:700;text-decoration:none}.welcome-card a:hover{background:var(--app-primary-hover)}
    </style>
</head>
<body>
    <main class="welcome-card">
        <img src="{{ asset('logo.png') }}" alt="{{ config('app.name') }}">
        <h1>{{ config('app.name', 'نرم افزار داخلی آریا گستر') }}</h1>
        <p>سامانه یکپارچه مدیریت عملیات داخلی شرکت</p>
        <a href="{{ auth()->check() ? route('dashboard') : route('login') }}">
            {{ auth()->check() ? 'ورود به داشبورد' : 'ورود به سامانه' }}
        </a>
    </main>
</body>
</html>
