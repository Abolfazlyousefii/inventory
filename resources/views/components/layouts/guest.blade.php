@props(['title' => ''])
<!doctype html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $title }} | {{ config('app.name') }}</title></head>
<body>{{ $slot }}</body>
</html>
