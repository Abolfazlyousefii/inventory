<section id="staffTree" class="pw-tree" aria-label="ساختار سازمانی پرسنل" data-count="{{ count($rows) }}" data-filtered="{{ $filtered ? '1':'0' }}">
    @forelse($rows as $row)
        @php($person=$row['user'])
        @php($roleLabels=$person->roles->reject(fn($role)=>\Illuminate\Support\Str::startsWith($role->name,['Org:','local_']))->map(fn($role)=>\App\Support\PermissionCatalog::roleLabel($role->name))->unique())
        <div class="pw-node {{ $row['context'] ? 'pw-context':'' }}" data-level="{{ $person->organization_level ?: 'staff' }}" data-staff-node="{{ $person->id }}" data-parent="{{ $row['parent'] }}" style="--staff-depth:{{ min($row['depth'],8) }}" @if(!$filtered && $row['depth']>0) hidden @endif>
            <div class="pw-person">
                @if($row['has_children'])<button type="button" class="pw-toggle" data-toggle-node="{{ $person->id }}" aria-expanded="{{ $filtered?'true':'false' }}" aria-label="نیروهای {{ $person->name }}">{{ $filtered?'−':'+' }}</button>@else<span class="pw-leaf" aria-hidden="true">·</span>@endif
                <span class="pw-avatar" aria-hidden="true">{{ mb_substr($person->name,0,1) }}</span>
                <div><strong>{{ $person->name }}</strong><small>{{ $person->position ?: ($person->department ?: 'سمت تعیین نشده') }}@if($person->personnel_code) · {{ $person->personnel_code }}@endif</small>
                    @if($row['issue'])<small class="pw-warning">رابطه مدیریتی نیازمند بررسی است</small>@endif
                    @if($row['context'])<small>مدیر بالادست نتیجه جستجو</small>@endif
                </div>
            </div>
            <div class="pw-node-meta"><span class="pw-role-label">{{ $roleLabels->take(2)->join('، ') ?: 'بدون نقش سازمانی' }}@if($roleLabels->count()>2)<small> +{{ $roleLabels->count()-2 }}</small>@endif</span><span class="pw-state {{ $person->is_active && $person->can_access_erp ? 'pw-active':'' }}">{{ $person->is_active && $person->can_access_erp ? 'فعال':'غیرفعال' }}</span>
            @if($canManage)<button type="button" class="pw-button pw-edit" data-edit-staff="{{ $person->id }}" data-editor-url="{{ route('admin.permissions.index',['panel'=>1,'user_id'=>$person->id]) }}" data-person-name="{{ $person->name }}">ویرایش</button>@endif</div>
        </div>
    @empty<div class="pw-empty">پرسنلی با این مشخصات پیدا نشد.</div>@endforelse
</section>
