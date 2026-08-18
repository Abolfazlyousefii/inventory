@extends('layouts.app')

@section('content')
@php $actions=\App\Models\ProductDeactivationDocument::actionLabels(); $reasons=array_merge(\App\Models\ProductDeactivationDocument::reasonLabels(), \App\Models\ProductDeactivationDocument::activationReasonLabels()); @endphp
<div class="d-flex justify-content-between align-items-center mb-3"><h4 class="mb-0">تاریخچه وضعیت فروش</h4><a href="{{ route('product-deactivation-documents.create') }}" class="btn btn-primary">مدیریت وضعیت فروش</a></div>
<div class="card mb-3"><div class="card-body"><form class="row g-2">
    <div class="col-md-3"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="جستجوی کالا یا کد"></div>
    <div class="col-md-2"><select name="action_type" class="form-select"><option value="">همه عملیات</option>@foreach($actions as $key=>$label)<option value="{{ $key }}" @selected(request('action_type')===$key)>{{ $label }}</option>@endforeach</select></div>
    <div class="col-md-2"><input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control"></div><div class="col-md-2"><input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control"></div>
    <div class="col-md-3"><button class="btn btn-outline-primary">اعمال فیلتر</button> <a href="{{ route('product-deactivation-documents.index') }}" class="btn btn-outline-secondary">پاک کردن</a></div>
</form></div></div>
<div class="card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>شماره سند</th><th>عملیات</th><th>کالا</th><th>محدوده</th><th>تعداد تنوع</th><th>دلیل</th><th>ثبت‌کننده</th><th>تاریخ</th><th></th></tr></thead><tbody>
@forelse($documents as $doc)<tr><td class="fw-bold">{{ $doc->document_number }}</td><td><span class="badge {{ ($doc->action_type ?? 'deactivate')==='activate'?'text-bg-success':'text-bg-secondary' }}">{{ $actions[$doc->action_type ?? 'deactivate'] }}</span></td><td>{{ $doc->product_name_snapshot ?: $doc->product?->name }}</td><td>{{ ($doc->scope_type ?? 'product')==='variants'?'تنوع‌های مشخص':'کل کالا' }}</td><td>{{ $doc->items_count }}</td><td>{{ $reasons[$doc->reason_type] ?? $doc->reason_text ?? '—' }}</td><td>{{ $doc->creator?->name ?? '—' }}</td><td>{{ optional($doc->created_at)->format('Y/m/d H:i') }}</td><td><a class="btn btn-sm btn-outline-dark" href="{{ route('product-deactivation-documents.show',$doc) }}">مشاهده</a></td></tr>
@empty<tr><td colspan="9" class="text-center text-muted py-4">رویدادی ثبت نشده است.</td></tr>@endforelse
</tbody></table></div><div class="card-body">{{ $documents->links() }}</div></div>
@endsection
