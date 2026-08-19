<x-layouts.guest title="ورود مشتریان">
    <section class="w-full max-w-sm" x-data="{ mode: @js(old('login_mode', 'password')) }">
        <div class="mb-7 text-center">
            <span class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-lg bg-brand text-lg font-black text-emerald-950">ج</span>
            <strong class="text-lg">پشتیبانی جهش</strong>
            <p class="mt-1 text-sm text-gray-500">ورود مشتریان</p>
        </div>

        <div class="panel p-5 sm:p-7">
            <h1 class="mb-2 text-xl font-bold">ورود به حساب</h1>
            <p class="mb-5 text-sm leading-6 text-gray-500">با رمز عبور وارد شوید یا کد یکبار مصرف دریافت کنید.</p>
            <x-alert />

            <div class="mb-5 grid grid-cols-2 rounded-xl bg-gray-100 p-1" role="tablist" aria-label="روش ورود">
                <button
                    type="button"
                    class="rounded-lg px-3 py-2.5 text-sm font-bold transition"
                    :class="mode === 'password' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'"
                    @click="mode = 'password'"
                >رمز عبور</button>
                <button
                    type="button"
                    class="rounded-lg px-3 py-2.5 text-sm font-bold transition"
                    :class="mode === 'otp' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'"
                    @click="mode = 'otp'"
                >کد یکبار مصرف</button>
            </div>

            <form x-show="mode === 'password'" method="POST" action="{{ route('portal.login.password') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="login_mode" value="password">
                <x-input label="شماره موبایل" name="phone" inputmode="numeric" autocomplete="tel" placeholder="۰۹۱۲۳۴۵۶۷۸۹" required />
                <div>
                    <label for="customer-password" class="form-label">رمز عبور</label>
                    <input id="customer-password" name="password" type="password" class="form-control" autocomplete="current-password" required>
                    @error('password')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <x-button class="w-full">ورود به پنل</x-button>
            </form>

            <form x-show="mode === 'otp'" x-cloak method="POST" action="{{ route('portal.login.request') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="login_mode" value="otp">
                <x-input label="شماره موبایل" name="phone" inputmode="numeric" autocomplete="tel" placeholder="۰۹۱۲۳۴۵۶۷۸۹" required />
                <x-button class="w-full">دریافت کد ورود</x-button>
            </form>
        </div>
    </section>
</x-layouts.guest>
