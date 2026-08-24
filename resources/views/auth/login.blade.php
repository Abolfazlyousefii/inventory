<x-guest-layout>
	<x-slot name="title">ورود</x-slot>

	<x-auth-session-status class="mb-6 rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-700" :status="session('status')" />

	<div class="mb-7 text-center">
		<h1 class="text-3xl font-black text-slate-800">خوش آمدید</h1>
		<p class="mt-3 text-sm text-slate-500">برای ورود به پنل، شماره تلفن و رمز عبور خود را وارد کنید.</p>
	</div>

	<form method="POST" action="{{ route('login') }}" class="space-y-5">
		@csrf

		<div>
			<x-input-label for="phone" :value="'شماره تلفن'" class="text-slate-700 font-semibold" />
			<x-text-input id="phone" class="mt-2 block w-full rounded-2xl border-slate-200 bg-slate-50 px-4 py-3.5 text-sm focus:border-indigo-400 focus:bg-white focus:ring-indigo-200" type="tel" name="phone" :value="old('phone')" required autofocus autocomplete="username" dir="ltr" placeholder="09123456789" inputmode="numeric" />
			<x-input-error :messages="$errors->get('phone')" class="mt-2 text-sm" />
		</div>

		<div>
			<x-input-label for="password" :value="'رمز عبور'" class="text-slate-700 font-semibold" />
			<x-text-input id="password" type="password" name="password" class="mt-2 block w-full rounded-2xl border-slate-200 bg-slate-50 px-4 py-3.5 text-sm focus:border-indigo-400 focus:bg-white focus:ring-indigo-200" required autocomplete="current-password" />
			<x-input-error :messages="$errors->get('password')" class="mt-2 text-sm" />
		</div>

		<div>
			<label for="remember_me" class="inline-flex items-center gap-2 text-sm text-slate-600">
				<input id="remember_me" type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" name="remember">
				<span>مرا به خاطر بسپار</span>
			</label>
		</div>

		<div class="pt-2">
			<x-primary-button class="flex w-full items-center justify-center rounded-2xl bg-indigo-600 py-3.5 text-sm font-bold normal-case tracking-normal hover:bg-indigo-700 focus:bg-indigo-700">
				ورود به حساب
			</x-primary-button>
		</div>
	</form>

	@if(config('crm.sso.enabled'))
		<div class="my-5 flex items-center gap-3" aria-hidden="true">
			<span class="h-px flex-1 bg-slate-200"></span>
			<span class="text-xs text-slate-400">یا</span>
			<span class="h-px flex-1 bg-slate-200"></span>
		</div>
	@endif
</x-guest-layout>
