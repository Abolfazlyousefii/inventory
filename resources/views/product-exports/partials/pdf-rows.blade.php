@foreach($rows as $rowItem)
    @php
        $row = $rowItem['row'];
        $rowNumber = $rowItem['number'];
        $statusClass = match ($row['stock_status_class'] ?? '') {
            'success' => 'badge-success',
            'warning' => 'badge-warning',
            'danger' => 'badge-danger',
            default => 'badge-secondary',
        };
    @endphp

    <tr>
        <td class="number">{{ number_format($rowNumber) }}</td>
        <td>
            @if(!($skipImages ?? false) && !empty($row['has_image']) && !empty($row['pdf_image_src']))
                <img class="product-image" src="{{ $row['pdf_image_src'] }}" alt="">
            @else
                <span class="muted">بدون تصویر</span>
            @endif
        </td>
        <td>
            <div class="product-name">{{ $row['name'] ?? 'محصول بدون نام' }}</div>
            <div class="product-unit">واحد: {{ $row['unit'] ?? 'عدد' }}</div>
        </td>
        <td class="code">{{ $row['display_code'] ?? '—' }}</td>
        <td>{{ $row['category'] ?? 'بدون دسته‌بندی' }}</td>
        <td>{{ $row['model_list'] ?? '—' }}</td>
        <td>
            <span class="number">{{ number_format((int) ($row['stock'] ?? 0)) }}</span>
            {{ $row['unit'] ?? 'عدد' }}
        </td>
        <td class="price">
            <span class="price-label">{{ $row['price_label'] ?? 'قیمت فروش' }}</span>
            <span class="number">{{ number_format((int) ($row['price'] ?? 0)) }}</span>
            تومان
        </td>
        <td>
            <span class="badge {{ $statusClass }}">{{ $row['stock_status'] ?? 'نامشخص' }}</span>
        </td>
    </tr>

    @foreach(($row['variants'] ?? []) as $variant)
        <tr class="variant-row">
            <td></td>
            <td><span class="variant-label">تنوع</span></td>
            <td class="variant-name">{{ $variant['name'] ?? 'تنوع بدون نام' }}</td>
            <td class="code">{{ $variant['code'] ?? '—' }}</td>
            <td>{{ $variant['status'] ?? 'نامشخص' }}</td>
            <td>{{ $variant['model_list'] ?? 'بدون مدل' }}</td>
            <td>
                <span class="number">{{ number_format((int) ($variant['stock'] ?? 0)) }}</span>
                {{ $variant['unit'] ?? 'عدد' }}
            </td>
            <td class="price">
                <span class="number">{{ number_format((int) ($variant['price'] ?? 0)) }}</span>
                تومان
            </td>
            <td>{{ $variant['stock_status'] ?? 'نامشخص' }}</td>
        </tr>
    @endforeach
@endforeach
