<section class="access-card">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <h3 class="mb-0">مشخصات سازمانی {{ $selectedUser->name }}</h3>
        <span class="badge text-bg-light">{{ $selectedUser->organization_managed_locally ? 'مدیریت ساختار در INV' : ($selectedUser->is_crm_managed ? 'ساختار فعلی از CRM' : 'کاربر سامانه') }}</span>
    </div>
    <form method="post" action="{{ route('admin.personnel.organization.update', $selectedUser) }}">
        @csrf @method('PUT')
        <input type="hidden" name="organization_version" value="{{ \App\Services\Personnel\OrganizationManagement::version($selectedUser) }}">
        <div class="row g-3">
            <div class="col-12"><label for="staffName" class="form-label">نام و نام خانوادگی</label><input id="staffName" class="form-control" name="name" required maxlength="255" value="{{ old('name', $selectedUser->name) }}" @disabled(!$canEditPermissions)></div>
            <div class="col-12">
                <input type="hidden" name="show_in_organization_chart" value="0" @disabled(!$canEditPermissions)>
                <label class="pw-chart-membership"><input type="checkbox" name="show_in_organization_chart" value="1" @checked(old('show_in_organization_chart',$selectedUser->show_in_organization_chart)) @disabled(!$canEditPermissions)> نمایش در چارت سازمانی</label>
                <small class="d-block text-muted mt-1">با برداشتن تیک، حساب به «حساب‌های مدیریتی» منتقل می‌شود. نقش، دسترسی و مسیر تأیید درخواست‌ها تغییر نمی‌کند.</small>
            </div>
            <div class="col-md-6"><label for="staffLevel" class="form-label">جایگاه سازمانی</label><select id="staffLevel" class="form-select" name="organization_level" @disabled(!$canEditPermissions)><option value="">تعیین نشده</option>@foreach(\App\Services\Personnel\OrganizationHierarchy::LEVELS as $level=>$label)<option value="{{ $level }}" @selected(old('organization_level',$selectedUser->organization_level)===$level)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-md-6"><label for="staffManager" class="form-label">مدیر مستقیم</label>
                <select id="staffManager" name="manager_id" class="form-select" @disabled(!$canEditPermissions)>
                    <option value="">بدون مدیر — رأس سازمان یا حساب مستقل</option>
                    @foreach($users as $manager)
                        @if($manager->id !== $selectedUser->id && (($manager->is_active && $manager->can_access_erp && $manager->show_in_organization_chart) || $manager->id === $selectedUser->manager_id))
                            <option value="{{ $manager->id }}" @selected((string)old('manager_id', $selectedUser->manager_id)===(string)$manager->id)>{{ $manager->name }}{{ !$manager->is_active || !$manager->can_access_erp ? ' — غیرفعال' : '' }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
            @foreach(['department'=>'واحد سازمانی','position'=>'سمت','personnel_code'=>'کد پرسنلی','branch'=>'شعبه'] as $field=>$label)
                <div class="col-md-6"><label for="staff-{{ $field }}" class="form-label">{{ $label }}</label><input id="staff-{{ $field }}" class="form-control" name="{{ $field }}" maxlength="100" value="{{ old($field, $selectedUser->$field) }}" @disabled(!$canEditPermissions)></div>
            @endforeach
        </div>
        <p class="text-muted small mt-3 mb-2">با ذخیره، این مشخصات و مدیر مستقیم در INV مدیریت می‌شوند و همگام‌سازی CRM آن‌ها را بازنویسی نمی‌کند. نقش و دسترسی از بخش‌های پایین، جداگانه ذخیره می‌شوند.</p>
        @if($canEditPermissions)<button class="btn btn-primary">ذخیره مشخصات سازمانی</button>@endif
    </form>
</section>
