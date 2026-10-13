<x-guest-layout>
    <x-slot name="title">ورود با کد پیامکی</x-slot>
    <h1 class="text-2xl font-bold text-center mb-6">ورود با کد پیامکی</h1>
    <x-auth-session-status class="mb-4" :status="session('status')" />
    @if($errors->any())<div class="text-red-600 mb-4">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('login.otp.request') }}" class="space-y-4">
        @csrf
        <x-input-label for="phone" value="شماره موبایل" />
        <x-text-input id="phone" name="phone" type="tel" dir="ltr" inputmode="numeric" placeholder="09123456789" :value="old('phone', session('user_login_phone'))" required class="w-full" />
        <x-primary-button>ارسال کد ورود</x-primary-button>
    </form>
    @if(session()->has('user_login_phone'))
        <form method="POST" action="{{ route('login.otp.verify') }}" class="space-y-4 mt-8">
            @csrf
            <x-input-label for="code" value="کد شش رقمی" />
            <x-text-input id="code" name="code" type="text" dir="ltr" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required class="w-full" />
            <x-primary-button>تأیید و ورود</x-primary-button>
        </form>
    @endif
    <a href="{{ route('login') }}" class="block mt-6 text-indigo-600">ورود با رمز عبور</a>
</x-guest-layout>
