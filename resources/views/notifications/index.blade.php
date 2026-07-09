@extends('layouts.app')
@section('content')
@php
  $typeLabel = fn($type) => str_starts_with((string) $type, 'preinvoice') ? 'پیش‌فاکتور' : (str_contains((string) $type, 'ship') ? 'ارسال' : (str_contains((string) $type, 'collection') || str_contains((string) $type, 'warehouse') ? 'انبار' : (str_contains((string) $type, 'finance') ? 'مالی' : (str_starts_with((string) $type, 'invoice') ? 'فاکتور' : 'اعلان'))));
@endphp
<div class="container py-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h5 class="mb-1">آلارم‌ها</h5>
      <div class="text-muted small">وضعیت‌های مهم پیش‌فاکتور، فاکتور، مالی، انبار و ارسال بار</div>
    </div>
    <div class="btn-group btn-group-sm">
      <a href="{{ route('notifications.index', ['filter'=>'all']) }}" class="btn btn-outline-primary @if($filter==='all') active @endif">همه</a>
      <a href="{{ route('notifications.index', ['filter'=>'unread']) }}" class="btn btn-outline-primary @if($filter==='unread') active @endif">خوانده‌نشده</a>
      <a href="{{ route('notifications.index', ['filter'=>'read']) }}" class="btn btn-outline-primary @if($filter==='read') active @endif">خوانده‌شده</a>
    </div>
  </div>
  <div class="card border-0 shadow-sm">
    <div class="list-group list-group-flush">
      @forelse($notifications as $n)
        @php $priority = $n->priority ?: 'normal'; $tone = $priority === 'urgent' ? 'danger' : ($priority === 'important' ? 'primary' : 'secondary'); @endphp
        <a href="{{ route('notifications.open', $n->id) }}" class="list-group-item list-group-item-action border-0 border-bottom py-3 @if(is_null($n->read_at)) bg-info bg-opacity-10 @else bg-light @endif">
          <div class="d-flex justify-content-between gap-3">
            <div class="fw-bold text-dark">{{ $n->title }}</div>
            <small class="text-muted text-nowrap">{{ $n->created_at?->diffForHumans() }}</small>
          </div>
          <div class="small text-muted my-1">{{ $n->message }}</div>
          <span class="badge bg-{{ $tone }}-subtle text-{{ $tone }}-emphasis border border-{{ $tone }}-subtle">{{ $typeLabel($n->type) }}</span>
          <span class="badge bg-light text-dark border">{{ $priority }}</span>
        </a>
      @empty
        <div class="p-4 text-muted text-center">آلارمی وجود ندارد.</div>
      @endforelse
    </div>
  </div>
  <div class="mt-3">{{ $notifications->links() }}</div>
</div>
@endsection
