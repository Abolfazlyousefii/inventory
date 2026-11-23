@php($person=$row['user'])
<li class="pw-tree-item" data-staff-node="{{ $person->id }}" data-parent="{{ $row['parent'] }}">
    <div class="pw-node {{ $row['context'] ? 'pw-context':'' }}" data-level="{{ $person->organization_level ?: 'staff' }}" data-root="{{ $row['parent'] === null ? '1' : '0' }}">
        <div class="pw-person">
            @if($row['has_children'])<button type="button" class="pw-toggle" data-toggle-node="{{ $person->id }}" aria-expanded="true" aria-controls="staff-children-{{ $person->id }}" aria-label="نیروهای {{ $person->name }}">−</button>@else<span class="pw-leaf" aria-hidden="true">•</span>@endif
            <span class="pw-avatar" aria-hidden="true">{{ mb_substr($person->name,0,1) }}</span>
            <div><strong>{{ $person->name }}</strong><small><span class="pw-position">{{ $person->position ?: ($person->department ?: 'سمت تعیین نشده') }}</span>@if($person->personnel_code) · {{ $person->personnel_code }}@endif @if($row['has_children'])<span class="pw-direct-count"> · {{ ($children[$person->id] ?? collect())->count() }} زیرمجموعه در این نما</span>@endif</small>
                @if($row['issue'])<small class="pw-warning">رابطه مدیریتی نیازمند بررسی است</small>@endif
                @if($row['context'])<small>مدیر بالادست نتیجه جستجو</small>@endif
            </div>
        </div>
        <div class="pw-node-meta"><span class="pw-state {{ $person->is_active && $person->can_access_erp ? 'pw-active':'' }}">{{ $person->is_active && $person->can_access_erp ? 'فعال':'غیرفعال' }}</span>
        @if($canManage)<button type="button" class="pw-button pw-edit" data-edit-staff="{{ $person->id }}" data-editor-url="{{ route('admin.permissions.index',['panel'=>1,'user_id'=>$person->id]) }}" data-person-name="{{ $person->name }}">ویرایش ↗</button>@endif</div>
    </div>
    @if($row['has_children'])<ul class="pw-tree-branch" id="staff-children-{{ $person->id }}">
        @foreach($children[$person->id] ?? [] as $child)
            @include('users.partials.branch',['row'=>$child])
        @endforeach
    </ul>@endif
</li>
