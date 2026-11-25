@extends('layouts.app')
@section('title', 'اعلان‌ها')
@section('content')
<div class="container py-3 notif-center" dir="rtl" data-notification-surface data-filter="{{ $filter }}"
     data-per-page="20" data-page="{{ $notifications->currentPage() }}">
    <header class="notif-center__header">
        <div class="notif-panel__heading">
            <span class="notif-panel__symbol" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 4h16a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Zm-1 9h5l2 3h4l2-3h5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <div>
                <h2>صندوق اعلان‌ها</h2>
                <p data-notification-count aria-live="polite">{{ $unreadCount }} خوانده‌نشده</p>
            </div>
        </div>
        <form action="{{ route('notifications.read-all', [], false) }}" method="POST" data-notification-read-all>
            @csrf
            <button type="submit" class="notif-tool-btn notif-tool-btn--primary" @disabled($unreadCount === 0)><span aria-hidden="true">✓</span> خواندن همه</button>
        </form>
    </header>
    <nav class="notif-center__tabs" aria-label="فیلتر اعلان‌ها">
        @foreach(['unread' => 'خوانده‌نشده', 'read' => 'تاریخچه', 'all' => 'همه'] as $key => $label)
            <a href="{{ route('notifications.index', ['filter' => $key]) }}" @class(['notif-tool-btn', 'is-active' => $filter === $key])
                @if($filter === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <div class="notif-feedback" data-notification-error hidden><span data-notification-error-text role="alert"></span> <button type="button" class="notif-tool-btn" data-notification-retry>تلاش مجدد</button></div>
    <div class="notif-list notif-center__list" data-notification-list>
        @forelse($notifications as $notification)
            @php $readAt = $notification->readTimestamp(); @endphp
            <form method="POST" action="{{ route('notifications.open', $notification, false) }}" data-notification-open data-read-url="{{ route('notifications.read', $notification, false) }}" data-notification-id="{{ $notification->id }}">
                @csrf
                <button type="submit" @class(['notif-card', 'notif-card--unread' => !$readAt])>
                    <span class="notif-card__body">
                        <span class="notif-card__top">
                            <span class="notif-card__badge" data-category="{{ $notification->categoryLabel() }}">{{ $notification->categoryLabel() }}</span>
                            <time datetime="{{ $notification->created_at?->toIso8601String() }}">{{ $notification->created_at?->locale('fa')->diffForHumans() }}</time>
                        </span>
                        <span class="notif-card__title-line">
                            <span class="notif-card__dot" aria-hidden="true"></span>
                            <strong class="notif-card__title">{{ $notification->title }}</strong>
                        </span>
                        @if($notification->message)
                            <span class="notif-card__message">{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($notification->message), 180) }}</span>
                        @endif
                        <span class="notif-card__meta">
                            <span class="notif-card__link">مشاهده جزئیات <span aria-hidden="true">←</span></span>
                        </span>
                        <span class="visually-hidden">{{ $readAt ? 'خوانده‌شده' : 'خوانده‌نشده' }}</span>
                    </span>
                </button>
            </form>
        @empty
            <div class="notif-empty"><span class="notif-empty__icon" aria-hidden="true">✓</span><span>{{ $filter === 'unread' ? 'همه اعلان‌ها را خوانده‌اید.' : 'اعلانی برای نمایش وجود ندارد.' }}</span></div>
        @endforelse
    </div>
    <nav class="notif-pagination" aria-label="صفحه‌بندی اعلان‌ها" data-notification-pagination>
        <a class="notif-tool-btn" data-notification-step="-1" href="{{ $notifications->previousPageUrl() ?: '#' }}" @if($notifications->onFirstPage()) aria-disabled="true" @endif><span class="notif-page-arrow" aria-hidden="true"></span> قبلی</a>
        <span data-notification-page-label aria-live="polite">صفحه {{ $notifications->currentPage() }} از {{ $notifications->lastPage() }}</span>
        <a class="notif-tool-btn" data-notification-step="1" href="{{ $notifications->nextPageUrl() ?: '#' }}" @if(!$notifications->hasMorePages()) aria-disabled="true" @endif>بعدی <span class="notif-page-arrow" aria-hidden="true"></span></a>
    </nav>
</div>
@endsection
