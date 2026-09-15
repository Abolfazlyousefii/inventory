@extends('layouts.app')
@section('title', 'تنظیمات نرخ پورسانت')
@section('page-title', 'مالی / تنظیمات نرخ پورسانت')

@push('styles')
<style>
    .commission-tree { border: 1px solid #e3e6ef; border-radius: .75rem; background: #fff; padding: .5rem; max-height: 70vh; overflow-y: auto; }
    .commission-node { border-bottom: 1px dashed #eef0f6; }
    .commission-node:last-child { border-bottom: 0; }
    .commission-node__head { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; padding: .5rem .25rem; }
    .commission-expand { flex: 1 1 240px; display: flex; align-items: center; gap: .5rem; background: transparent; border: 0; text-align: right; padding: .25rem; border-radius: .5rem; }
    .commission-expand:not(:disabled):hover { background: #f6f8fc; }
    .commission-expand:disabled { opacity: .85; cursor: default; }
    .commission-node__toggle { display: inline-flex; width: 1.25rem; justify-content: center; color: #6c757d; transition: transform .15s ease; }
    .commission-expand[aria-expanded="true"] .commission-node__toggle { transform: rotate(90deg); }
    .commission-node__kind { font-size: .72rem; color: #6c757d; background: #f1f3f9; border-radius: .35rem; padding: .1rem .4rem; }
    .commission-node__meta { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
    .commission-node__actions { display: flex; gap: .35rem; }
    .commission-effective-rate { font-size: .78rem; color: #6c757d; }
    .commission-badge { font-size: .74rem; border-radius: .35rem; padding: .15rem .5rem; border: 1px solid transparent; }
    .commission-badge--own { background: #e6f7ee; color: #12734a; border-color: #b7e4cb; }
    .commission-badge--inherited { background: #e8f1fe; color: #14539a; border-color: #bcd7fb; }
    .commission-badge--zero { background: #f1f3f9; color: #5a6372; border-color: #dfe3ec; }
    .commission-badge--missing { background: #fdecec; color: #a12222; border-color: #f7c5c5; }
    .commission-children { margin-right: 1.5rem; border-right: 2px solid #eef0f6; padding-right: .5rem; }
    .commission-rate-summary dt { font-size: .78rem; color: #6c757d; font-weight: 400; }
    .commission-rate-summary dd { font-weight: 600; margin-bottom: .6rem; }
</style>
@endpush

@section('content')
<div class="container-fluid py-4"
     id="commissionRateApp"
     data-tree-url="{{ route('finance.commission-rates.tree') }}"
     data-history-url="{{ route('finance.commission-rates.rates.history') }}">

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">تنظیمات نرخ پورسانت</h1>
            <p class="text-muted mb-0">نرخ اختصاصی، ارث‌بری و نرخ مؤثر هر سطح از درخت کالا</p>
        </div>
        <a href="{{ route('finance.seller-sales.index') }}" class="btn btn-outline-secondary">بازگشت به اسناد پورسانت</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
        @php
            $draftCount = \App\Models\SellerSalesDocument::where('status', \App\Models\SellerSalesDocument::STATUS_DRAFT)->count();
        @endphp
        @if($draftCount > 0)
            <div class="alert alert-info">
                <strong>توجه:</strong> {{ $draftCount }} سند پیش‌نویس فروشنده وجود دارد.
                برای اعمال نرخ‌های جدید در این اسناد، از دستور
                <code>php artisan commissions:recalculate-drafts</code> استفاده کنید.
            </div>
        @endif
    @endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0 ps-3">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-center">
                <div class="col-md-6">
                    <label class="form-label" for="commissionTreeSearch">جستجوی دسته، کالا یا تنوع</label>
                    <input type="search" class="form-control" id="commissionTreeSearch" placeholder="حداقل ۲ کاراکتر وارد کنید…" autocomplete="off">
                </div>
                <div class="col-md-6">
                    <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                        <span class="commission-badge commission-badge--own">اختصاصی</span>
                        <span class="commission-badge commission-badge--inherited">ارث‌بری</span>
                        <span class="commission-badge commission-badge--zero">بدون پورسانت</span>
                        <span class="commission-badge commission-badge--missing">فاقد نرخ</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="commission-tree" id="commissionTree">
        @forelse($rootNodes as $node)
            @include('finance.commission-rates.partials.node', ['node' => $node])
        @empty
            <p class="text-muted mb-0 p-3">هیچ دسته‌بندی‌ای برای نمایش وجود ندارد.</p>
        @endforelse
    </div>
</div>

<div class="modal fade" id="rateEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تعیین/ویرایش نرخ پورسانت</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
            </div>
            <div class="modal-body">
                <dl class="row commission-rate-summary mb-3">
                    <div class="col-6"><dt>عنوان</dt><dd id="rateModalLabel">—</dd></div>
                    <div class="col-6"><dt>نوع</dt><dd id="rateModalType">—</dd></div>
                    <div class="col-4"><dt>نرخ ارث‌بری</dt><dd id="rateModalInherited">—</dd></div>
                    <div class="col-4"><dt>نرخ اختصاصی</dt><dd id="rateModalOwn">—</dd></div>
                    <div class="col-4"><dt>نرخ مؤثر</dt><dd id="rateModalEffective">—</dd></div>
                    <div class="col-12"><dt>منبع نرخ</dt><dd id="rateModalSource">—</dd></div>
                </dl>

                <form method="POST" action="{{ route('finance.commission-rates.rates.store') }}" id="rateStoreForm">
                    @csrf
                    <input type="hidden" name="target_type" id="rateStoreType">
                    <input type="hidden" name="target_id" id="rateStoreId">
                    <label class="form-label" for="rateStorePercentage">درصد نرخ جدید</label>
                    <div class="input-group mb-2">
                        <input type="text" class="form-control" name="percentage" id="rateStorePercentage" inputmode="decimal" required>
                        <span class="input-group-text">٪</span>
                        <button type="button" class="btn btn-outline-secondary" id="rateZeroButton">تعیین ۰٪</button>
                    </div>
                    <label class="form-label mt-2" for="rateStoreEffectiveFrom">تاریخ شروع اعمال</label>
                    <input type="text" class="form-control mb-2" name="effective_from" id="rateStoreEffectiveFrom"
                           data-jdp data-jdp-only-date autocomplete="off"
                           placeholder="خالی = از همین لحظه">
                    <p class="text-muted small mb-3">اگر خالی بگذارید، نرخ از همین لحظه اعمال می‌شود و نرخ قبلی در تاریخچه حفظ می‌گردد. برای اسناد پیش‌نویس با فاکتورهای قدیمی‌تر، تاریخی قبل از تاریخ فاکتورها وارد کنید.</p>
                    <button type="submit" class="btn btn-primary w-100">ذخیره نرخ</button>
                </form>

                <hr>

                <div class="d-flex gap-2">
                    <form method="POST" action="{{ route('finance.commission-rates.rates.destroy') }}" id="rateDestroyForm" class="flex-fill">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="target_type" id="rateDestroyType">
                        <input type="hidden" name="target_id" id="rateDestroyId">
                        <button type="submit" class="btn btn-outline-danger w-100" data-confirm="نرخ اختصاصی این سطح بسته شود؟">حذف نرخ اختصاصی</button>
                    </form>
                    <button type="button" class="btn btn-outline-secondary flex-fill" id="rateHistoryButton">تاریخچه تغییرات</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="commissionHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تاریخچه نرخ — <span id="historyModalLabel"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>درصد</th><th>از تاریخ</th><th>تا تاریخ</th><th>ثبت‌کننده</th></tr></thead>
                        <tbody id="historyTableBody"><tr><td colspan="4" class="text-muted text-center">در حال بارگذاری…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function ($) {
    'use strict';

    var $app = $('#commissionRateApp');
    if (! $app.length) { return; }

    var treeUrl = $app.data('tree-url');
    var historyUrl = $app.data('history-url');
    var $tree = $('#commissionTree');
    var initialHtml = $tree.html();
    var kindLabels = { category: 'دسته', product: 'کالا', variant: 'تنوع' };
    var current = null;

    function escapeHtml(value) {
        return $('<div>').text(value === null || value === undefined ? '' : value).html();
    }

    function trimRate(value) {
        if (value === null || value === undefined || value === '') { return null; }
        var text = String(value);
        if (text.indexOf('.') !== -1) {
            text = text.replace(/0+$/, '').replace(/\.$/, '');
        }
        return text === '' ? '0' : text;
    }

    function badgeFor(node) {
        if (node.is_missing) { return '<span class="commission-badge commission-badge--missing">فاقد نرخ</span>'; }
        if (node.is_explicit_zero) { return '<span class="commission-badge commission-badge--zero">بدون پورسانت</span>'; }
        if (node.own_rate !== null && node.own_rate !== undefined) {
            return '<span class="commission-badge commission-badge--own">' + escapeHtml(trimRate(node.own_rate)) + '٪ اختصاصی</span>';
        }
        return '<span class="commission-badge commission-badge--inherited">' + escapeHtml(trimRate(node.percentage)) + '٪ ارث‌بری</span>';
    }

    function renderNode(node) {
        var effective = node.is_missing ? '—' : trimRate(node.percentage) + '٪';
        return '' +
            '<div class="commission-node" data-type="' + escapeHtml(node.type) + '" data-id="' + escapeHtml(node.id) + '"' +
            ' data-label="' + escapeHtml(node.label) + '" data-own="' + escapeHtml(node.own_rate) + '"' +
            ' data-inherited="' + escapeHtml(node.inherited_rate) + '" data-effective="' + escapeHtml(node.percentage) + '"' +
            ' data-source="' + escapeHtml(node.source_label) + '">' +
                '<div class="commission-node__head">' +
                    '<button type="button" class="commission-expand"' + (node.has_children ? '' : ' disabled') + ' aria-expanded="false">' +
                        '<span class="commission-node__toggle">' + (node.has_children ? '›' : '•') + '</span>' +
                        '<span class="commission-node__kind">' + escapeHtml(kindLabels[node.type] || node.type) + '</span>' +
                        '<strong>' + escapeHtml(node.label) + '</strong>' +
                    '</button>' +
                    '<div class="commission-node__meta">' + badgeFor(node) +
                        '<span class="commission-effective-rate">نرخ مؤثر: ' + escapeHtml(effective) + '</span>' +
                    '</div>' +
                    '<div class="commission-node__actions">' +
                        '<button type="button" class="btn btn-sm btn-outline-primary commission-select">تعیین/ویرایش نرخ</button>' +
                    '</div>' +
                '</div>' +
                '<div class="commission-children d-none" aria-live="polite"></div>' +
            '</div>';
    }

    function renderNodes(items) {
        return $.map(items || [], renderNode).join('');
    }

    // Children are paginated (30 per page); without this button the rest of a
    // large category would never be reachable.
    function loadMoreButton(type, id, response, $container) {
        if (! response.has_more || ! response.next_page) { return ''; }

        // Only products/variants are paginated; sub-categories come with page 1
        // and must not be counted against the paginator total.
        var loaded = $container.children('.commission-node').not('[data-type="category"]').length;
        var remaining = Math.max((parseInt(response.total, 10) || 0) - loaded, 0);

        return '<button type="button" class="btn btn-sm btn-outline-secondary commission-load-more w-100 mt-2"' +
            ' data-type="' + escapeHtml(type) + '" data-id="' + escapeHtml(id) + '" data-page="' + escapeHtml(response.next_page) + '">' +
            'بارگذاری بیشتر… (' + escapeHtml(remaining) + ' مورد باقی‌مانده)</button>';
    }

    $tree.on('click', '.commission-expand', function () {
        var $button = $(this);
        if ($button.is(':disabled')) { return; }

        var $node = $button.closest('.commission-node');
        var $children = $node.children('.commission-children');

        if ($node.data('loaded')) {
            var willOpen = $children.hasClass('d-none');
            $children.toggleClass('d-none', ! willOpen);
            $button.attr('aria-expanded', willOpen ? 'true' : 'false');
            return;
        }

        var type = $node.data('type');
        var id = $node.data('id');

        $children.removeClass('d-none').html('<p class="text-muted small mb-1 p-2">در حال بارگذاری…</p>');
        $button.attr('aria-expanded', 'true');

        $.getJSON(treeUrl, { type: type, id: id })
            .done(function (response) {
                var html = renderNodes(response.items);
                $children.html(html || '<p class="text-muted small mb-1 p-2">زیرمجموعه‌ای ثبت نشده است.</p>');
                $children.append(loadMoreButton(type, id, response, $children));
                $node.data('loaded', true);
            })
            .fail(function () {
                $children.html('<p class="text-danger small mb-1 p-2">بارگذاری زیرمجموعه‌ها ناموفق بود.</p>');
            });
    });

    $tree.on('click', '.commission-load-more', function () {
        var $button = $(this);
        var type = $button.data('type');
        var id = $button.data('id');
        var page = $button.data('page');
        var $container = $button.closest('.commission-children');

        $button.prop('disabled', true).text('در حال بارگذاری…');

        $.getJSON(treeUrl, { type: type, id: id, page: page })
            .done(function (response) {
                $button.remove();
                $container.append(renderNodes(response.items));
                $container.append(loadMoreButton(type, id, response, $container));
            })
            .fail(function () {
                $button.prop('disabled', false).text('بارگذاری ناموفق بود — تلاش دوباره');
            });
    });

    var searchTimer = null;
    $('#commissionTreeSearch').on('input', function () {
        var term = $.trim($(this).val());
        window.clearTimeout(searchTimer);

        if (term.length === 0) {
            $tree.html(initialHtml);
            return;
        }
        if (term.length < 2) { return; }

        searchTimer = window.setTimeout(function () {
            $tree.html('<p class="text-muted mb-0 p-3">در حال جستجو…</p>');
            $.getJSON(treeUrl, { scope: 'all', q: term })
                .done(function (response) {
                    var html = renderNodes(response.items);
                    $tree.html(html || '<p class="text-muted mb-0 p-3">نتیجه‌ای یافت نشد.</p>');
                    if (response.has_more) {
                        $tree.append('<p class="alert alert-warning small mb-0 mt-2">نتایج بیشتری وجود دارد. عبارت دقیق‌تری وارد کنید.</p>');
                    }
                })
                .fail(function () {
                    $tree.html('<p class="text-danger mb-0 p-3">جستجو ناموفق بود.</p>');
                });
        }, 400);
    });

    $tree.on('click', '.commission-select', function () {
        var $node = $(this).closest('.commission-node');
        current = {
            type: String($node.data('type')),
            id: String($node.data('id')),
            label: String($node.data('label') || '')
        };

        var own = trimRate($node.attr('data-own'));
        var inherited = trimRate($node.attr('data-inherited'));
        var effective = trimRate($node.attr('data-effective'));

        $('#rateModalLabel').text(current.label);
        $('#rateModalType').text(kindLabels[current.type] || current.type);
        $('#rateModalInherited').text(inherited === null ? '—' : inherited + '٪');
        $('#rateModalOwn').text(own === null ? 'ندارد' : own + '٪');
        $('#rateModalEffective').text(effective === null ? '—' : effective + '٪');
        $('#rateModalSource').text($node.attr('data-source') || '—');

        $('#rateStoreType, #rateDestroyType').val(current.type);
        $('#rateStoreId, #rateDestroyId').val(current.id);
        $('#rateStorePercentage').val(own === null ? '' : own);
        $('#rateStoreEffectiveFrom').val('');

        bootstrap.Modal.getOrCreateInstance(document.getElementById('rateEditModal')).show();
    });

    $('#rateZeroButton').on('click', function () {
        $('#rateStorePercentage').val('0');
    });

    $('#rateDestroyForm').on('submit', function (event) {
        if (! window.confirm($(this).find('[data-confirm]').data('confirm'))) {
            event.preventDefault();
        }
    });

    $('#rateHistoryButton').on('click', function () {
        if (! current) { return; }

        $('#historyModalLabel').text(current.label);
        $('#historyTableBody').html('<tr><td colspan="4" class="text-muted text-center">در حال بارگذاری…</td></tr>');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('commissionHistoryModal')).show();

        $.getJSON(historyUrl, { target_type: current.type, target_id: current.id })
            .done(function (response) {
                var rows = $.map(response.items || [], function (item) {
                    return '<tr>' +
                        '<td>' + escapeHtml(trimRate(item.percentage)) + '٪</td>' +
                        '<td>' + escapeHtml(item.effective_from) + '</td>' +
                        '<td>' + escapeHtml(item.effective_to) + '</td>' +
                        '<td>' + escapeHtml(item.created_by || '—') + '</td>' +
                    '</tr>';
                }).join('');
                $('#historyTableBody').html(rows || '<tr><td colspan="4" class="text-muted text-center">تاریخچه‌ای ثبت نشده است.</td></tr>');
            })
            .fail(function () {
                $('#historyTableBody').html('<tr><td colspan="4" class="text-danger text-center">دریافت تاریخچه ناموفق بود.</td></tr>');
            });
    });
})(jQuery);
</script>
@endpush
