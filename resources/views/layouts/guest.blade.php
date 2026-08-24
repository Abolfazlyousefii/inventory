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

	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

	@vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="text-slate-900 antialiased" style="font-family: 'Vazirmatn', sans-serif;">
<div class="min-h-screen flex flex-col items-center justify-center px-4 py-10 bg-gradient-to-br from-slate-50 via-white to-indigo-50">
	<div class="flex flex-col items-center justify-center text-center">
		<a href="/" class="flex justify-center">
			<x-application-logo class="w-24 h-24 object-contain drop-shadow-sm" />
		</a>

		<p class="mt-3 text-sm font-medium text-slate-500">
			{{ $appName }}
		</p>
	</div>

	<div class="w-full sm:max-w-md mt-8 px-6 py-7 bg-white shadow-xl shadow-slate-200/60 overflow-hidden rounded-3xl border border-slate-100">
		{{ $slot }}
	</div>
</div>
</body>
</html>
