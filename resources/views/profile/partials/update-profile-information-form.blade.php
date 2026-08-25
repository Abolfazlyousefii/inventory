<section dir="rtl">
	<form id="send-verification" method="post" action="{{ route('verification.send') }}">@csrf</form>

	<form method="post" action="{{ route('profile.update') }}">
		@csrf
		@method('patch')

		<div class="mb-4">
			<h3 class="fw-bold mb-2">اطلاعات شخصی</h3>
			<p class="text-muted">اطلاعات اصلی حساب خود را مدیریت کنید.</p>
		</div>

		<div class="mb-4">
			<label class="form-label fw-bold" for="name">نام کامل</label>
			<input class="form-control form-control-lg rounded-3"
			       id="name"
			       name="name"
			       value="{{ old('name',$user->name) }}"
			       required>
			<x-input-error :messages="$errors->get('name')"/>
		</div>

		<div class="mb-4">
			<label class="form-label fw-bold" for="email">ایمیل</label>
			<input class="form-control form-control-lg rounded-3"
			       id="email"
			       name="email"
			       type="email"
			       value="{{ old('email',$user->email) }}"
			       required>
			<x-input-error :messages="$errors->get('email')"/>
		</div>

		<button class="btn btn-primary px-5 py-3 rounded-3">
			ذخیره اطلاعات
		</button>

	</form>
</section>
