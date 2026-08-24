<x-guest-layout>
	<x-slot name="title">تنظیم رمز عبور جدید</x-slot>

	<div class="mb-7 text-center">
		<h1 class="text-2xl font-black text-slate-800">تنظیم رمز عبور جدید</h1>
		<p class="mt-3 text-sm text-slate-500">رمز عبور جدید خود را وارد و تایید کنید.</p>
	</div>

	<form method="POST" action="{{ route('password.store') }}" class="space-y-5">
		@csrf

		<input type="hidden" name="token" value="{{ $request->route('token') }}">

		<div>
			<x-input-label for="email" :value="'ایمیل'" class="text-slate-700 font-semibold" />
			<x-text-input id="email" class="mt-2 block w-full rounded-2xl border-slate-200 bg-slate-50 px-4 py-3.5 text-sm focus:border-indigo-400 focus:bg-white focus:ring-indigo-200" type="email" name="email" :value="old('email', $request->email)" required autofocus autocomplete="username" />
			<x-input-error :messages="$errors->get('email')" class="mt-2" />
		</div>

		<div>
			<x-input-label for="password" :value="'رمز عبور جدید'" class="text-slate-700 font-semibold" />
			<x-text-input id="password" class="mt-2 block w-full rounded-2xl border-slate-200 bg-slate-50 px-4 py-3.5 text-sm focus:border-indigo-400 focus:bg-white focus:ring-indigo-200" type="password" name="password" required autocomplete="new-password" />
			<x-input-error :messages="$errors->get('password')" class="mt-2" />
		</div>

		<div>
			<x-input-label for="password_confirmation" :value="'تکرار رمز عبور'" class="text-slate-700 font-semibold" />
			<x-text-input id="password_confirmation" class="mt-2 block w-full rounded-2xl border-slate-200 bg-slate-50 px-4 py-3.5 text-sm focus:border-indigo-400 focus:bg-white focus:ring-indigo-200" type="password" name="password_confirmation" required autocomplete="new-password" />
			<x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
		</div>

		<div class="pt-2 flex justify-end">
			<x-primary-button class="rounded-2xl bg-indigo-600 px-6 py-3 text-sm font-bold hover:bg-indigo-700 focus:bg-indigo-700">
				ذخیره رمز عبور
			</x-primary-button>
		</div>
	</form>
</x-guest-layout>
