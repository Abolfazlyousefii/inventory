<!DOCTYPE html>
<html lang="fa" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @php
            $appName = config('app.name', 'نرم افزار داخلی آریا گستر');
            $guestPageTitle = isset($title)
                ? trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $title)))
                : '';
            $guestDocumentTitle = match (true) {
                $guestPageTitle === '' => $appName,
                str_contains($guestPageTitle, $appName) => $guestPageTitle,
                default => $guestPageTitle.' | '.$appName,
            };
        @endphp
        <title>{{ $guestDocumentTitle }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="text-gray-900 antialiased" style="font-family: 'Vazirmatn', sans-serif;">
        <div class="min-h-screen flex flex-col sm:justify-center items-center px-4 py-8 sm:pt-0 bg-gray-100">
           <div class="flex flex-col items-center justify-center text-center">
    <a href="/" class="flex justify-center">
        <x-application-logo class="w-20 h-20 sm:w-16 sm:h-16 object-contain " />
    </a>

    <p class="mt-2 text-xs sm:text-sm text-gray-500">
        {{ $appName }}
    </p>
</div>
            <div class="w-full sm:max-w-md mt-6 px-6 py-6 bg-white shadow-sm overflow-hidden rounded-xl border border-gray-200">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
