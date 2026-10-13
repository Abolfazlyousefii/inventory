<a class="workspace-card tone-{{ $card['tone'] }}" href="{{ $card['url'] }}" data-workspace-route="{{ $card['route'] }}">
    <span class="workspace-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
    @switch($card['icon'])
        @case('sales')<path d="M3 4h2l2 11h11l2-8H6M9 20h.01M17 20h.01"/>@break
        @case('products')<path d="m12 3 9 5-9 5-9-5 9-5Zm-9 5v10l9 5 9-5V8M12 13v10"/>@break
        @case('warehouse')<path d="m3 9 9-6 9 6v12H3V9Zm4 12v-8h10v8M7 17h10"/>@break
        @case('shipping')<path d="M3 5h11v12H3V5Zm11 5h4l3 4v3h-7M7 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm11 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/>@break
        @case('chart')<path d="M4 3v18h17M8 16v-5M13 16V7M18 16V4"/>@break
        @case('people')<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm8-7a4 4 0 0 1 0 8M22 21v-2a4 4 0 0 0-3-4"/>@break
        @default<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>
    @endswitch
    </svg></span>
    <strong>{{ $card['title'] }}</strong><span class="workspace-card-group">{{ $card['group'] }}</span><span class="workspace-card-arrow" aria-hidden="true">←</span>
</a>
