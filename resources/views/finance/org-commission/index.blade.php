@extends('layouts.app')
@section('title', 'پورسانت اداری')
@section('page-title', 'مالی / پورسانت اداری')

@section('content')
@php
    use App\Support\Currency;
    use App\Support\JalaliDate;

    $statusLabels = ['draft' => 'پیش‌نویس', 'confirmed' => 'تأیید‌شده', 'finalized' => 'نهایی‌شده'];
    $statusClasses = ['draft' => 'bg-warning text-dark', 'confirmed' => 'bg-info text-dark', 'finalized' => 'bg-success'];
@endphp

<div class="container-fluid py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">پورسانت اداری</h1>
            <p class="text-muted mb-0">تعریف واحدهای سازمانی، اعضا و تخصیص سهمی از کل پورسانت فروشندگان</p>
        </div>
        <a href="{{ route('finance.seller-sales.index') }}" class="btn btn-outline-secondary">بازگشت به اسناد پورسانت</a>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0 ps-3">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link @if($activeTab === 'departments') active @endif" data-bs-toggle="tab" data-bs-target="#departmentsTab" type="button" role="tab">واحدهای سازمانی</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link @if($activeTab === 'documents') active @endif" data-bs-toggle="tab" data-bs-target="#documentsTab" type="button" role="tab">اسناد پورسانت اداری</button>
        </li>
    </ul>

    <div class="tab-content">

        {{-- ── تب واحدها ───────────────────────────── --}}
        <div class="tab-pane fade @if($activeTab === 'departments') show active @endif" id="departmentsTab" role="tabpanel">

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-bold">افزودن واحد جدید</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('finance.org-commission.departments.store') }}" class="row g-3 align-items-end">
                        @csrf
                        <div class="col-md-4">
                            <label class="form-label" for="newDepartmentName">نام واحد</label>
                            <input type="text" class="form-control" id="newDepartmentName" name="name" maxlength="255" placeholder="مثلاً انبار" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="newDepartmentDescription">توضیحات (اختیاری)</label>
                            <input type="text" class="form-control" id="newDepartmentDescription" name="description" maxlength="500">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">ثبت واحد</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="row g-3">
                @forelse($departments as $department)
                    @php $members = $department->activeMembers; @endphp
                    <div class="col-md-6 col-xl-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <h2 class="h6 mb-0">{{ $department->name }}</h2>
                                    <span class="badge {{ $department->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $department->is_active ? 'فعال' : 'غیرفعال' }}</span>
                                </div>
                                <p class="text-muted small mb-2">{{ $department->description ?: 'بدون توضیحات' }}</p>
                                <p class="small mb-3">تعداد اعضای فعال: <strong>{{ $members->count() }}</strong></p>

                                @if($members->isNotEmpty())
                                    <ul class="list-unstyled small text-muted mb-3">
                                        @foreach($members as $member)
                                            <li>• {{ $member->name }}@if($member->role) <span class="text-black-50">({{ $member->role }})</span>@endif</li>
                                        @endforeach
                                    </ul>
                                @endif

                                <div class="mt-auto d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary department-edit"
                                            data-id="{{ $department->id }}"
                                            data-name="{{ $department->name }}"
                                            data-description="{{ $department->description }}"
                                            data-action="{{ route('finance.org-commission.departments.update', $department) }}">ویرایش</button>

                                    <button type="button" class="btn btn-sm btn-outline-secondary department-members"
                                            data-name="{{ $department->name }}"
                                            data-members="{{ json_encode($members->map(fn ($m) => ['name' => $m->name, 'role' => $m->role]), JSON_UNESCAPED_UNICODE) }}"
                                            data-action="{{ route('finance.org-commission.departments.members.sync', $department) }}">مدیریت اعضا</button>

                                    <form method="POST" action="{{ route('finance.org-commission.departments.toggle', $department) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm {{ $department->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                            {{ $department->is_active ? 'غیرفعال کردن' : 'فعال کردن' }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-12"><div class="alert alert-info mb-0">هنوز واحدی تعریف نشده است.</div></div>
                @endforelse
            </div>
        </div>

        {{-- ── تب اسناد ────────────────────────────── --}}
        <div class="tab-pane fade @if($activeTab === 'documents') show active @endif" id="documentsTab" role="tabpanel">

            <div class="d-flex justify-content-end mb-3">
                <a href="{{ route('finance.org-commission.create') }}" class="btn btn-primary">صدور سند پورسانت اداری</a>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>شماره سند</th>
                                <th>بازه تاریخی</th>
                                <th>کل پورسانت فروشندگان</th>
                                <th>مبلغ تخصیص</th>
                                <th>وضعیت</th>
                                <th>ایجادکننده</th>
                                <th>تاریخ ایجاد</th>
                                <th class="text-end">عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($documents as $document)
                                <tr>
                                    <td class="fw-bold">{{ $document->document_number }}</td>
                                    <td>{{ JalaliDate::date($document->period_from) }} — {{ JalaliDate::date($document->period_to) }}</td>
                                    <td>{{ Currency::formatRial($document->total_seller_commission) }}</td>
                                    <td>{{ Currency::formatRial($document->total_allocated) }}</td>
                                    <td><span class="badge {{ $statusClasses[$document->status] ?? 'bg-secondary' }}">{{ $statusLabels[$document->status] ?? $document->status }}</span></td>
                                    <td>{{ $document->creator?->name ?? '—' }}</td>
                                    <td>{{ JalaliDate::date($document->created_at) }}</td>
                                    <td class="text-end">
                                        <div class="d-flex gap-1 justify-content-end">
                                            <a href="{{ route('finance.org-commission.show', $document) }}" class="btn btn-sm btn-outline-primary">مشاهده</a>
                                            @if($document->isDraft())
                                                <a href="{{ route('finance.org-commission.edit', $document) }}" class="btn btn-sm btn-outline-secondary">ویرایش</a>
                                                <form method="POST" action="{{ route('finance.org-commission.destroy', $document) }}" class="org-delete-form">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">حذف</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center text-muted py-4">هنوز سندی صادر نشده است.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-3">{{ $documents->appends(['tab' => 'documents'])->links() }}</div>
        </div>
    </div>
