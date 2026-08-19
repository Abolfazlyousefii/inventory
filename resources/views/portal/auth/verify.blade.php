<!doctype html><html lang="fa" dir="rtl"><body>
<form method="POST" action="{{ route('portal.verify.submit') }}">@csrf
    <input name="code" inputmode="numeric" maxlength="6">
    <button type="submit">ورود</button>
</form>
<form method="POST" action="{{ route('portal.resend') }}">@csrf<button type="submit">ارسال مجدد</button></form>
</body></html>
