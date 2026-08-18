<div class="commission-node" data-type="{{ $node['type'] }}" data-id="{{ $node['id'] }}"
     data-label="{{ $node['label'] }}" data-own="{{ $node['own_rate'] }}"
     data-inherited="{{ $node['inherited_rate'] }}" data-effective="{{ $node['percentage'] }}"
     data-source="{{ $node['source_label'] }}">
    <div class="commission-node__head">
        <button type="button" class="commission-expand" @disabled(! $node['has_children']) aria-expanded="false">
            <span class="commission-node__toggle">{{ $node['has_children'] ? '›' : '•' }}</span>
            <span class="commission-node__kind">{{ ['category'=>'دسته','product'=>'کالا','variant'=>'تنوع'][$node['type']] }}</span>
            <strong>{{ $node['label'] }}</strong>
        </button>
        <div class="commission-node__meta">
            @if($node['is_missing'])
                <span class="commission-badge commission-badge--missing">فاقد نرخ</span>
            @elseif($node['is_explicit_zero'])
                <span class="commission-badge commission-badge--zero">بدون پورسانت</span>
            @elseif($node['own_rate'] !== null)
                <span class="commission-badge commission-badge--own">{{ rtrim(rtrim($node['own_rate'], '0'), '.') }}٪ اختصاصی</span>
            @else
                <span class="commission-badge commission-badge--inherited">{{ rtrim(rtrim($node['percentage'], '0'), '.') }}٪ ارث‌بری</span>
            @endif
            <span class="commission-effective-rate">نرخ مؤثر: {{ $node['is_missing'] ? '—' : rtrim(rtrim($node['percentage'], '0'), '.').'٪' }}</span>
        </div>
        <div class="commission-node__actions">
            <button type="button" class="btn btn-sm btn-outline-primary commission-select">{{ $permissions['rates'] ? 'تعیین/ویرایش نرخ' : 'مشاهده جزئیات نرخ' }}</button>
            @if($permissions['campaigns'])
                <button type="button" class="btn btn-sm btn-outline-success commission-campaign-target">افزودن به اقلام کمپین</button>
            @endif
        </div>
    </div>
    <div class="commission-children d-none" aria-live="polite"></div>
</div>
