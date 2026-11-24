@if($selectedUser)
<div data-staff-editor>
    <nav class="pw-tabs" aria-label="بخش‌های ویرایش پرسنل"><button type="button" data-staff-tab="profile" aria-pressed="true">مشخصات</button><button type="button" data-staff-tab="roles" aria-pressed="false">نقش‌ها</button><button type="button" data-staff-tab="permissions" aria-pressed="false">دسترسی‌ها</button><button type="button" data-staff-tab="account" aria-pressed="false">وضعیت حساب</button></nav>
    <div class="pw-editor-feedback" role="status" aria-live="polite"></div>
    <section data-staff-section="profile" class="pw-editor-section">@include('admin.permissions.partials.organization')</section>
    <section data-staff-section="account" class="pw-editor-section" hidden>
        <form method="post" action="{{ route('admin.personnel.accounts.status',$selectedUser) }}" data-account-editor>
            @csrf @method('PUT')
            <input type="hidden" name="account_version" value="{{ \App\Services\Personnel\PersonnelAccounts::version($selectedUser) }}">
            <div class="pw-account-card"><strong>دسترسی ورود به نرم‌افزار</strong><p>وضعیت حساب مستقل از نمایش در چارت است. غیرفعال‌کردن، سوابق، مشتریان، نقش‌ها و درخواست‌های قبلی را حذف نمی‌کند.</p>
            <label class="pw-field">وضعیت حساب<select name="is_active" @disabled(!$canEditPermissions)><option value="1" @selected($selectedUser->is_active && $selectedUser->can_access_erp)>فعال — ورود مجاز</option><option value="0" @selected(!$selectedUser->is_active || !$selectedUser->can_access_erp)>غیرفعال — ورود بسته</option></select></label>
            <small>نام حساب: {{ $selectedUser->name }} · موبایل: {{ $selectedUser->phone }}</small></div>
            @if($canEditPermissions)<footer class="pw-editor-footer"><span>نقش‌ها و عضویت در چارت حفظ می‌شوند.</span><button class="pw-button" type="submit">ذخیره وضعیت حساب</button></footer>@endif
        </form>
    </section>
    <form method="post" action="{{ route('admin.permissions.update',$selectedUser) }}" data-access-editor>
        @csrf @method('PUT')
        <input type="hidden" name="user_id" value="{{ $selectedUser->id }}">
        <input type="hidden" name="access_version" value="{{ \App\Services\Personnel\PersonnelAccessSnapshot::version($selectedUser) }}">
        <input type="hidden" name="permission_catalog_version" value="{{ \App\Support\PermissionCatalog::versionHash() }}">
        <input type="hidden" name="roles_submitted" value="1"><input type="hidden" name="roles_changed" value="0">
        <input type="hidden" name="direct_permissions_submitted" value="1"><input type="hidden" name="direct_permissions_changed" value="0">
        <section data-staff-section="roles" class="pw-editor-section" hidden>
            <p class="pw-hint">هر فرد می‌تواند چند نقش داشته باشد. حذف نقش، دسترسی‌هایی را که از آن نقش می‌گرفت تغییر می‌دهد.</p>
            <label class="pw-field">جستجوی نقش<input type="search" data-role-search placeholder="نام نقش…"></label>
            @php($primaryRoles=$roles->filter(fn($role)=>$role['selected'] || !$role['legacy']))
            @php($otherRoles=$roles->reject(fn($role)=>$role['selected'] || !$role['legacy']))
            <div class="pw-role-grid">@foreach($primaryRoles as $role)@include('users.partials.role-choice')@endforeach</div>
            <details class="pw-permission-group"><summary>سایر نقش‌های موجود <small>{{ $otherRoles->count() }} نقش</small></summary><div class="pw-role-grid">@foreach($otherRoles as $role)@include('users.partials.role-choice')@endforeach</div></details>
            <a class="pw-advanced" href="{{ route('admin.roles.index') }}">تعریف و ویرایش نقش‌های سازمانی ←</a>
        </section>
        <section data-staff-section="permissions" class="pw-editor-section" hidden>
            <p class="pw-hint">تیک‌ها دسترسی مستقیم را تعیین می‌کنند. دسترسی «از نقش» با برداشتن تیک مستقیم حذف نمی‌شود؛ برای حذف آن باید نقش مربوط تغییر کند.</p>
            <label class="pw-field">جستجوی دسترسی<input type="search" data-permission-search placeholder="عنوان دسترسی یا بخش…"></label>
            @php($editorModules=collect($effective)->where('active',true)->where('deprecated',false)->groupBy(fn($item)=>str_starts_with($item['key'],'page.')?'صفحات نرم‌افزار':$item['module_label']))
            @foreach($editorModules as $module=>$items)
                <details class="pw-permission-group"><summary>{{ $module }} <small>{{ $items->count() }}</small></summary>
                @foreach($items as $item)
                    <label class="pw-permission-choice" data-permission-choice><input type="checkbox" name="direct_permissions[]" value="{{ $item['key'] }}" data-dependencies='@json($item["depends_on"])' @checked(in_array($item['source'],['direct','both'])) @disabled(!$canEditPermissions)><span>{{ $item['label'] }}<small>{{ $item['risk_label'] }}</small></span>@if(in_array($item['source'],['role','both']))<small class="pw-inherited">از نقش</small>@endif</label>
                @endforeach</details>
            @endforeach
            @if($legacyPermissions->isNotEmpty())<p class="pw-hint">{{ $legacyPermissions->count() }} دسترسی قدیمی بدون تغییر حفظ می‌شود.</p>@endif
        </section>
        <footer class="pw-editor-footer" data-access-footer hidden><span data-access-change-note>تغییری ثبت نشده</span><button class="pw-button" type="submit" @disabled(!$canEditPermissions)>ذخیره نقش و دسترسی</button></footer>
    </form>
</div>
@else<div class="pw-empty">کاربر پیدا نشد؛ پنجره را ببندید و دوباره انتخاب کنید.</div>@endif
