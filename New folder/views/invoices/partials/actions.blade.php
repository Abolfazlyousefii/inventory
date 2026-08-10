<div class="invoice-actions">
    @if($meta['actions']['show'])<a class="btn btn-sm btn-primary" href="{{ $meta['actions']['show'] }}">مشاهده</a>@endif
    @if($meta['actions']['print'] || $meta['actions']['edit'] || $meta['actions']['cancel'])
    <div class="dropdown"><button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-label="عملیات بیشتر">•••</button><ul class="dropdown-menu">
        @if($meta['actions']['print'])<li><a class="dropdown-item" href="{{ $meta['actions']['print'] }}" target="_blank">چاپ فاکتور</a></li>@endif
        @if($meta['actions']['edit'])<li><a class="dropdown-item" href="{{ $meta['actions']['edit'] }}">ویرایش فاکتور</a></li>@endif
        @if($meta['actions']['cancel'])<li><button type="button" class="dropdown-item text-danger js-invoice-cancel" data-url="{{ $meta['actions']['cancel'] }}" data-number="{{ $meta['number'] }}" data-shipped="{{ $meta['is_shipped'] ? 1 : 0 }}">حذف / لغو فاکتور</button></li>@endif
    </ul></div>
    @endif
</div>
