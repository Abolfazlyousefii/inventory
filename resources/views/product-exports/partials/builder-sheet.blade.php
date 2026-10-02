<div class="sheet-head">
    <h1>محصولات پیشنهادی آریا گستر</h1>
    <span>{{ now()->format('Y/m/d') }}</span>
</div>
<p class="sheet-intro">لیست کالاها و مدل‌های انتخاب‌شده</p>
<div class="sheet-grid">
    @foreach($products as $product)
        <article class="sheet-item {{ count($product['selected_models']) > 40 ? 'sheet-item--many-models' : '' }}">
            <div class="sheet-item-main">
                @if($product['image_path'])
                    <img class="sheet-photo" src="{{ $product['image_path'] }}" alt="{{ $product['name'] }}">
                @else
                    <span class="sheet-photo photo-placeholder">بدون تصویر</span>
                @endif
                <div class="sheet-item-details">
                    <span class="sheet-category">{{ $product['category_name'] }}</span>
                    <h2>{{ $product['name'] }}</h2>
                    <div class="sheet-item-meta">
                        @if($options['stock'])
                            <span class="sheet-stock {{ $product['total_stock'] > 0 ? '' : 'sheet-stock--empty' }}">{{ $product['total_stock'] > 0 ? 'موجود' : 'ناموجود' }}</span>
                        @endif
                        @if($options['code'] && $product['code'])
                            <span class="sheet-code">کد: {{ $product['code'] }}</span>
                        @endif
                    </div>
                </div>
                @if($options['price'])
                    <div class="sheet-price"><small>قیمت حدودی</small><strong>{{ $product['approximate_price_label'] }}</strong></div>
                @endif
            </div>
            <div class="sheet-list">
                <span class="sheet-list-label">مدل‌های انتخاب‌شده</span>
                <div class="sheet-chips sheet-model-chips">
                    @foreach($product['selected_models'] as $model)
                        <span class="sheet-chip sheet-model-chip">{{ $model }}</span>
                    @endforeach
                </div>
            </div>
            @if(count($product['selected_colors']))
                <div class="sheet-list sheet-list--colors">
                    <span class="sheet-list-label">رنگ‌های موجود در مدل‌های انتخاب‌شده</span>
                    <div class="sheet-chips">
                        @foreach($product['selected_colors'] as $color)
                            <span class="sheet-chip sheet-color-chip"><i style="--swatch: {{ $color['hex'] ?: '#dbe7ed' }}"></i>{{ $color['name'] }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
        </article>
    @endforeach
</div>
<footer>قیمت و موجودی براساس آخرین اطلاعات ثبت‌شده در سامانه است.</footer>
