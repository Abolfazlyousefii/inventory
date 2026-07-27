<x-guest-layout>
    <x-slot name="title">ورود</x-slot>
    <!-- Session Status -->
    <x-auth-session-status class="mb-6 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-700" :status="session('status')" />

    <div class="mb-6 text-center">
        <h1 class="text-2xl font-bold text-slate-800">ورود به حساب کاربری</h1>
        <p class="mt-2 text-sm text-slate-500">لطفاً شماره تلفن و رمز عبور خود را وارد کنید.</p>
    </div>

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <!-- Phone Number -->
        <div>
            <x-input-label for="phone" :value="'شماره تلفن'" class="text-slate-700" />
            <x-text-input id="phone" class="mt-2 block w-full rounded-xl border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:border-indigo-400 focus:bg-white" type="tel" name="phone" :value="old('phone')" required autofocus autocomplete="username" dir="ltr" placeholder="09123456789" inputmode="numeric" />
            <x-input-error :messages="$errors->get('phone')" class="mt-2 text-sm" />
        </div>

        <!-- Password -->
        <div>
            <x-input-label for="password" :value="'رمز عبور'" class="text-slate-700" />

            <x-text-input id="password" type="password"
                            name="password"
                            class="mt-2 block w-full rounded-xl border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:border-indigo-400 focus:bg-white"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2 text-sm" />
        </div>

        <!-- Remember Me -->
        <div class="block">
            <label for="remember_me" class="inline-flex items-center gap-2 text-sm text-slate-600">
                <input id="remember_me" type="checkbox" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500" name="remember">
                <span>مرا به خاطر بسپار</span>
            </label>
        </div>

        <div class="pt-2">
            <x-primary-button class="flex w-full items-center justify-center rounded-xl bg-indigo-600 py-3 text-sm font-bold normal-case tracking-normal hover:bg-indigo-700 focus:bg-indigo-700">
                ورود
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
