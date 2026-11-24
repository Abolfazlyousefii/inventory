<dialog id="staffCreateDialog" aria-labelledby="staffCreateTitle">
    <header class="pw-dialog-head"><div><h2 id="staffCreateTitle">حساب جدید</h2><small>ساخت حساب داخلی · بدون نیاز به CRM</small></div><button type="button" data-close-create aria-label="بستن">×</button></header>
    <form method="post" action="{{ route('admin.personnel.accounts.store') }}" id="staffCreateForm" class="pw-create-form">
        @csrf
        <div class="pw-create-grid">
            <label class="pw-field">نام و نام خانوادگی<input name="name" required maxlength="255" autocomplete="name"></label>
            <label class="pw-field">موبایل ورود<input name="phone" required maxlength="30" inputmode="tel" autocomplete="off" placeholder="۰۹۱۲…" dir="ltr"></label>
            <label class="pw-field">رمز عبور<input type="password" name="password" required minlength="8" maxlength="128" autocomplete="new-password" placeholder="حداقل ۸ کاراکتر" dir="ltr"></label>
            <label class="pw-field">تکرار رمز<input type="password" name="password_confirmation" required minlength="8" maxlength="128" autocomplete="new-password" dir="ltr"></label>
            <label class="pw-field">مدیر مستقیم<select name="manager_id"><option value="">بدون مدیر</option>@foreach($users as $manager)@if($manager->is_active && $manager->can_access_erp && $manager->show_in_organization_chart)<option value="{{ $manager->id }}">{{ $manager->name }}</option>@endif @endforeach</select></label>
            <label class="pw-field">جایگاه سازمانی<select name="organization_level">@foreach(\App\Services\Personnel\OrganizationHierarchy::LEVELS as $value=>$label)<option value="{{ $value }}" @selected($value==='staff')>{{ $label }}</option>@endforeach</select></label>
            <label class="pw-field">واحد<input name="department" maxlength="100"></label>
            <label class="pw-field">کد پرسنلی <small>اختیاری</small><input name="personnel_code" maxlength="100"></label>
        </div>
        <div class="pw-create-checks"><label class="pw-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked>حساب فعال باشد</label><label class="pw-check"><input type="hidden" name="show_in_organization_chart" value="0"><input type="checkbox" name="show_in_organization_chart" value="1" checked>نمایش در چارت سازمانی</label></div>
        <p class="pw-hint">پس از ساخت، پنجرهٔ نقش و دسترسی باز می‌شود. تا تعیین مجوزهای کاری، دسترسی جدیدی به حساب داده نمی‌شود.</p>
        <div class="pw-editor-feedback" data-create-feedback role="status" aria-live="polite"></div>
        <footer class="pw-editor-footer"><span>رمز فقط هنگام ساخت ثبت می‌شود.</span><button class="pw-button" type="submit">ساخت و تعیین دسترسی</button></footer>
    </form>
</dialog>