</div>

{{-- مودال ویرایش واحد --}}
<div class="modal fade" id="departmentEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="departmentEditForm" class="modal-content">
            @csrf
            @method('PUT')
            <div class="modal-header">
                <h5 class="modal-title">ویرایش واحد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="editDepartmentName">نام واحد</label>
                    <input type="text" class="form-control" id="editDepartmentName" name="name" maxlength="255" required>
                </div>
                <div>
                    <label class="form-label" for="editDepartmentDescription">توضیحات</label>
                    <input type="text" class="form-control" id="editDepartmentDescription" name="description" maxlength="500">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>
</div>

{{-- مودال مدیریت اعضا --}}
<div class="modal fade" id="departmentMembersModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" id="departmentMembersForm" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">اعضای واحد — <span id="membersModalTitle"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">اعضایی که از فهرست حذف شوند غیرفعال می‌شوند و سوابق آن‌ها در اسناد قبلی حفظ می‌ماند.</p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th style="width:50%">نام عضو</th><th>سمت (اختیاری)</th><th style="width:60px"></th></tr></thead>
                        <tbody id="membersRows"></tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="addMemberRow">افزودن عضو</button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره اعضا</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function ($) {
    'use strict';

    var memberIndex = 0;

    function memberRow(name, role) {
        var i = memberIndex++;
        return '<tr>' +
            '<td><input type="text" class="form-control form-control-sm" name="members[' + i + '][name]" maxlength="255" value="' + $('<div>').text(name || '').html() + '" required></td>' +
            '<td><input type="text" class="form-control form-control-sm" name="members[' + i + '][role]" maxlength="255" value="' + $('<div>').text(role || '').html() + '"></td>' +
            '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-member-row">×</button></td>' +
        '</tr>';
    }

    $('.department-edit').on('click', function () {
        var $button = $(this);
        $('#departmentEditForm').attr('action', $button.data('action'));
        $('#editDepartmentName').val($button.data('name'));
        $('#editDepartmentDescription').val($button.data('description') || '');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('departmentEditModal')).show();
    });

    $('.department-members').on('click', function () {
        var $button = $(this);
        var members = $button.data('members') || [];

        $('#departmentMembersForm').attr('action', $button.data('action'));
        $('#membersModalTitle').text($button.data('name'));

        memberIndex = 0;
        var rows = $.map(members, function (member) {
            return memberRow(member.name, member.role);
        }).join('');
        $('#membersRows').html(rows || memberRow('', ''));

        bootstrap.Modal.getOrCreateInstance(document.getElementById('departmentMembersModal')).show();
    });

    $('#addMemberRow').on('click', function () {
        $('#membersRows').append(memberRow('', ''));
    });

    $('#membersRows').on('click', '.remove-member-row', function () {
        var $body = $('#membersRows');
        $(this).closest('tr').remove();
        if ($body.children('tr').length === 0) {
            $body.append(memberRow('', ''));
        }
    });

    $('.org-delete-form').on('submit', function (event) {
        if (! window.confirm('این سند پیش‌نویس حذف شود؟')) {
            event.preventDefault();
        }
    });
})(jQuery);
</script>
@endpush
