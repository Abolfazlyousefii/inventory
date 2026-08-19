@php
    $defaultPhones = $customer
        ? $customer->phones->map(fn ($phone) => ['phone' => $phone->phone])->values()->all()
        : [['phone' => '']];
    $defaultPrimary = $customer
        ? max(0, $customer->phones->search(fn ($phone) => $phone->is_primary))
        : 0;
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <x-input label="نام مشتری" name="name" :value="$customer?->name" required />
    <x-input label="نام شرکت / فروشگاه / مجموعه" name="company_name" :value="$customer?->company_name" />
    <x-input label="شهر" name="city" :value="$customer?->city" />
    <label class="flex min-h-11 items-center gap-2 self-end rounded-lg border border-gray-200 px-3">
        <input type="checkbox" name="is_active" value="1" class="accent-emerald-500" @checked(old('is_active', $customer?->is_active ?? true))>
        <span>مشتری فعال باشد</span>
    </label>
</div>

<div class="mt-5 grid gap-4 sm:grid-cols-2">
    <div>
        <label for="address" class="form-label">آدرس</label>
        <textarea id="address" name="address" rows="4" class="form-control">{{ old('address', $customer?->address) }}</textarea>
        @error('address')<p class="form-error">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="notes" class="form-label">یادداشت داخلی</label>
        <textarea id="notes" name="notes" rows="4" class="form-control">{{ old('notes', $customer?->notes) }}</textarea>
        @error('notes')<p class="form-error">{{ $message }}</p>@enderror
    </div>
</div>

<section class="mt-6 rounded-xl border border-gray-200 p-4 sm:p-5">
    <div class="mb-4 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-sm font-bold text-gray-900">ورود به پنل مشتری</h2>
            <p class="mt-1 text-xs leading-6 text-gray-500">
                {{ $customer ? 'برای تغییر رمز، رمز جدید را وارد کنید. خالی گذاشتن این بخش رمز فعلی را حفظ می‌کند.' : 'اختیاری است؛ مشتری همچنان می‌تواند با کد یکبار مصرف وارد شود.' }}
            </p>
        </div>
        @if($customer)
            <span class="mt-2 inline-flex w-fit rounded-full px-2.5 py-1 text-xs font-bold {{ filled($customer->password) ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600' }} sm:mt-0">
                {{ filled($customer->password) ? 'رمز تعریف شده' : 'بدون رمز' }}
            </span>
        @endif
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="password" class="form-label">{{ $customer ? 'رمز عبور جدید' : 'رمز عبور' }}</label>
            <input id="password" name="password" type="password" class="form-control" autocomplete="new-password" minlength="8" placeholder="حداقل ۸ کاراکتر">
            @error('password')<p class="form-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="password_confirmation" class="form-label">تکرار رمز عبور</label>
            <input id="password_confirmation" name="password_confirmation" type="password" class="form-control" autocomplete="new-password" minlength="8" placeholder="تکرار رمز عبور">
        </div>
    </div>
</section>

<fieldset
    class="mt-6 rounded-xl border border-gray-200 p-4"
    x-data="{
        phones: @js(old('phones', $defaultPhones)),
        primary: String(@js(old('primary_phone', $defaultPrimary))),
        add() { this.phones.push({ phone: '' }) },
        remove(index) { this.phones.splice(index, 1); this.primary = '0' }
    }"
>
    <legend class="px-2 text-sm font-bold">شماره‌های موبایل <span class="text-red-600">*</span></legend>
    <div class="space-y-3">
        <template x-for="(item, index) in phones" :key="index">
            <div class="flex flex-col gap-2 rounded-lg bg-gray-50 p-3 sm:flex-row sm:items-end">
                <div class="min-w-0 flex-1">
                    <label class="form-label" :for="'phone-' + index" x-text="index === 0 ? 'شماره موبایل' : 'شماره دیگر'"></label>
                    <input class="form-control" :id="'phone-' + index" :name="'phones[' + index + '][phone]'" x-model="item.phone" inputmode="numeric" dir="ltr" required>
                </div>
                <label class="flex min-h-11 shrink-0 items-center gap-2 px-2">
                    <input type="radio" name="primary_phone" :value="index" x-model="primary" class="accent-emerald-500">
                    <span>شماره اصلی</span>
                </label>
                <button type="button" class="btn btn-danger shrink-0" x-show="phones.length > 1" @click="remove(index)">حذف</button>
            </div>
        </template>
    </div>
    @foreach($errors->get('phones.*.phone') as $messages)
        @foreach($messages as $message)<p class="form-error">{{ $message }}</p>@endforeach
    @endforeach
    @error('phones')<p class="form-error">{{ $message }}</p>@enderror
    @error('primary_phone')<p class="form-error">{{ $message }}</p>@enderror
    <button type="button" class="btn btn-secondary mt-3" @click="add()">+ افزودن شماره دیگر</button>
</fieldset>

<div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row">
    <a href="{{ $customer ? route('customers.show', $customer) : route('customers.index') }}" class="btn btn-secondary">انصراف</a>
    <x-button>ذخیره</x-button>
</div>
