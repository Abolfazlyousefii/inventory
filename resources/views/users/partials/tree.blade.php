<section id="staffTree" class="pw-tree {{ $directoryTab === 'accounts' ? 'pw-accounts-panel' : '' }}" aria-label="{{ $directoryTab === 'accounts' ? 'حساب‌های مدیریتی' : 'ساختار سازمانی پرسنل' }}" data-count="{{ $directoryTab === 'accounts' ? $accounts->count() : count($rows) }}" data-chart-count="{{ $chartCount }}" data-account-count="{{ $accountCount }}" data-directory-tab="{{ $directoryTab }}" data-filtered="{{ $filtered ? '1':'0' }}">
    @if($directoryTab === 'accounts')
        <div class="pw-accounts-intro"><strong>حساب‌های خارج از چارت</strong><p>مدیریت حساب و دسترسی، مستقل از ساختار مدیر و زیرمجموعه</p></div>
        <div class="pw-account-grid">
        @forelse($accounts as $person)
            <article class="pw-account">
                <span class="pw-avatar" aria-hidden="true">{{ mb_substr($person->name,0,1) }}</span>
                <strong>{{ $person->name }}</strong>
                <small>{{ $person->is_crm_managed ? 'حساب منتقل‌شده از CRM' : 'حساب سامانه' }} · کد {{ $person->id }}</small>
                <div class="pw-account-state"><span class="pw-state {{ $person->is_active && $person->can_access_erp ? 'pw-active':'' }}">{{ $person->is_active && $person->can_access_erp ? 'فعال':'غیرفعال' }}</span><small>خارج از چارت سازمانی</small></div>
                @if($canManage)<button type="button" class="pw-button pw-edit" data-edit-staff="{{ $person->id }}" data-editor-url="{{ route('admin.permissions.index',['panel'=>1,'user_id'=>$person->id]) }}" data-person-name="{{ $person->name }}">مدیریت حساب و دسترسی ↗</button>@endif
            </article>
        @empty<div class="pw-empty">حسابی با این مشخصات پیدا نشد.</div>@endforelse
        </div>
    @else
        <ul class="pw-tree-list">
        @forelse(($children['root'] ?? collect())->sortBy(fn($row)=>in_array($row['user']->organization_level,['ceo','executive_manager']) ? 0 : 1) as $row)
            @include('users.partials.branch',['row'=>$row])
        @empty<li class="pw-empty">پرسنلی با این مشخصات پیدا نشد.</li>@endforelse
        </ul>
    @endif
</section>
