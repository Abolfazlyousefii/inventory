<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>دسترسی تعریف نشده</title>
</head>
<body>
    <main style="max-width: 42rem; margin: 10vh auto; padding: 2rem; font-family: Tahoma, sans-serif; text-align: center">
        <h1>دسترسی برای شما تعریف نشده است</h1>
        <p>لطفاً برای تعیین نقش و دسترسی با مدیر سامانه تماس بگیرید.</p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">خروج</button>
        </form>
    </main>
</body>
</html>
