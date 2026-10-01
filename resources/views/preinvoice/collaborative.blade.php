@extends('layouts.app')
@section('content')
	<div class="container py-4" dir="rtl" id="collaborative-editor"
	     data-state="{{ route('preinvoice.collaborative.state', $order->uuid) }}"
	     data-change="{{ route('preinvoice.collaborative.change', $order->uuid) }}"
	     data-search="{{ route('preinvoice.collaborative.search', $order->uuid) }}">
		<h4>ویرایش اقلام پیش‌فاکتور {{ $order->uuid }}</h4>
		<p>تغییرات ذخیره‌شده در تب‌های دیگر نیز نمایش داده می‌شوند. قیمت‌ها به ریال هستند.</p>
		<div id="live-message" class="alert alert-info" role="status">در حال دریافت سند…</div>
		<label for="live-reason">دلیل ویرایش</label><input id="live-reason" class="form-control mb-3" maxlength="1000" placeholder="دلیل تغییر اقلام را بنویسید">
		<div class="table-responsive"><table class="table"><thead><tr><th>کالا / تنوع</th><th>تعداد</th><th>قیمت واحد</th><th>عملیات</th></tr></thead><tbody id="live-items"></tbody></table></div>
		<strong id="live-total"></strong>
		<div class="card mt-3"><div class="card-body"><label for="live-search">افزودن کالا</label><input id="live-search" class="form-control" placeholder="نام کالا یا تنوع (حداقل دو حرف)"><div id="live-results" class="list-group mt-2"></div></div></div>
		<p class="text-muted mt-3">افزودن، حذف و تغییر تعداد همراه با بررسی موجودی و رزرو انجام می‌شود. سند تبدیل‌شده از مسیر اصلاح فاکتور ویرایش می‌شود.</p>
	</div>
	<script src="{{ asset('js/preinvoice-collaborative.js') }}"></script>
@endsection
