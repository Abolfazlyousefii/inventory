@extends('layouts.app')
@section('title', 'اطلاعات واریز فاکتور مشتری')

@push('styles')
<style>
.invoice-bank-settings{max-width:1000px;margin:0 auto;padding:22px 12px 50px;direction:rtl;color:#17364a;font-family:Vazirmatn,Tahoma,sans-serif}
.invoice-bank-settings *{box-sizing:border-box}
.invoice-bank-settings__head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}
.invoice-bank-settings__head h1{margin:0;font-size:24px;line-height:1.6;font-weight:850}
.invoice-bank-settings__head p{margin:3px 0 0;color:#688091;font-size:13px}
.invoice-bank-settings__eyebrow{display:block;color:#168066;font-size:11px;font-weight:800;margin-bottom:2px}
.invoice-bank-settings__card{background:#fff;border:1px solid #dbe6ed;border-radius:17px;box-shadow:0 9px 35px #1b47610b;padding:24px}
.invoice-bank-settings__card h2{font-size:17px;font-weight:800;margin:0 0 7px}
.invoice-bank-settings__intro{font-size:12px;color:#687f8d;margin:0 0 22px;line-height:1.85}
.invoice-bank-settings__fields{display:grid;grid-template-columns:1fr 1fr;gap:19px}
.invoice-bank-settings__field{display:flex;flex-direction:column;min-width:0;gap:7px}
.invoice-bank-settings__field--wide{grid-column:1/-1}
.invoice-bank-settings__field label{font-size:12px;font-weight:750;color:#2e5063}
.invoice-bank-settings__field input{display:block;width:100%;min-height:46px;border-radius:10px;border:1px solid #cfdfe7;padding:9px 12px;background:#fbfdfe;font-size:14px;color:#183e56}
.invoice-bank-settings__field input:focus{outline:3px solid #bfebe2;outline-offset:1px;border-color:#138466;background:#fff}
.invoice-bank-settings__field input[dir=ltr]{font-family:Tahoma,Arial,sans-serif;text-align:left;letter-spacing:.2px}
.invoice-bank-settings__field small{font-size:11px;color:#8295a2}
.invoice-bank-settings__error{font-size:11px;color:#b83232;font-weight:700}
.invoice-bank-settings__alert{border-radius:11px;padding:12px 15px;margin:0 0 16px;border:1px solid #baded3;background:#edfaf5;color:#176a54;font-size:12px;line-height:1.8}
.invoice-bank-settings__alert--danger{border-color:#f0cdc8;background:#fff6f4;color:#93382e}
.invoice-bank-settings__footer{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:22px;padding-top:18px;border-top:1px solid #e5edf1}
.invoice-bank-settings__footer p{max-width:540px;margin:0;color:#69818f;font-size:11px;line-height:1.8}
.invoice-bank-settings__save{border:0;border-radius:10px;padding:11px 24px;min-height:44px;background:#137f67;color:#fff;font-size:13px;font-weight:800;cursor:pointer}
.invoice-bank-settings__save:hover{background:#086b55}
.invoice-bank-settings__save:disabled{background:#9ab3aa;cursor:not-allowed}
@media(max-width:680px){.invoice-bank-settings{padding:12px 6px 30px}.invoice-bank-settings__card{padding:17px 13px}.invoice-bank-settings__fields{grid-template-columns:1fr;gap:16px}.invoice-bank-settings__field--wide{grid-column:auto}.invoice-bank-settings__head h1{font-size:21px}.invoice-bank-settings__save{width:100%}}
</style>
@endpush

@section('content')
<main class="invoice-bank-settings">
    <header class="invoice-bank-settings__head">
        <div>
            <span class="invoice-bank-settings__eyebrow">پیکربندی / چاپ فاکتور</span>
            <h1>اطلاعات واریز فاکتور مشتری</h1>
            <p>مشخصات بانکی نمایش‌داده‌شده در پایین چاپ فاکتور فروش، نسخه مشتری</p>
        </div>
    </header>

    @if(session('success'))
        <div class="invoice-bank-settings__alert" role="status">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="invoice-bank-settings__alert invoice-bank-settings__alert--danger" role="alert">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="invoice-bank-settings__alert invoice-bank-settings__alert--danger" role="alert">
            لطفاً اطلاعات نامعتبر را اصلاح کنید و دوباره ذخیره کنید.
        </div>
    @endif
    @unless($dbReady)
        <div class="invoice-bank-settings__alert invoice-bank-settings__alert--danger" role="alert">
            جدول تنظیمات هنوز ایجاد نشده است. پس از اجرای migration می‌توانید اطلاعات را در پنل ذخیره کنید. تا آن زمان مقادیر خصوصی فایل env برای چاپ استفاده می‌شوند.
        </div>
    @endunless

    <section class="invoice-bank-settings__card">
        <h2>مشخصات حساب برای واریز مشتری</h2>
        <p class="invoice-bank-settings__intro">اطلاعات این فرم فقط در نسخه مشتریِ چاپ فاکتور فروش نمایش داده می‌شوند. برای حذف هر مقدار، همان فیلد را خالی بگذارید و ذخیره کنید. این تنظیمات روی مانده مالی و اطلاعات فاکتورها اثری ندارند.</p>

        <form method="POST" action="{{ route('admin.invoice-payment-settings.update') }}" autocomplete="off">
            @csrf
            @method('PUT')
            <div class="invoice-bank-settings__fields">
                <div class="invoice-bank-settings__field invoice-bank-settings__field--wide">
                    <label for="invoicePaymentName">نام صاحب حساب</label>
                    <input id="invoicePaymentName" name="name" type="text" value="{{ old('name', $details['name'] ?? '') }}" maxlength="150" placeholder="نام و نام خانوادگی صاحب حساب">
                    @error('name')<span class="invoice-bank-settings__error">{{ $message }}</span>@enderror
                </div>

                <div class="invoice-bank-settings__field">
                    <label for="invoicePaymentNationalId">شماره ملی صاحب حساب</label>
                    <input id="invoicePaymentNationalId" name="national_id" type="text" inputmode="numeric" dir="ltr" value="{{ old('national_id', $details['national_id'] ?? '') }}" maxlength="15" placeholder="۱۰ رقم">
                    @error('national_id')<span class="invoice-bank-settings__error">{{ $message }}</span>@enderror
                </div>

                <div class="invoice-bank-settings__field">
                    <label for="invoicePaymentAccount">شماره حساب</label>
                    <input id="invoicePaymentAccount" name="account" type="text" inputmode="numeric" dir="ltr" value="{{ old('account', $details['account'] ?? '') }}" maxlength="35" placeholder="شماره حساب بانکی">
                    @error('account')<span class="invoice-bank-settings__error">{{ $message }}</span>@enderror
                </div>

                <div class="invoice-bank-settings__field invoice-bank-settings__field--wide">
                    <label for="invoicePaymentIban">شماره شبا</label>
                    <input id="invoicePaymentIban" name="iban" type="text" dir="ltr" value="{{ old('iban', $details['iban'] ?? '') }}" maxlength="34" placeholder="IR و ۲۴ رقم" spellcheck="false">
                    <small>شماره شبا با IR شروع می‌شود و شامل ۲۴ رقم بعد از آن است.</small>
                    @error('iban')<span class="invoice-bank-settings__error">{{ $message }}</span>@enderror
                </div>

                <div class="invoice-bank-settings__field invoice-bank-settings__field--wide">
                    <label for="invoicePaymentCard">شماره کارت</label>
                    <input id="invoicePaymentCard" name="card" type="text" inputmode="numeric" dir="ltr" value="{{ old('card', $details['card'] ?? '') }}" maxlength="24" placeholder="0000-0000-0000-0000">
                    <small>شماره ۱۶ رقمی را با یا بدون خط تیره وارد کنید.</small>
                    @error('card')<span class="invoice-bank-settings__error">{{ $message }}</span>@enderror
                </div>
            </div>

            <footer class="invoice-bank-settings__footer">
                <p>اطلاعات در دیتابیس به‌صورت رمزگذاری‌شده نگهداری می‌شوند. دسترسی به این صفحه فقط برای کاربران مجاز ویرایش تنظیمات است.</p>
                <button class="invoice-bank-settings__save" type="submit" @disabled(!$dbReady)>ذخیره اطلاعات واریز</button>
            </footer>
        </form>
    </section>
</main>
@endsection
