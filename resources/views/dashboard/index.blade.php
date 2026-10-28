@extends('layouts.app')
@section('title', 'میز کار من')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard-workspace.css') }}?v=20261011">
@endpush
@section('content')
<div class="workspace">
    @if(auth()->user()?->isAdministrator())
    <section class="workspace-attention" style="border-right:4px solid #c98b37;padding:18px;border-radius:12px;margin-bottom:18px"><strong>کنترل انبارگردانی</strong><a href="{{ route('inventory-count-freeze.index') }}">{{app(\App\Services\InventoryCountFreezeService::class)->state()?->enabled?'🟠 فعال — مدیریت و پایان شمارش':'انتخاب گروه کالا و توقف فروش برای شمارش'}} <span>←</span></a></section>
    @endif
    <header class="workspace-heading"><div><span>سلام، {{ $userName }}</span><h1>میز کار من</h1></div><time>{{ $todayDateLabel }}</time></header>
    @if(count($workspaceAttention))
    <section class="workspace-attention" aria-label="کارهای نیازمند اقدام"><strong>نیازمند اقدام</strong>@foreach($workspaceAttention as $item)<a href="{{ $item['url'] }}">{{ $item['title'] }} <b>{{ number_format($item['count']) }}</b><span aria-hidden="true">←</span></a>@endforeach</section>
    @endif
    @php $primaryCards = collect($workspaceCards)->where('primary', true); $otherCards = collect($workspaceCards)->where('primary', false); @endphp
    @if($primaryCards->isNotEmpty())
    <nav class="workspace-grid" aria-label="دسترسی‌های من">
        @foreach($primaryCards as $card)@include('dashboard.partials.workspace-card', ['card' => $card])@endforeach
    </nav>
    @elseif($otherCards->isEmpty())
    <div class="workspace-empty">هنوز بخشی برای حساب شما فعال نشده است. برای تعیین دسترسی با مدیر نرم‌افزار هماهنگ کنید.</div>
    @endif
    @if($otherCards->isNotEmpty())
    <details class="workspace-other"><summary>سایر دسترسی‌های من <span>{{ $otherCards->count() }}</span></summary><nav class="workspace-grid" aria-label="سایر دسترسی‌های من">@foreach($otherCards as $card)@include('dashboard.partials.workspace-card', ['card' => $card])@endforeach</nav></details>
    @endif
    @include('stock-thresholds.widget')
</div>
@endsection
