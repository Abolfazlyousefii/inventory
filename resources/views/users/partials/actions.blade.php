@if($canManage)
<div class="pw-staff-actions">
    <button type="button" class="pw-button pw-edit" data-edit-staff="{{ $person->id }}" data-editor-tab="profile" data-editor-url="{{ route('admin.permissions.index',['panel'=>1,'user_id'=>$person->id]) }}" data-person-name="{{ $person->name }}">مشخصات</button>
    <button type="button" class="pw-button pw-access" data-edit-staff="{{ $person->id }}" data-editor-tab="permissions" data-editor-url="{{ route('admin.permissions.index',['panel'=>1,'user_id'=>$person->id]) }}" data-person-name="{{ $person->name }}" aria-label="نقش و دسترسی {{ $person->name }}">نقش و دسترسی</button>
</div>
@endif
