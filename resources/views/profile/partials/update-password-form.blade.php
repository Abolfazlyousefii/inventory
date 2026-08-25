<section dir="rtl">
	<form method="post" action="{{ route('password.update') }}">
		@csrf
		@method('put')

		<h3 class="fw-bold mb-2">امنیت حساب</h3>
		<p class="text-white-50 mb-4">رمز عبور حساب خود را تغییر دهید.</p>

		<div class="mb-3">
			<label class="form-label">رمز فعلی</label>
			<input class="form-control rounded-3" name="current_password" type="password">
		</div>

		<div class="mb-3">
			<label class="form-label">رمز جدید</label>
			<input class="form-control rounded-3" name="password" type="password">
		</div>

		<div class="mb-4">
			<label class="form-label">تکرار رمز جدید</label>
			<input class="form-control rounded-3" name="password_confirmation" type="password">
		</div>

		<button class="btn btn-light px-5 py-3 rounded-3">
			تغییر رمز
		</button>

	</form>
</section>
