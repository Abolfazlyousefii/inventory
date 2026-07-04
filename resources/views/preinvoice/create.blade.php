@extends('layouts.app')

@section('content')
@php
$order = $order ?? null;
$customersPageUrl = $customersPageUrl ?? url('/customers');

$initRows = old('products');

if (!$initRows && $order) {
$initRows = $order->items->map(function ($it) {
$product = $it->product ?? null;
$variant = $it->variant ?? null;
return [
'id' => (int) $it->product_id,
'product_id' => (int) $it->product_id,
'product_name' => $product->title ?? $product->name ?? null,
'product_code' => $product->code ?? $product->sku ?? null,
'variety_id' => (int) $it->variant_id,
'variant_id' => (int) $it->variant_id,
'variant_name' => $variant->variant_name ?? null,
'quantity' => (int) $it->quantity,
'price' => (int) $it->price,
'item_id' => (int) $it->id,
];
})->values();
}

if (!$initRows) { $initRows = []; }

$isEdit = (bool) ($isEdit ?? false);
$oldCustomerTitle = trim((string) old('customer_name', $order->customer_name ?? ''));
$oldCustomerMobile = trim((string) old('customer_mobile', $order->customer_mobile ?? ''));
$oldPreinvoiceDescription = old('description', $order->description ?? '');
$initialDiscountBreakdown = old('discount_breakdown');
if (!$initialDiscountBreakdown && $order && $order->discount_breakdown) {
    $initialDiscountBreakdown = $order->discount_breakdown;
}
if (is_string($initialDiscountBreakdown)) {
    $decodedInitialDiscountBreakdown = json_decode($initialDiscountBreakdown, true);
    $initialDiscountBreakdown = is_array($decodedInitialDiscountBreakdown) ? $decodedInitialDiscountBreakdown : null;
}
$hasLegacyDiscount = $order && empty($order->discount_allocation_mode) && (int) ($order->discount_amount ?? 0) > 0;
@endphp

<link rel="stylesheet" href="{{ asset('lib/select2.min.css') }}">
<link rel="stylesheet" href="{{ asset('lib/bootstrap.rtl.min.css') }}">
<script src="{{ asset('lib/jquery.min.js') }}"></script>
<script src="{{ asset('lib/select2.min.js') }}"></script>
<script src="{{ asset('lib/bootstrap.bundle.min.js') }}"></script>

<style>
    :root {
        --brand: #33c7c0;
        --brand-dark: #0c5367;
        --brand-darker: #083d50;
        --accent: #f1ab27;
        --accent-dark: #dd991b;
        --bg: #f7f3eb;
        --card: #fffdf9;
        --border: #dde6e3;
        --text: #173543;
        --text-soft: #2e4f5d;
        --muted: #6d8087;
        --success: #178c63;
        --danger: #d14d4d;
        --danger-soft: rgba(209, 77, 77, .08);
        --shadow-sm: 0 4px 14px rgba(8, 61, 80, .05);
        --shadow-md: 0 8px 26px rgba(8, 61, 80, .08);
    }

    html,
    body {
        max-width: 100%;
        overflow-x: hidden;
    }

    body {
        background: linear-gradient(180deg, #f8f5ee 0%, #f5efe6 100%);
        font-size: 14px;
        color: var(--text);
    }

    .page-shell {
        max-width: 960px;
    }

    .soft-card,
    .soft-card-lg {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 16px;
        box-shadow: var(--shadow-sm);
        position: relative;
        overflow: hidden;
    }

    .soft-card::before,
    .soft-card-lg::before {
        content: "";
        position: absolute;
        inset: 0 auto auto 0;
        width: 100%;
        height: 3px;
        background: linear-gradient(90deg, var(--brand-dark), var(--brand), var(--accent));
    }

    .soft-card-lg {
        background: linear-gradient(180deg, rgba(255, 255, 255, .98), rgba(253, 250, 245, .98));
        border-color: rgba(51, 199, 192, .2);
        box-shadow: var(--shadow-md);
    }

    .compact-card {
        padding: 14px;
    }

    .product-focus {
        padding: 16px;
    }

    .final-card {
        padding: 15px;
        margin-bottom: 24px;
        background: linear-gradient(180deg, #fffefb, #fbf7ef);
    }

    .page-title {
        font-size: 1.15rem;
        font-weight: 900;
        margin: 0;
        color: var(--brand-darker);
    }

    .section-title {
        font-size: .95rem;
        font-weight: 900;
        margin: 0;
        color: var(--brand-darker);
    }

    .hint {
        color: var(--muted);
        font-size: .8rem;
        line-height: 1.7;
    }

    .label-sm {
        font-size: .77rem;
        font-weight: 800;
        color: var(--text-soft);
        margin-bottom: 5px;
        display: block;
    }

    .customer-box {
        background: linear-gradient(180deg, #faf7f1, #f7f1e7);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 10px 13px;
        min-height: 60px;
    }

    .customer-box.is-selected {
        background: linear-gradient(180deg, rgba(51, 199, 192, .10), rgba(51, 199, 192, .05));
        border-color: rgba(51, 199, 192, .35);
    }

    .quick-area {
        background: linear-gradient(180deg, #fffefb, #fbf6ee);
        border: 1px solid rgba(12, 83, 103, .12);
        border-radius: 14px;
        padding: 12px;
    }

    .code-input {
        height: 46px;
        font-size: 1.4rem;
        font-weight: 900;
        text-align: center;
        letter-spacing: 6px;
        direction: ltr;
        border-radius: 12px;
        border: 1px solid rgba(12, 83, 103, .15);
        background: #fff;
        color: var(--brand-darker);
        width: 100%;
    }

    .code-input:focus {
        border-color: var(--brand);
        box-shadow: 0 0 0 .18rem rgba(51, 199, 192, .14);
        outline: none;
    }

    .find-btn {
        height: 46px;
        border-radius: 12px;
        font-weight: 900;
        font-size: .88rem;
        background: linear-gradient(135deg, var(--brand), #26b8c3);
        border: none;
        color: #fff;
        padding: 0 18px;
        white-space: nowrap;
    }

    .find-btn:hover {
        background: linear-gradient(135deg, #26bac7, var(--brand-dark));
    }

    .badge-soft {
        display: inline-flex;
        align-items: center;
        background: #f7f5ef;
        color: var(--text-soft);
        border: 1px solid rgba(12, 83, 103, .10);
        border-radius: 999px;
        padding: 3px 8px;
        font-size: .72rem;
        font-weight: 800;
        line-height: 1.6;
    }

    .badge-brand {
        background: rgba(51, 199, 192, .12);
        color: var(--brand-dark);
        border-color: rgba(51, 199, 192, .25);
    }

    .badge-stock {
        background: rgba(23, 140, 99, .09);
        color: var(--success);
        border-color: rgba(23, 140, 99, .18);
    }

    .badge-no-stock {
        background: var(--danger-soft);
        color: var(--danger);
        border-color: rgba(209, 77, 77, .18);
    }

    .local-draft-banner {
        display: none;
        border: 1px solid rgba(241, 171, 39, .30);
        background: linear-gradient(180deg, rgba(241, 171, 39, .14), rgba(241, 171, 39, .06));
        border-radius: 15px;
        padding: 12px 14px;
        margin-bottom: 12px;
        box-shadow: var(--shadow-sm);
    }

    .local-draft-banner.is-visible {
        display: block;
    }

    .autosave-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        border-radius: 999px;
        padding: 4px 9px;
        font-size: .72rem;
        font-weight: 900;
        border: 1px solid rgba(12, 83, 103, .10);
        background: #fff;
        color: var(--muted);
    }

    .autosave-pill.is-saved {
        color: var(--success);
        border-color: rgba(23, 140, 99, .18);
        background: rgba(23, 140, 99, .06);
    }

    .recent-wrap {
        display: none;
        margin-top: 10px;
        gap: 6px;
        flex-wrap: wrap;
        align-items: center;
    }

    .recent-chip {
        border: 1px solid rgba(12, 83, 103, .12);
        background: #fff;
        color: var(--brand-dark);
        border-radius: 999px;
        padding: 5px 10px;
        font-size: .74rem;
        font-weight: 800;
        cursor: pointer;
        transition: all .15s;
    }

    .recent-chip:hover {
        background: rgba(51, 199, 192, .08);
        border-color: rgba(51, 199, 192, .32);
    }

    #groupSummaryList {
        max-height: 320px;
        overflow-y: auto;
        padding: 2px;
        scrollbar-width: thin;
    }

    .group-card {
        border: 1px solid rgba(12, 83, 103, .10);
        border-radius: 13px;
        background: #fff;
        overflow: hidden;
        margin-bottom: 7px;
        box-shadow: 0 2px 8px rgba(8, 61, 80, .03);
    }

    .group-main {
        width: 100%;
        border: 0;
        background: linear-gradient(180deg, #fffefb, #fbf8f2);
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto 26px;
        gap: 8px;
        align-items: center;
        padding: 10px 12px;
        cursor: pointer;
        text-align: right;
        transition: background .15s;
    }

    .group-main:hover {
        background: linear-gradient(180deg, #fdfaf5, #f6f1e8);
    }

    .group-title {
        font-weight: 900;
        color: var(--brand-darker);
        font-size: .9rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .group-amount {
        font-weight: 900;
        color: var(--accent-dark);
        font-size: .86rem;
        white-space: nowrap;
    }

    .group-arrow {
        width: 24px;
        height: 24px;
        border-radius: 8px;
        border: 1px solid rgba(12, 83, 103, .12);
        color: var(--muted);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: transform .15s, all .15s;
        font-size: .76rem;
        background: #fff;
    }

    .group-card.is-open .group-arrow {
        transform: rotate(180deg);
        color: #fff;
        border-color: var(--brand);
        background: linear-gradient(135deg, var(--brand-dark), var(--brand));
    }

    .group-details {
        display: none;
        border-top: 1px solid rgba(12, 83, 103, .08);
        background: linear-gradient(180deg, #fcfaf6, #f8f4ed);
        padding: 10px;
    }

    .group-card.is-open .group-details {
        display: block;
    }

    .group-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 8px;
    }

    .details-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 7px;
    }

    .detail-pill {
        border: 1px solid rgba(12, 83, 103, .08);
        background: #fff;
        border-radius: 10px;
        padding: 7px 9px;
        font-size: .75rem;
    }

    .empty-state {
        border: 1px dashed rgba(12, 83, 103, .18);
        border-radius: 12px;
        background: linear-gradient(180deg, #fbf8f2, #f7f2e9);
        color: var(--muted);
        padding: 16px;
        text-align: center;
        font-weight: 800;
    }

    .final-grid {
        display: grid;
        grid-template-columns: 1.1fr .85fr .85fr 1fr auto;
        gap: 10px;
        align-items: end;
    }

    .total-view {
        font-weight: 900;
        color: var(--brand-darker);
        background: linear-gradient(180deg, #f8f7f1, #f1ede4) !important;
        border-color: rgba(12, 83, 103, .12);
    }

    .discount-control {
        display: grid;
        grid-template-columns: 80px 1fr;
        gap: 6px;
    }

    .submit-disabled-hint {
        font-size: .74rem;
        color: var(--muted);
        margin-top: 5px;
        text-align: center;
        font-weight: 700;
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--brand), #24b8c4);
        border-color: var(--brand);
        color: #fff;
        font-weight: 800;
    }

    .btn-primary:hover,
    .btn-primary:focus {
        background: linear-gradient(135deg, #26bac7, var(--brand-dark));
        border-color: var(--brand-dark);
        color: #fff;
    }

    .btn-outline-primary {
        color: var(--brand-dark);
        border-color: rgba(12, 83, 103, .22);
        background: #fff;
    }

    .btn-outline-primary:hover {
        background: linear-gradient(135deg, var(--brand), var(--brand-dark));
        border-color: var(--brand-dark);
        color: #fff;
    }

    .btn-outline-secondary {
        color: var(--brand-dark);
        border-color: rgba(12, 83, 103, .18);
        background: #fff;
    }

    .btn-outline-secondary:hover {
        background: rgba(12, 83, 103, .06);
        color: var(--brand-dark);
    }

    .btn-outline-success {
        color: var(--success);
        border-color: rgba(23, 140, 99, .26);
        background: #fff;
    }

    .btn-outline-success:hover {
        background: rgba(23, 140, 99, .07);
        color: var(--success);
    }

    .btn-outline-danger {
        color: var(--danger);
        border-color: rgba(209, 77, 77, .22);
        background: #fff;
    }

    .btn-outline-danger:hover {
        background: rgba(209, 77, 77, .07);
        color: var(--danger);
    }

    .btn-light.border {
        background: #fff;
        border-color: rgba(12, 83, 103, .13) !important;
    }

    .form-control,
    .form-select {
        border-radius: 10px;
        border-color: rgba(12, 83, 103, .13);
        color: var(--text);
        background-color: #fff;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--brand);
        box-shadow: 0 0 0 .18rem rgba(51, 199, 192, .12);
    }

    .select2-container {
        max-width: 100% !important;
    }

    .select2-container .select2-selection--single {
        min-height: 38px !important;
        border-color: rgba(12, 83, 103, .13) !important;
        border-radius: .7rem !important;
        padding-top: 4px;
        background: #fff !important;
    }

    .select2-container .select2-selection--single .select2-selection__rendered {
        line-height: 28px !important;
        padding-right: 12px !important;
        color: var(--text) !important;
    }

    .select2-container .select2-selection--single .select2-selection__arrow {
        height: 36px !important;
    }

    .alert-success {
        background: linear-gradient(180deg, rgba(23, 140, 99, .10), rgba(23, 140, 99, .05));
        color: #146948;
    }

    .alert-danger {
        background: linear-gradient(180deg, rgba(209, 77, 77, .10), rgba(209, 77, 77, .05));
        color: #9d3434;
    }

    .modal-dialog {
        margin: .5rem auto;
    }

    .modal-xl {
        max-width: 860px;
        width: calc(100vw - 16px);
    }

    .modal-content {
        border: 0;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 16px 36px rgba(8, 61, 80, .13);
    }

    .picker-head {
        background: linear-gradient(135deg, rgba(12, 83, 103, .96), rgba(51, 199, 192, .92));
        color: #fff;
        border-bottom: 0;
        padding: 14px 16px;
    }

    .picker-head .modal-title,
    .picker-head .hint {
        color: #fff !important;
    }

    .picker-head .btn-close {
        filter: invert(1);
        opacity: .9;
    }

    .variant-list {
        max-height: 52vh;
        overflow-y: auto;
        overflow-x: hidden;
        border: 1px solid rgba(12, 83, 103, .08);
        border-radius: 12px;
        background: linear-gradient(180deg, #fffefc, #faf6ef);
        padding: 7px;
    }

    .variant-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 10px;
        align-items: center;
        border: 1px solid rgba(12, 83, 103, .08);
        border-radius: 11px;
        padding: 9px 11px;
        background: #fff;
        margin-bottom: 6px;
        transition: border-color .12s;
    }

    .variant-row:last-child {
        margin-bottom: 0;
    }

    .variant-row.row-selected {
        background: linear-gradient(180deg, rgba(51, 199, 192, .09), rgba(51, 199, 192, .04));
        border-color: rgba(51, 199, 192, .30);
    }

    .variant-row.row-empty-stock {
        opacity: .52;
        pointer-events: none;
        background: #fcfaf7;
    }

    .variant-title {
        font-weight: 900;
        color: var(--brand-darker);
        font-size: .88rem;
        line-height: 1.6;
    }

    .variant-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
        margin-top: 5px;
    }

    .qty-control {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        direction: ltr;
    }

    .qty-btn {
        width: 32px;
        height: 32px;
        border: 1px solid rgba(12, 83, 103, .14);
        background: #fff;
        border-radius: 9px;
        font-weight: 900;
        font-size: 1rem;
        line-height: 1;
        color: var(--brand-dark);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background .12s;
    }

    .qty-btn:hover {
        background: rgba(51, 199, 192, .10);
        border-color: rgba(51, 199, 192, .30);
    }

    .qty-input {
        width: 54px;
        height: 32px;
        text-align: center;
        font-weight: 900;
        direction: ltr;
        border-radius: 9px;
        border: 1px solid rgba(12, 83, 103, .13);
        font-size: .9rem;
    }

    .qty-input:focus {
        border-color: var(--brand);
        box-shadow: 0 0 0 .15rem rgba(51, 199, 192, .12);
        outline: none;
    }

    .modal-summary-bar {
        background: linear-gradient(180deg, #f4f9f8, #edf6f5);
        border: 1px solid rgba(51, 199, 192, .18);
        border-radius: 11px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px;
    }

    .summary-stat {
        text-align: center;
    }

    .summary-stat .s-label {
        font-size: .7rem;
        color: var(--muted);
        font-weight: 700;
    }

    .summary-stat .s-val {
        font-size: .95rem;
        font-weight: 900;
        color: var(--brand-darker);
        margin-top: 1px;
    }

    .modal-discount-box {
        margin-top: 10px;
        background: linear-gradient(180deg, #fffefb, #f9f5ee);
        border: 1px solid rgba(12, 83, 103, .08);
        border-radius: 12px;
        padding: 10px 12px;
    }

    .discount-line {
        color: var(--brand-dark);
        font-size: .78rem;
        margin-top: 5px;
        font-weight: 700;
    }

    .picker-search {
        border: 1px solid rgba(12, 83, 103, .13);
        border-radius: 10px;
        padding: 7px 12px;
        font-size: .88rem;
        width: 100%;
        background: #fff;
        color: var(--text);
    }

    .picker-search:focus {
        border-color: var(--brand);
        box-shadow: 0 0 0 .15rem rgba(51, 199, 192, .12);
        outline: none;
    }

    @media (max-width: 991.98px) {
        .page-shell {
            max-width: 100%;
        }

        .final-grid {
            grid-template-columns: 1fr;
        }

        .details-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 575.98px) {
        body {
            font-size: 13px;
        }

        .container {
            padding-left: 9px;
            padding-right: 9px;
        }

        .compact-card,
        .product-focus,
        .final-card {
            padding: 10px;
            border-radius: 12px;
        }

        #groupSummaryList {
            max-height: 240px;
        }

        .modal-dialog {
            width: 100%;
            max-width: 100%;
            margin: 0;
        }

        .modal-content {
            min-height: 100vh;
            border-radius: 0;
        }

        .modal-body {
            padding: 9px;
        }

        .modal-header,
        .modal-footer {
            padding: 10px;
        }

        .modal-footer {
            position: sticky;
            bottom: 0;
            z-index: 20;
            background: linear-gradient(180deg, #f9f6ee, #f3eee5) !important;
        }

        .variant-list {
            max-height: calc(100vh - 380px);
            min-height: 200px;
            padding: 5px;
        }

        .variant-row {
            grid-template-columns: 1fr;
            gap: 7px;
            padding: 8px 9px;
        }

        .qty-control {
            width: 100%;
            justify-content: space-between;
        }

        .qty-btn {
            width: 36px;
            height: 36px;
        }

        .qty-input {
            width: 60px;
            height: 36px;
        }

        .details-grid {
            grid-template-columns: 1fr;
        }

        .modal-summary-bar {
            flex-direction: column;
            gap: 10px;
        }

        .summary-stat {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            text-align: right;
        }

        .summary-stat .s-label {
            font-size: .75rem;
        }

        .summary-stat .s-val {
            font-size: 1rem;
        }
    }


    /* Final mobile cleanup: simpler cards, safer modal height, and easier numeric typing */
    .preinvoice-note-box textarea {
        min-height: 72px;
        resize: vertical;
    }

    input[type="number"] {
        direction: ltr;
    }

    .qty-input {
        -webkit-user-select: text;
        user-select: text;
        touch-action: manipulation;
    }

    #submitOrderBtn {
        min-width: 150px;
    }

    @media (max-width: 575.98px) {
        .page-shell {
            padding-top: 8px !important;
            padding-bottom: 12px !important;
        }

        .page-title {
            font-size: 1.05rem;
        }

        .page-shell>.d-flex:first-child {
            align-items: flex-start !important;
            margin-bottom: 10px !important;
        }

        .page-shell>.d-flex:first-child>div:last-child {
            width: 100%;
            display: grid !important;
            grid-template-columns: 1fr 1fr;
            gap: 7px !important;
        }

        #localDraftStatus {
            grid-column: 1 / -1;
            justify-content: center;
            min-height: 34px;
        }

        #clearLocalDraftTopBtn,
        .page-shell>.d-flex:first-child>div:last-child>a {
            width: 100%;
            min-height: 36px;
        }

        .soft-card,
        .soft-card-lg {
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(8, 61, 80, .055);
        }

        .soft-card::before,
        .soft-card-lg::before {
            height: 2px;
        }

        .section-title {
            font-size: .9rem;
        }

        .hint {
            font-size: .76rem;
        }

        .quick-area {
            padding: 10px;
        }

        .code-input {
            height: 44px;
            font-size: 1.25rem;
            letter-spacing: 4px;
        }

        .find-btn {
            height: 42px;
        }

        .group-main {
            grid-template-columns: minmax(0, 1fr) auto 24px;
            padding: 9px 10px;
        }

        .group-title,
        .group-amount {
            font-size: .82rem;
        }

        .final-card {
            margin-bottom: 14px;
        }

        .final-grid {
            gap: 9px;
        }

        #submitOrderBtn {
            width: 100%;
            min-height: 44px;
        }

        .submit-disabled-hint {
            text-align: center;
        }

        .modal-dialog.modal-xl,
        .modal-dialog {
            width: 100%;
            max-width: 100%;
            height: 100dvh;
            margin: 0;
        }

        .modal-dialog-scrollable .modal-content,
        .modal-content {
            height: 100dvh;
            max-height: 100dvh;
            min-height: 0;
            border-radius: 0;
        }

        .picker-head {
            flex: 0 0 auto;
        }

        .modal-body {
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-height: 0;
        }

        .variant-list {
            flex: 1 1 auto;
            min-height: 0;
            max-height: none;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .modal-discount-box,
        .modal-summary-bar {
            flex: 0 0 auto;
        }

        .modal-summary-bar {
            padding: 8px 10px;
        }

        .modal-footer {
            flex: 0 0 auto;
            position: static;
            padding-bottom: calc(10px + env(safe-area-inset-bottom));
            display: grid;
            grid-template-columns: 1fr 1fr 1.25fr;
            gap: 7px;
        }

        .modal-footer .btn {
            width: 100%;
            margin: 0 !important;
            min-height: 40px;
            padding-left: 6px;
            padding-right: 6px;
            font-size: .82rem;
        }

        .variant-row {
            grid-template-columns: 1fr;
            gap: 8px;
        }

        .variant-meta {
            gap: 4px;
        }

        .badge-soft {
            font-size: .68rem;
            padding: 3px 7px;
        }

        .qty-control {
            display: grid;
            grid-template-columns: 42px minmax(64px, 1fr) 42px;
            gap: 7px;
            width: 100%;
        }

        .qty-btn,
        .qty-input {
            height: 40px;
            width: 100%;
        }

        .qty-input {
            font-size: 1rem;
        }
    }
</style>

<div class="container page-shell py-3">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h1 class="page-title">{{ $isEdit ? '📝 ویرایش پیش‌فاکتور' : '🧾 ثبت پیش‌فاکتور' }}</h1>
            <div class="hint mt-1">{{ $isEdit ? 'کد سند: ' . $order->uuid . ' | وضعیت: ' . $order->status_label : 'ثبت سریع کالا با کد ۴ رقمی محصول مادر' }}</div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            @unless($isEdit)
            <span class="autosave-pill" id="localDraftStatus">ذخیره خودکار فعال</span>
            <button type="button" class="btn btn-sm btn-outline-danger rounded-3" id="clearLocalDraftTopBtn">پاک‌کردن پیش‌نویس</button>
            @else
            <span class="autosave-pill is-saved">{{ $order->status_label }}</span>
            @if($order->invoice)
            <span class="autosave-pill">فاکتور: {{ $order->invoice->uuid }}</span>
            @endif
            @endunless
            <a class="btn btn-sm btn-outline-secondary rounded-3" href="{{ route('preinvoice.warehouse.index') }}">صف تایید انبار</a>
        </div>
    </div>

    <div class="local-draft-banner" id="localDraftBanner">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <div>
                <div class="fw-bold" style="color:var(--brand-darker)">پیش‌نویس ذخیره‌شده پیدا شد</div>
                <div class="hint mt-1" id="localDraftBannerText">می‌توانید ادامه ثبت پیش‌فاکتور قبلی را لود کنید.</div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-sm btn-primary rounded-3" id="loadLocalDraftBtn">لود پیش‌نویس</button>
                <button type="button" class="btn btn-sm btn-outline-danger rounded-3" id="discardLocalDraftBtn">حذف پیش‌نویس</button>
            </div>
        </div>
    </div>

    @if(session('success'))
    <div class="alert alert-success border-0 shadow-sm rounded-4 fw-bold py-2">✅ {{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-bold py-2" style="white-space:pre-wrap">{!! session('error') !!}</div>
    @endif
    @if($hasLegacyDiscount)
    <div class="alert alert-warning border-0 shadow-sm rounded-4 py-2">این سند با ساختار قدیمی تخفیف ثبت شده است؛ تخفیف فعلی به عنوان تخفیف کلی مبلغی نمایش داده می‌شود.</div>
    @endif
    @if($errors->any())
    <div class="alert alert-danger border-0 shadow-sm rounded-4 py-2">
        <div class="fw-bold mb-1">⚠️ خطا:</div>
        <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
    @endif

    <form action="{{ $isEdit ? route('preinvoice.draft.update', $order->uuid) : route('preinvoice.draft.save') }}" method="POST" id="orderForm" autocomplete="off">
        @csrf
        @if($isEdit) @method('PUT') @endif

        <input type="hidden" name="customer_id" id="customer_id" value="{{ old('customer_id', $order->customer_id ?? '') }}">
        <input type="hidden" name="customer_name" id="customer_name" value="{{ old('customer_name', $order->customer_name ?? '') }}">
        <input type="hidden" name="customer_mobile" id="customer_mobile" value="{{ old('customer_mobile', $order->customer_mobile ?? '') }}">
        <input type="hidden" name="payment_status" value="pending">
        <input type="hidden" name="reservation_token" id="reservation_token" value="{{ old('reservation_token', old('draft_token')) }}">
        <input type="hidden" name="draft_token" id="draft_token" value="{{ old('draft_token', old('reservation_token')) }}">
        <input type="hidden" name="discount_breakdown" id="discount_breakdown" value="">

        <div class="soft-card compact-card mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-lg-5">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <h2 class="section-title">👤 مشتری</h2>
                            <div class="hint">جستجو با نام یا موبایل</div>
                        </div>
                        <a href="{{ $customersPageUrl }}" class="btn btn-sm btn-outline-success rounded-3">➕ افزودن</a>
                    </div>
                    <select id="customer_search_select" class="form-select"></select>
                </div>
                <div class="col-lg-7">
                    <div id="customerSummaryBox" class="customer-box h-100 {{ old('customer_id') || $oldCustomerTitle ? 'is-selected' : '' }}">
                        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                            <div>
                                <div class="fw-bold" id="selectedCustomerTitle">
                                    @if($oldCustomerTitle)
                                    {{ $oldCustomerTitle }} @if($oldCustomerMobile) - {{ $oldCustomerMobile }} @endif
                                    @else هنوز مشتری انتخاب نشده است @endif
                                </div>
                                <div class="hint mt-1" id="customer_balance_hint"></div>
                            </div>
                            <button type="button" id="clearCustomerBtn" class="btn btn-sm btn-light border rounded-3">تغییر</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="soft-card compact-card mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-lg-4">
                    <h2 class="section-title mb-2">🚚 ارسال</h2>
                    <label class="label-sm">شیوه ارسال</label>
                    <select id="shipping_id" name="shipping_id" class="form-select form-select-sm" required>
                        <option value="">انتخاب روش ارسال...</option>
                    </select>
                    <input type="hidden" id="shipping_price" name="shipping_price" value="{{ old('shipping_price', $order->shipping_price ?? 0) }}">
                </div>
                <div class="col-lg-4" id="provinceBox">
                    <label class="label-sm">استان</label>
                    <select id="province_id" name="province_id" class="form-select form-select-sm">
                        <option value=""></option>
                    </select>
                </div>
                <div class="col-lg-4" id="cityBox">
                    <label class="label-sm">شهر</label>
                    <select id="city_id" name="city_id" class="form-select form-select-sm">
                        <option value=""></option>
                    </select>
                </div>
            </div>
            <div class="mt-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="hint" id="shipping_mode_hint">روش ارسال را انتخاب کنید.</div>
                <button class="btn btn-sm btn-light border rounded-3" type="button" data-bs-toggle="collapse" data-bs-target="#addressCollapse">آدرس ارسال</button>
            </div>
            <div id="addressCollapse" class="collapse mt-2 {{ old('customer_address', $order->customer_address ?? '') ? 'show' : '' }}">
                <textarea id="customer_address" name="customer_address" class="form-control form-control-sm" rows="2" placeholder="آدرس ارسال...">{{ old('customer_address', $order->customer_address ?? '') }}</textarea>
            </div>
            <div class="preinvoice-note-box mt-2">
                <label class="label-sm">توضیحات پیش‌فاکتور</label>
                <textarea id="preinvoice_description" name="description" class="form-control form-control-sm" rows="2" placeholder="توضیحات داخلی، نکته فروش، هماهنگی با انبار یا توضیح مخصوص این پیش‌فاکتور...">{{ $oldPreinvoiceDescription }}</textarea>
            </div>
        </div>

        <div class="soft-card-lg product-focus mb-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h2 class="section-title">🧩 ثبت سریع کالا</h2>
                    <div class="hint mt-1">کد ۴ رقمی محصول مادر را وارد کنید</div>
                </div>
                <span class="badge-soft badge-brand">فروش سالن آریا</span>
            </div>

            <div class="quick-area mb-3">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-4 col-sm-5">
                        <label class="label-sm">کد محصول مادر</label>
                        <input type="text" id="motherCodeInput" class="code-input" maxlength="4" inputmode="numeric" placeholder="4450">
                    </div>
                    <div class="col-lg-2 col-sm-3">
                        <button type="button" id="findMotherBtn" class="find-btn w-100">مشاهده</button>
                    </div>
                    <div class="col-lg-6 col-sm-4">
                        <label class="label-sm d-lg-none mt-2">جستجوی نام محصول</label>
                        <select id="motherProductAjaxSelect" class="form-select mb-2" aria-label="جستجوی محصول"></select>
                       
                        <div id="motherProductBox" style="display:none">
                            <div class="customer-box is-selected d-flex justify-content-between align-items-center gap-2">
                                <div>
                                    <div class="hint" style="font-size:.73rem">محصول انتخاب‌شده</div>
                                    <div class="fw-bold" id="motherProductTitle" style="font-size:.9rem">—</div>
                                    <div class="hint" id="motherProductCode">—</div>
                                </div>
                                <button type="button" id="openGroupPickerBtn" class="btn btn-sm btn-outline-primary rounded-3 fw-bold">انتخاب تنوع</button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <div>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <h2 class="section-title">سبد پیش‌فاکتور</h2>
                    <div class="hint" id="orderItemsCountHint">۰ کالا</div>
                </div>
                <div id="groupSummaryList"></div>
                <div id="groupProductsInputs"></div>
            </div>
        </div>

        <div class="soft-card final-card">
            <input type="hidden" name="discount_amount" id="discount" value="{{ \App\Support\Currency::toRial(old('discount_amount', $order->discount_amount ?? 0)) }}">
            <div class="final-grid">
                <div>
                    <label class="label-sm">تخفیف کلی</label>
                    <div class="discount-control">
                        <select id="orderDiscountType" class="form-select form-select-sm">
                            <option value="amount">ریال</option>
                            <option value="percent">درصد</option>
                        </select>
                        <input type="number" id="orderDiscountValue" class="form-control form-control-sm" min="0" step="0.01" inputmode="decimal" value="{{ \App\Support\Currency::toRial(old('discount_amount', $order->discount_amount ?? 0)) }}" placeholder="مقدار">
                    </div>
                    <div class="discount-line" id="orderDiscountPreview">تخفیف کلی: 0 ریال</div>
                </div>
                <div>
                    <label class="label-sm">هزینه ارسال</label>
                    <input type="text" id="shipping_price_view" class="form-control form-control-sm bg-light" readonly value="0 ریال">
                </div>
                <div>
                    <label class="label-sm">مجموع تخفیف</label>
                    <input type="text" id="totalDiscountView" class="form-control form-control-sm bg-light" readonly value="0 ریال">
                </div>
                <div>
                    <label class="label-sm">جمع کل</label>
                    <input type="text" name="total_price" id="total_price" class="form-control form-control-sm total-view" readonly value="0">
                </div>
                <div>
                    <button class="btn btn-primary px-4 py-2 rounded-3 fw-bold" id="submitOrderBtn" disabled>{{ $isEdit ? 'ذخیره و ارسال مجدد برای تایید' : 'ثبت پیش‌فاکتور' }}</button>
                    <div class="submit-disabled-hint" id="submitHint">برای ثبت، مشتری و حداقل یک کالا لازم است.</div>
                </div>
            </div>
        </div>
    </form>
</div>

<div class="modal fade" id="groupPickerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header picker-head">
                <div>
                    <h5 class="modal-title fw-bold" id="pickerModalTitle">انتخاب تنوع</h5>
                    <div class="hint mt-1" id="pickerModalSubTitle">—</div>
                </div>
                <button type="button" class="btn-close m-0" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="mb-2">
                    <input type="text" id="pickerSearchInput" class="picker-search" placeholder="جستجو در تنوع‌ها...">
                </div>

                <div id="pickerLoading" class="empty-state d-none">در حال دریافت کالاها...</div>

                <div class="variant-list" id="pickerTableWrap">
                    <div id="groupPickerRows"></div>
                </div>

                <div class="modal-discount-box">
                    <label class="label-sm">تخفیف این محصول</label>
                    <div class="discount-control">
                        <select id="modalGroupDiscountType" class="form-select form-select-sm">
                            <option value="amount">ریال</option>
                            <option value="percent">درصد</option>
                        </select>
                        <input type="number" id="modalGroupDiscountValue" class="form-control form-control-sm" min="0" step="0.01" inputmode="decimal" value="0" placeholder="مقدار تخفیف">
                    </div>
                    <div class="discount-line">مبلغ تخفیف: <strong id="modalGroupDiscountPreview">0 ریال</strong></div>
                </div>

                <div class="modal-summary-bar mt-2">
                    <div class="summary-stat">
                        <div class="s-label">ردیف انتخاب‌شده</div>
                        <div class="s-val" id="modalSelectedRows">0</div>
                    </div>
                    <div class="summary-stat">
                        <div class="s-label">جمع تعداد</div>
                        <div class="s-val" id="modalTotalQty">0</div>
                    </div>
                    <div class="summary-stat">
                        <div class="s-label">مبلغ قبل تخفیف</div>
                        <div class="s-val" id="modalRawAmount">0 ریال</div>
                    </div>
                    <div class="summary-stat">
                        <div class="s-label">جمع نهایی</div>
                        <div class="s-val" id="modalTotalAmount" style="color:var(--accent-dark)">0 ریال</div>
                    </div>
                </div>
            </div>

            <div class="modal-footer" style="background:linear-gradient(180deg,#f9f6ee,#f3eee5);border-top:1px solid rgba(12,83,103,.08);">
                <button type="button" class="btn btn-light border rounded-3" id="clearPickerQtyBtn">پاک کردن</button>
                <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">لغو</button>
                <button type="button" id="saveGroupSelectionBtn" class="btn btn-primary rounded-3 fw-bold px-4">افزودن به سبد</button>
            </div>
        </div>
    </div>
</div>

<script>
    window.PREINVOICE_BOOT = {
        api: {
            products: @json(url('/preinvoice/api/products')),
            product: @json(url('/preinvoice/api/products')),
            reservationsSync: @json(route('preinvoice.api.reservations.sync')),
            reservationsRelease: @json(route('preinvoice.api.reservations.release')),
            area: @json(url('/preinvoice/api/area')),
            customers: @json(url('/preinvoice/api/customers')),
            customer: @json(url('/preinvoice/api/customers'))
        },
        initRows: @json($initRows),
        shippings: @json($shippingMethods ?? []),
        oldCustomerId: @json(old('customer_id', $order->customer_id ?? '')),
        oldCustomerName: @json(old('customer_name', $order->customer_name ?? '')),
        oldCustomerMobile: @json(old('customer_mobile', $order->customer_mobile ?? '')),
        oldCustomerAddress: @json(old('customer_address', $order->customer_address ?? '')),
        oldDescription: @json($oldPreinvoiceDescription),
        oldProvinceId: @json(old('province_id', $order->province_id ?? '')),
        oldCityId: @json(old('city_id', $order->city_id ?? '')),
        oldShippingId: @json(old('shipping_id', $order->shipping_id ?? '')),
        oldDiscountAmount: @json(old('discount_amount', $order->discount_amount ?? 0)),
        initialDiscountBreakdown: @json($initialDiscountBreakdown),
        hasLegacyDiscount: @json($hasLegacyDiscount),
        isEdit: @json($isEdit),
        orderUuid: @json($order->uuid ?? null)
    };

    const API = window.PREINVOICE_BOOT.api;
    const INIT_ROWS = window.PREINVOICE_BOOT.initRows || [];
    const INITIAL_SHIPPINGS = window.PREINVOICE_BOOT.shippings || [];
    const OLD_CUSTOMER_ID = window.PREINVOICE_BOOT.oldCustomerId;
    const OLD_CUSTOMER_NAME = window.PREINVOICE_BOOT.oldCustomerName;
    const OLD_CUSTOMER_MOBILE = window.PREINVOICE_BOOT.oldCustomerMobile;
    const OLD_CUSTOMER_ADDRESS = window.PREINVOICE_BOOT.oldCustomerAddress;
    const OLD_PROVINCE_ID = window.PREINVOICE_BOOT.oldProvinceId;
    const OLD_CITY_ID = window.PREINVOICE_BOOT.oldCityId;
    const OLD_SHIPPING_ID = window.PREINVOICE_BOOT.oldShippingId;
    const OLD_DISCOUNT_AMOUNT = window.PREINVOICE_BOOT.oldDiscountAmount;
    const INITIAL_DISCOUNT_BREAKDOWN = window.PREINVOICE_BOOT.initialDiscountBreakdown || null;
    const HAS_LEGACY_DISCOUNT = !!window.PREINVOICE_BOOT.hasLegacyDiscount;
    const IS_EDIT = !!window.PREINVOICE_BOOT.isEdit;
    const EDIT_ORDER_UUID = window.PREINVOICE_BOOT.orderUuid || null;

    let shippings = INITIAL_SHIPPINGS || [];
    let areaProvinces = [];
    const productCache = new Map();

    let selectedMotherProduct = null;
    let activeProductId = null;
    let activeProduct = null;
    let activeModalItems = [];
    let modalQuantities = new Map();
    let modalGroupDiscountType = 'amount';
    let modalGroupDiscountValue = 0;

    let groupedSelections = {};
    let motherAutoTimer = null;
    let lastMotherAutoCode = '';
    let isSubmittingProgrammatically = false;
    let isHydratingLocalDraft = false;
    let isBootingPage = true;
    let localDraftSaveTimer = null;

    const RECENT_PRODUCTS_KEY = 'aria_preinvoice_recent_mothers_v3';
    const LOCAL_DRAFT_VERSION = 1;
    const LOCAL_DRAFT_KEY = 'aria_preinvoice_local_draft_create_v1';
    const RESERVATION_TOKEN_KEY = 'aria_preinvoice_reservation_token_v1';
    let isSyncingReservation = false;


    function cryptoRandomUuidFallback() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    function ensureReservationToken() {
        if (IS_EDIT) return '';
        let token = normalize(document.getElementById('reservation_token')?.value);
        if (!token) token = normalize(localStorage.getItem(RESERVATION_TOKEN_KEY));
        if (!token) token = window.crypto?.randomUUID ? window.crypto.randomUUID() : cryptoRandomUuidFallback();
        localStorage.setItem(RESERVATION_TOKEN_KEY, token);
        const input = document.getElementById('reservation_token');
        if (input) input.value = token;
        const draftInput = document.getElementById('draft_token');
        if (draftInput) draftInput.value = token;
        return token;
    }

    function reservationItemsFromGroups(sourceGroups = groupedSelections) {
        const rows = [];
        Object.values(sourceGroups || {}).forEach(group => {
            const productId = Number(group?.product?.id || 0);
            if (!productId) return;
            (group.items || []).forEach(item => {
                const variantId = Number(item.variant_id || 0);
                const quantity = Number(item.quantity || 0);
                if (variantId > 0 && quantity > 0) {
                    rows.push({ product_id: productId, variant_id: variantId, quantity });
                }
            });
        });
        return rows;
    }

    async function postReservation(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify(body)
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok || json?.ok === false) {
            const message = Object.values(json?.errors || {}).flat().join('\n') || json?.message || 'خطا در فریز موجودی پیش‌فاکتور.';
            throw new Error(message);
        }
        return json;
    }

    async function syncDraftReservation(sourceGroups = groupedSelections) {
        if (IS_EDIT) return { ok: true };
        const token = ensureReservationToken();
        isSyncingReservation = true;
        try {
            const response = await postReservation(API.reservationsSync, {
                reservation_token: token,
                items: reservationItemsFromGroups(sourceGroups)
            });
            productCache.clear();
            return response;
        } finally {
            isSyncingReservation = false;
        }
    }

    async function releaseDraftReservation() {
        const token = normalize(document.getElementById('reservation_token')?.value) || normalize(localStorage.getItem(RESERVATION_TOKEN_KEY));
        if (!token) return;
        await postReservation(API.reservationsRelease, { reservation_token: token });
        localStorage.removeItem(RESERVATION_TOKEN_KEY);
        const input = document.getElementById('reservation_token');
        if (input) input.value = '';
        const draftInput = document.getElementById('draft_token');
        if (draftInput) draftInput.value = '';
        productCache.clear();
    }

    function releaseDraftReservationBeacon() {
        if (IS_EDIT || isSubmittingProgrammatically) return;
        const token = normalize(document.getElementById('reservation_token')?.value) || normalize(localStorage.getItem(RESERVATION_TOKEN_KEY));
        if (!token || !API.reservationsRelease) return;
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const body = JSON.stringify({ reservation_token: token, _token: csrf });
        if (navigator.sendBeacon) {
            navigator.sendBeacon(API.reservationsRelease, new Blob([body], { type: 'application/json' }));
        } else {
            fetch(API.reservationsRelease, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body,
                keepalive: true
            }).catch(() => {});
        }
        localStorage.removeItem(RESERVATION_TOKEN_KEY);
    }

    function toEnglishDigits(str) {
        return String(str || '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    }

    function toInt(val) {
        const s = toEnglishDigits(val).replaceAll(',', '').replaceAll('٬', '').replaceAll('،', '').replace(/[^\d.-]/g, '').trim();
        const n = parseFloat(s);
        return Number.isFinite(n) ? Math.trunc(n) : 0;
    }

    function formatMoney(val) {
        return Number(val || 0).toLocaleString('fa-IR') + ' ریال';
    }

    function formatNum(val) {
        return Number(val || 0).toLocaleString('fa-IR');
    }

    function esc(val) {
        return String(val ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
    }

    function normalize(val) {
        return String(val || '').trim();
    }

    function isEmptyLabel(value) {
        const v = normalize(value);
        return !v || v === '—' || v === '-' || v === 'بدون مدل' || v === 'عمومی';
    }

    function safeDiscountValue(type, value) {
        let n = Number(value || 0);
        if (!Number.isFinite(n)) n = 0;
        if (n < 0) n = 0;
        if (type === 'percent' && n > 100) n = 100;
        return n;
    }

    function normalizeDiscountInput(type, value) {
        const safeType = type === 'percent' ? 'percent' : 'amount';
        const safeValue = safeDiscountValue(safeType, value);
        return safeType === 'percent' ? safeValue : Math.floor(safeValue);
    }

    function discountValueForInput(type, value) {
        const n = Number(value || 0);
        return type === 'percent' ? n : Math.floor(n);
    }

    function calcDiscount(baseAmount, type, value) {
        const base = Math.max(0, Number(baseAmount || 0));
        const safeType = type === 'percent' ? 'percent' : 'amount';
        const safeValue = safeDiscountValue(safeType, value);
        if (safeType === 'percent') return Math.min(base, Math.floor(base * safeValue / 100));
        return Math.min(base, Math.floor(safeValue));
    }

    function customerFullName(c) {
        if (!c) return '';
        const full = `${c.first_name || ''} ${c.last_name || ''}`.trim();
        return full || normalize(c.customer_name || c.name);
    }

    function productTitle(product) {
        return normalize(product?.title || product?.name) || 'بدون نام';
    }

    function productCode(product) {
        return normalize(product?.code || product?.sku || product?.short_code);
    }

    function getProductVarieties(product) {
        if (!product) return [];
        if (Array.isArray(product.varieties)) return product.varieties;
        if (Array.isArray(product.variants)) return product.variants;
        return [];
    }

    function variantId(v) {
        return Number(v?.id || 0);
    }

    function variantModel(v) {
        return normalize(v?.model_list_name || v?.model_name || v?.model_list?.name) || '—';
    }

    function variantDesign(v) {
        return normalize(v?.design_name || v?.pattern_name || v?.variety_name || v?.type_name) || '—';
    }

    function variantName(v) {
        return normalize(v?.variant_name || v?.color_name || v?.color || v?.name) || '—';
    }

    function variantPrice(v, product = null) {
        return Number(v?.sell_price ?? v?.price ?? product?.sell_price ?? product?.price ?? 0);
    }

    function variantStock(v) {
        if (v?.sellable_stock !== undefined && v?.sellable_stock !== null) return Number(v.sellable_stock) || 0;
        const stock = Number(v?.stock ?? v?.quantity ?? 0) || 0;
        return Math.max(0, stock);
    }

    function buildVariantTitle(v) {
        const model = variantModel(v);
        const design = variantDesign(v);
        const name = variantName(v);
        const parts = [];
        if (!isEmptyLabel(model)) parts.push(model);
        if (!isEmptyLabel(design)) parts.push(design);
        if (!isEmptyLabel(name)) parts.push(name);
        if (parts.length) return parts.join(' / ');
        return 'تنوع پیش‌فرض';
    }

    function groupRawSubtotal(group) {
        if (!group || !Array.isArray(group.items)) return 0;
        return group.items.reduce((sum, item) => sum + Number(item.quantity || 0) * Number(item.price || 0), 0);
    }

    function groupDiscountTotal(group) {
        return calcDiscount(groupRawSubtotal(group), group.discount_type || 'amount', group.discount_value || 0);
    }

    function groupFinalAmount(group) {
        return Math.max(0, groupRawSubtotal(group) - groupDiscountTotal(group));
    }

    function hasAnyFormData() {
        return !!(
            normalize(document.getElementById('customer_id')?.value) ||
            normalize(document.getElementById('customer_name')?.value) ||
            normalize(document.getElementById('customer_mobile')?.value) ||
            normalize(document.getElementById('customer_address')?.value) ||
            normalize(document.getElementById('preinvoice_description')?.value) ||
            normalize(document.getElementById('shipping_id')?.value) ||
            Object.keys(groupedSelections || {}).length ||
            toInt(document.getElementById('orderDiscountValue')?.value || 0) > 0
        );
    }

    function localDraftExists() {
        try {
            const raw = localStorage.getItem(LOCAL_DRAFT_KEY);
            if (!raw) return false;
            const data = JSON.parse(raw);
            return data && data.version === LOCAL_DRAFT_VERSION;
        } catch (e) {
            return false;
        }
    }

    function getLocalDraft() {
        try {
            const raw = localStorage.getItem(LOCAL_DRAFT_KEY);
            if (!raw) return null;
            const data = JSON.parse(raw);
            if (!data || data.version !== LOCAL_DRAFT_VERSION) return null;
            return data;
        } catch (e) {
            return null;
        }
    }

    async function removeLocalDraft(showMessage = true, releaseReservation = false) {
        localStorage.removeItem(LOCAL_DRAFT_KEY);
        hideLocalDraftBanner();
        if (releaseReservation) {
            try {
                await releaseDraftReservation();
            } catch (e) {
                alert(e.message || 'خطا در آزادسازی موجودی فریز شده.');
            }
        }
        if (showMessage) updateLocalDraftStatus('پیش‌نویس پاک شد', false);
    }

    function updateLocalDraftStatus(text, saved = false) {
        const el = document.getElementById('localDraftStatus');
        if (!el) return;
        el.textContent = text;
        el.classList.toggle('is-saved', !!saved);
    }

    function showLocalDraftBanner() {
        const draft = getLocalDraft();
        if (!draft) return;
        const banner = document.getElementById('localDraftBanner');
        const text = document.getElementById('localDraftBannerText');
        const savedAt = draft.saved_at ? new Date(draft.saved_at) : null;
        const savedText = savedAt && !Number.isNaN(savedAt.getTime()) ? savedAt.toLocaleString('fa-IR') : 'زمان نامشخص';
        const groups = draft.groupedSelections ? Object.keys(draft.groupedSelections).length : 0;
        text.textContent = `آخرین ذخیره: ${savedText} | تعداد محصول: ${formatNum(groups)}`;
        banner.classList.add('is-visible');
    }

    function hideLocalDraftBanner() {
        document.getElementById('localDraftBanner')?.classList.remove('is-visible');
    }

    function collectLocalDraftPayload() {
        return {
            version: LOCAL_DRAFT_VERSION,
            saved_at: new Date().toISOString(),
            reservation_token: ensureReservationToken(),
            customer: {
                id: document.getElementById('customer_id')?.value || '',
                name: document.getElementById('customer_name')?.value || '',
                mobile: document.getElementById('customer_mobile')?.value || '',
                title: document.getElementById('selectedCustomerTitle')?.textContent || '',
                balance_hint: document.getElementById('customer_balance_hint')?.textContent || ''
            },
            shipping: {
                shipping_id: document.getElementById('shipping_id')?.value || '',
                shipping_price: toInt(document.getElementById('shipping_price')?.value || 0),
                province_id: document.getElementById('province_id')?.value || '',
                city_id: document.getElementById('city_id')?.value || '',
                address: document.getElementById('customer_address')?.value || ''
            },
            description: document.getElementById('preinvoice_description')?.value || '',
            discount: {
                type: document.getElementById('orderDiscountType')?.value || 'amount',
                value: document.getElementById('orderDiscountValue')?.value || 0
            },
            groupedSelections: groupedSelections || {}
        };
    }

    function saveLocalDraftNow() {
        if (isBootingPage || isHydratingLocalDraft || isSubmittingProgrammatically) return;

        // خیلی مهم:
        // اگر فرم خالی بود، پیش‌نویس قبلی را پاک نمی‌کنیم.
        // فقط ذخیره انجام نمی‌دهیم.
        // حذف پیش‌نویس فقط با دکمه حذف یا بعد از ثبت موفق انجام می‌شود.
        if (!hasAnyFormData()) {
            updateLocalDraftStatus('ذخیره خودکار فعال', false);
            return;
        }

        try {
            localStorage.setItem(LOCAL_DRAFT_KEY, JSON.stringify(collectLocalDraftPayload()));
            updateLocalDraftStatus('ذخیره شد', true);

            setTimeout(() => {
                updateLocalDraftStatus('ذخیره خودکار فعال', false);
            }, 1600);
        } catch (e) {
            updateLocalDraftStatus('خطا در ذخیره محلی', false);
        }
    }

    function scheduleLocalDraftSave() {
        if (IS_EDIT) return;
        if (isBootingPage || isHydratingLocalDraft || isSubmittingProgrammatically) return;
        clearTimeout(localDraftSaveTimer);
        localDraftSaveTimer = setTimeout(saveLocalDraftNow, 350);
    }


    function setMotherSearchHintVisible(visible = true) {
        const hint = document.getElementById('motherSearchHint');
        if (hint) hint.style.display = visible ? '' : 'none';
    }

    function clearVisibleFormOnly() {
        document.getElementById('customer_id').value = '';
        document.getElementById('customer_name').value = '';
        document.getElementById('customer_mobile').value = '';
        document.getElementById('customer_address').value = '';
        const noteEl = document.getElementById('preinvoice_description');
        if (noteEl) noteEl.value = '';
        document.getElementById('selectedCustomerTitle').textContent = 'هنوز مشتری انتخاب نشده است';
        document.getElementById('customer_balance_hint').textContent = '';
        document.getElementById('customerSummaryBox').classList.remove('is-selected');
        if (window.jQuery) $('#customer_search_select').val(null).trigger('change');

        document.getElementById('shipping_id').value = '';
        document.getElementById('province_id').value = '';
        document.getElementById('city_id').value = '';
        if (window.jQuery) {
            $('#province_id').trigger('change.select2');
            $('#city_id').trigger('change.select2');
        }

        document.getElementById('orderDiscountType').value = 'amount';
        document.getElementById('orderDiscountValue').value = 0;
        groupedSelections = {};
        selectedMotherProduct = null;
        document.getElementById('motherCodeInput').value = '';
        document.getElementById('motherProductBox').style.display = 'none';
        setMotherSearchHintVisible(true);
    }

    async function applyLocalDraft(draft) {
        if (!draft) return;
        isHydratingLocalDraft = true;

        clearVisibleFormOnly();

        document.getElementById('customer_id').value = draft.customer?.id || '';
        document.getElementById('customer_name').value = draft.customer?.name || '';
        document.getElementById('customer_mobile').value = draft.customer?.mobile || '';

        const displayTitle = normalize(draft.customer?.title) || [draft.customer?.name, draft.customer?.mobile].filter(Boolean).join(' - ');
        if (displayTitle) {
            document.getElementById('selectedCustomerTitle').textContent = displayTitle;
            document.getElementById('customerSummaryBox').classList.add('is-selected');
        }
        document.getElementById('customer_balance_hint').textContent = draft.customer?.balance_hint || '';

        if (draft.customer?.id && window.jQuery) {
            const optionText = displayTitle || draft.customer.id;
            const selectEl = document.getElementById('customer_search_select');
            selectEl.add(new Option(optionText, draft.customer.id, true, true));
            $('#customer_search_select').trigger('change');
        }

        document.getElementById('shipping_id').value = draft.shipping?.shipping_id || '';
        document.getElementById('customer_address').value = draft.shipping?.address || '';
        const noteEl = document.getElementById('preinvoice_description');
        if (noteEl) noteEl.value = draft.description || '';
        document.getElementById('orderDiscountType').value = draft.discount?.type || 'amount';
        document.getElementById('orderDiscountValue').value = draft.discount?.value || 0;

        updateShippingMode();

        if (draft.shipping?.province_id) {
            document.getElementById('province_id').value = String(draft.shipping.province_id);
            if (window.jQuery) $('#province_id').trigger('change.select2');
            fillCitiesByProvinceId(draft.shipping.province_id);
        }

        if (draft.shipping?.city_id) {
            document.getElementById('city_id').value = String(draft.shipping.city_id);
            if (window.jQuery) $('#city_id').trigger('change.select2');
        }

        groupedSelections = draft.groupedSelections || {};
        if (draft.reservation_token) {
            document.getElementById('reservation_token').value = draft.reservation_token;
            localStorage.setItem(RESERVATION_TOKEN_KEY, draft.reservation_token);
        }
        try {
            await syncDraftReservation(groupedSelections);
        } catch (e) {
            alert(e.message || 'خطا در فریز موجودی پیش‌نویس.');
            groupedSelections = {};
        }
        renderGroupSummary();
        updateTotal();
        updateSubmitState();
        hideLocalDraftBanner();
        updateLocalDraftStatus('پیش‌نویس لود شد', true);

        isHydratingLocalDraft = false;
        scheduleLocalDraftSave();
    }

    function bindLocalDraftEvents() {
        document.getElementById('loadLocalDraftBtn')?.addEventListener('click', function() {
            const draft = getLocalDraft();
            if (!draft) {
                alert('پیش‌نویسی برای لود شدن پیدا نشد.');
                hideLocalDraftBanner();
                return;
            }
            applyLocalDraft(draft);
        });

        document.getElementById('discardLocalDraftBtn')?.addEventListener('click', async function() {
            if (!confirm('پیش‌نویس ذخیره‌شده حذف شود؟')) return;
            await removeLocalDraft(true, true);
        });

        document.getElementById('clearLocalDraftTopBtn')?.addEventListener('click', async function() {
            if (!confirm('پیش‌نویس محلی و فرم فعلی پاک شود؟')) return;
            isHydratingLocalDraft = true;
            clearVisibleFormOnly();
            try {
                await releaseDraftReservation();
            } catch (e) {
                alert(e.message || 'خطا در آزادسازی موجودی فریز شده.');
            }
            renderGroupSummary();
            updateShippingMode();
            updateTotal();
            updateSubmitState();
            isHydratingLocalDraft = false;
            await removeLocalDraft(true, false);
        });

        ['customer_address', 'preinvoice_description', 'shipping_id', 'province_id', 'city_id', 'orderDiscountType', 'orderDiscountValue'].forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('change', scheduleLocalDraftSave);
            el.addEventListener('input', scheduleLocalDraftSave);
        });

        window.addEventListener('beforeunload', function() {
            saveLocalDraftNow();
        });
        window.addEventListener('pagehide', releaseDraftReservationBeacon);
    }

    async function getProductDetails(productId, fresh = false) {
        const id = String(productId || '');
        if (!id) return null;
        const token = ensureReservationToken();
        const cacheKey = id + ':' + token + ':' + (IS_EDIT ? (EDIT_ORDER_UUID || '') : '');
        if (!fresh && productCache.has(cacheKey)) return productCache.get(cacheKey);
        const params = new URLSearchParams();
        if (token) params.set('reservation_token', token);
        if (IS_EDIT && EDIT_ORDER_UUID) params.set('preinvoice_uuid', EDIT_ORDER_UUID);
        if (fresh) params.set('_', Date.now());
        const qs = params.toString();
        const url = API.product + '/' + encodeURIComponent(id) + (qs ? '?' + qs : '');
        const res = await fetch(url, {
            headers: {
                'Accept': 'application/json'
            }
        });
        const json = await res.json();
        const product = json?.data?.product || null;
        if (product) productCache.set(cacheKey, product);
        return product;
    }

    async function searchProducts(query) {
        const params = new URLSearchParams({ q: query });
        const token = ensureReservationToken();
        if (token) params.set('reservation_token', token);
        const res = await fetch(API.products + '?' + params.toString(), {
            headers: {
                'Accept': 'application/json'
            }
        });
        const json = await res.json();
        return json?.data?.products?.data || [];
    }

    function productAjaxText(product) {
        if (!product) return '';
        const code = productCode(product);
        const stock = Number(product.quantity ?? product.stock ?? 0) || 0;
        return [productTitle(product), code ? 'کد: ' + code : '', 'موجودی: ' + formatNum(stock)].filter(Boolean).join(' | ');
    }

    function applyMotherProduct(product, autoOpen = true) {
        if (!product) return;
        selectedMotherProduct = product;
        setMotherSearchHintVisible(false);
        document.getElementById('motherProductBox').style.display = 'block';
        document.getElementById('motherProductTitle').textContent = productTitle(product);
        document.getElementById('motherProductCode').textContent = 'کد: ' + (productCode(product) || '—');
        const codeInput = document.getElementById('motherCodeInput');
        if (codeInput && productCode(product)) codeInput.value = String(productCode(product)).replace(/\D/g, '').slice(-4);
        saveRecentProduct(product);
        if (autoOpen) openGroupPicker(product.id);
    }

    function initMotherAjaxSearch() {
        const selectEl = document.getElementById('motherProductAjaxSelect');
        if (!window.jQuery || !window.jQuery.fn?.select2 || !selectEl) return;
        const $el = $(selectEl);
        $el.select2({
            width: '100%',
            dir: 'rtl',
            placeholder: 'جستجوی  محصول با نام، کد، SKU یا بارکد...',
            allowClear: true,
            minimumInputLength: 1,
            ajax: {
                url: API.products,
                dataType: 'json',
                delay: 250,
                data: params => ({ q: params.term || '' }),
                processResults: resp => {
                    const items = resp?.data?.products?.data || [];
                    return {
                        results: items.map(p => ({
                            id: p.id,
                            text: productAjaxText(p),
                            product: p
                        }))
                    };
                }
            },
            templateResult: item => item.loading ? item.text : $('<span>').text(item.text),
            templateSelection: item => item.text || 'جستجوی محصول'
        });
        $el.on('select2:select', function(e) {
            const product = e?.params?.data?.product || null;
            applyMotherProduct(product, true);
        });
        $el.on('select2:clear', function() {
            if (selectedMotherProduct) return;
            document.getElementById('motherProductBox').style.display = 'none';
            setMotherSearchHintVisible(true);
        });
    }

    function shippingById(id) {
        return shippings.find(s => Number(s.id) === Number(id)) || null;
    }

    function isInPersonShipping(ship) {
        const name = normalize(ship?.name);
        return name.includes('حضوری') || name.includes('مراجعه');
    }

    function initSelect2Basic(selectEl, placeholder) {
        if (!window.jQuery || !window.jQuery.fn?.select2 || !selectEl) return;
        const $el = $(selectEl);
        if ($el.hasClass('select2-hidden-accessible')) {
            $el.off('select2:select select2:clear');
            $el.select2('destroy');
        }
        $el.select2({
            width: '100%',
            dir: 'rtl',
            placeholder,
            allowClear: true
        });
        $el.on('select2:select select2:clear', function() {
            this.dispatchEvent(new Event('change', {
                bubbles: true
            }));
        });
    }

    async function loadArea() {
        try {
            const res = await fetch(API.area, {
                headers: {
                    'Accept': 'application/json'
                }
            });
            const data = await res.json();
            areaProvinces = data?.data?.provinces || [];
        } catch (e) {
            areaProvinces = [];
        }
    }

    function fillProvincesSelect() {
        const s = document.getElementById('province_id');
        s.innerHTML = '<option value=""></option>';
        areaProvinces.forEach(p => {
            const o = document.createElement('option');
            o.value = p.id;
            o.textContent = normalize(p.name);
            s.appendChild(o);
        });
        initSelect2Basic(s, 'انتخاب استان...');
    }

    function fillCitiesByProvinceId(provinceId) {
        const s = document.getElementById('city_id');
        s.innerHTML = '<option value=""></option>';
        const province = areaProvinces.find(p => Number(p.id) === Number(provinceId));
        const cities = province?.cities || [];
        cities.forEach(c => {
            const o = document.createElement('option');
            o.value = c.id;
            o.textContent = normalize(c.name);
            s.appendChild(o);
        });
        s.disabled = cities.length === 0;
        initSelect2Basic(s, 'انتخاب شهر...');
    }

    function fillShippingSelect() {
        const s = document.getElementById('shipping_id');
        s.innerHTML = '<option value="">انتخاب روش ارسال...</option>';
        shippings.forEach(sh => {
            const o = document.createElement('option');
            o.value = sh.id;
            o.textContent = sh.name;
            s.appendChild(o);
        });
    }

    function updateShippingMode() {
        const shippingSelect = document.getElementById('shipping_id');
        const ship = shippingById(shippingSelect.value);
        const inPerson = isInPersonShipping(ship);
        const price = ship ? Number(ship.price || 0) : 0;
        document.getElementById('shipping_price').value = String(price);
        document.getElementById('shipping_price_view').value = formatMoney(price);
        const provinceBox = document.getElementById('provinceBox');
        const cityBox = document.getElementById('cityBox');
        const provinceEl = document.getElementById('province_id');
        const cityEl = document.getElementById('city_id');
        const addressEl = document.getElementById('customer_address');
        const hintEl = document.getElementById('shipping_mode_hint');
        if (inPerson) {
            provinceBox.style.display = 'none';
            cityBox.style.display = 'none';
            provinceEl.value = '';
            cityEl.value = '';
            addressEl.value = '';
            provinceEl.disabled = true;
            cityEl.disabled = true;
            hintEl.textContent = 'مراجعه حضوری؛ آدرس لازم نیست.';
        } else {
            provinceBox.style.display = '';
            cityBox.style.display = '';
            provinceEl.disabled = false;
            cityEl.disabled = false;
            hintEl.textContent = price > 0 ? 'هزینه ارسال: ' + formatMoney(price) : 'مقصد و آدرس را تکمیل کنید.';
        }
        updateTotal();
        scheduleLocalDraftSave();
    }

    function applyCustomerToForm(c) {
        if (!c) return;
        const name = customerFullName(c);
        const mobile = normalize(c.mobile);
        document.getElementById('customer_id').value = c.id || '';
        document.getElementById('customer_name').value = name;
        document.getElementById('customer_mobile').value = mobile;
        document.getElementById('customer_address').value = c.address || '';
        document.getElementById('selectedCustomerTitle').textContent = name + (mobile ? ' - ' + mobile : '');
        document.getElementById('customer_balance_hint').textContent = 'مانده حساب: ' + formatMoney(c.balance || 0);
        document.getElementById('customerSummaryBox').classList.add('is-selected');
        if (c.province_id) {
            document.getElementById('province_id').value = String(c.province_id);
            if (window.jQuery) $('#province_id').trigger('change.select2');
            fillCitiesByProvinceId(c.province_id);
        }
        if (c.city_id) {
            document.getElementById('city_id').value = String(c.city_id);
            if (window.jQuery) $('#city_id').trigger('change.select2');
        }
        updateSubmitState();
        scheduleLocalDraftSave();
    }

    function clearCustomer() {
        document.getElementById('customer_id').value = '';
        document.getElementById('customer_name').value = '';
        document.getElementById('customer_mobile').value = '';
        document.getElementById('selectedCustomerTitle').textContent = 'هنوز مشتری انتخاب نشده است';
        document.getElementById('customer_balance_hint').textContent = '';
        document.getElementById('customerSummaryBox').classList.remove('is-selected');
        if (window.jQuery) $('#customer_search_select').val(null).trigger('change');
        updateSubmitState();
        scheduleLocalDraftSave();
    }

    function preloadCustomerOption(selectEl, customer) {
        if (!selectEl || !customer || !window.jQuery) return;
        const text = customerFullName(customer) + (customer.mobile ? ' - ' + customer.mobile : '');
        selectEl.add(new Option(text, customer.id, true, true));
        $(selectEl).trigger('change');
    }

    function initCustomerSearch() {
        const selectEl = document.getElementById('customer_search_select');
        if (!window.jQuery || !window.jQuery.fn?.select2) return;
        $(selectEl).select2({
            width: '100%',
            dir: 'rtl',
            placeholder: 'نام یا شماره موبایل مشتری...',
            allowClear: true,
            minimumInputLength: 1,
            ajax: {
                url: API.customers,
                dataType: 'json',
                delay: 250,
                data: params => ({
                    q: params.term || ''
                }),
                processResults: resp => {
                    const items = resp?.data?.customers || [];
                    return {
                        results: items.map(c => ({
                            id: c.id,
                            text: customerFullName(c) + ' - ' + (c.mobile || '')
                        }))
                    };
                }
            }
        });
        $(selectEl).on('select2:select', async function(e) {
            const id = e?.params?.data?.id;
            if (!id) return;
            try {
                const res = await fetch(API.customer + '/' + encodeURIComponent(id), {
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                const json = await res.json();
                const customer = json?.data?.customer || null;
                if (customer) applyCustomerToForm(customer);
            } catch (error) {}
        });
        $(selectEl).on('select2:clear', clearCustomer);
    }

    async function loadOldCustomer() {
        const cid = document.getElementById('customer_id').value || OLD_CUSTOMER_ID || '';
        if (!cid) return;
        try {
            const res = await fetch(API.customer + '/' + encodeURIComponent(cid), {
                headers: {
                    'Accept': 'application/json'
                }
            });
            const json = await res.json();
            const customer = json?.data?.customer || null;
            if (customer) {
                applyCustomerToForm(customer);
                preloadCustomerOption(document.getElementById('customer_search_select'), customer);
            }
        } catch (e) {}
    }

    function getRecentProducts() {
        return [];
    }

    function saveRecentProduct(product) {
        // حذف بخش «آخرین سرچ‌ها» برای خلوت‌تر شدن صفحه.
        return;
    }

    function renderRecentProducts() {
        document.getElementById('recentProductsWrap')?.remove();
    }

    async function findMotherProductByCode(autoOpen = false) {
        const input = document.getElementById('motherCodeInput');
        const code = toEnglishDigits(input.value).replace(/\D/g, '').slice(0, 4);
        input.value = code;
        if (code.length !== 4) {
            if (!autoOpen) {
                alert('کد مادر باید ۴ رقم باشد.');
                input.focus();
            }
            return;
        }
        const btn = document.getElementById('findMotherBtn');
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = '...';
        try {
            const rows = await searchProducts(code);
            selectedMotherProduct = rows.find(p => String(productCode(p)).trim() === code) || rows.find(p => String(p.code || '').trim() === code) || rows.find(p => String(p.sku || '').trim() === code) || rows[0] || null;
            if (!selectedMotherProduct) {
                document.getElementById('motherProductBox').style.display = 'none';
                setMotherSearchHintVisible(true);
                if (!autoOpen) {
                    alert('محصول مادری با این کد پیدا نشد.');
                    input.select();
                }
                return;
            }
            setMotherSearchHintVisible(false);
            document.getElementById('motherProductBox').style.display = 'block';
            document.getElementById('motherProductTitle').textContent = productTitle(selectedMotherProduct);
            document.getElementById('motherProductCode').textContent = 'کد: ' + (productCode(selectedMotherProduct) || code);
            saveRecentProduct(selectedMotherProduct);
            if (autoOpen) {
                await openGroupPicker(selectedMotherProduct.id);
            } else {
                setTimeout(() => document.getElementById('openGroupPickerBtn').focus(), 50);
            }
        } catch (e) {
            if (!autoOpen) alert('خطا در جستجو. دوباره تلاش کنید.');
        } finally {
            btn.disabled = false;
            btn.textContent = originalText;
        }
    }

    async function openGroupPicker(productId = null) {
        const targetId = productId || selectedMotherProduct?.id;
        if (!targetId) return;
        const modalEl = document.getElementById('groupPickerModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        activeProductId = Number(targetId);
        activeProduct = groupedSelections[activeProductId]?.product || selectedMotherProduct || null;
        document.getElementById('pickerLoading').classList.remove('d-none');
        document.getElementById('pickerTableWrap').classList.add('d-none');
        document.getElementById('groupPickerRows').innerHTML = '';
        document.getElementById('pickerSearchInput').value = '';
        modal.show();
        try {
            const product = await getProductDetails(activeProductId);
            if (!product) {
                alert('اطلاعات محصول دریافت نشد.');
                modal.hide();
                return;
            }
            activeProduct = product;
            activeModalItems = getProductVarieties(product);
            modalQuantities = new Map();
            const oldItems = groupedSelections[activeProductId]?.items || [];
            oldItems.forEach(item => modalQuantities.set(Number(item.variant_id), Number(item.quantity || 0)));
            modalGroupDiscountType = groupedSelections[activeProductId]?.discount_type || 'amount';
            modalGroupDiscountValue = Number(groupedSelections[activeProductId]?.discount_value || 0);
            document.getElementById('modalGroupDiscountType').value = modalGroupDiscountType;
            document.getElementById('modalGroupDiscountValue').value = discountValueForInput(modalGroupDiscountType, modalGroupDiscountValue);
            document.getElementById('pickerModalTitle').textContent = productTitle(product);
            document.getElementById('pickerModalSubTitle').textContent = 'کد: ' + (productCode(product) || '—') + ' | ' + formatNum(activeModalItems.length) + ' تنوع';
            saveRecentProduct(product);
            renderPickerRows();
            updateModalSummary();
            document.getElementById('pickerLoading').classList.add('d-none');
            document.getElementById('pickerTableWrap').classList.remove('d-none');
            setTimeout(() => document.getElementById('pickerSearchInput').focus(), 200);
        } catch (e) {
            alert('خطا در باز کردن لیست.');
            modal.hide();
        }
    }

    function filteredModalItems() {
        const q = normalize(document.getElementById('pickerSearchInput').value).toLowerCase();
        return activeModalItems.filter(v => {
            if (!q) return true;
            const haystack = [variantModel(v), variantDesign(v), variantName(v)].join(' ').toLowerCase();
            return haystack.includes(q);
        });
    }

    function modalMaxQty(v) {
        const id = variantId(v);
        const currentQty = Number(modalQuantities.get(id) || 0);
        return Math.max(variantStock(v), currentQty);
    }

    function variantSaleWarning(v, price, stock, qty) {
        if (v?.sale_warning) return String(v.sale_warning);
        if (Number(price || 0) <= 0) return 'قیمت فروش ثبت نشده';
        if (Number(stock || 0) <= 0 && Number(qty || 0) <= 0) return 'موجودی آزاد ندارد';
        return '';
    }

    function renderPickerRows() {
        const wrap = document.getElementById('groupPickerRows');
        const rows = filteredModalItems();
        if (!rows.length) {
            wrap.innerHTML = `<div class="empty-state">موردی برای نمایش وجود ندارد.</div>`;
            return;
        }
        wrap.innerHTML = rows.map(v => {
            const id = variantId(v);
            const stock = variantStock(v);
            const reserved = Number(v?.reserved ?? 0) || 0;
            const totalStock = Number(v?.stock ?? v?.quantity ?? 0) || 0;
            const max = modalMaxQty(v);
            const qty = Number(modalQuantities.get(id) || 0);
            const price = variantPrice(v, activeProduct);
            const warning = variantSaleWarning(v, price, stock, qty);
            const selectedClass = qty > 0 ? 'row-selected' : '';
            const noStockClass = warning && qty <= 0 ? 'row-empty-stock' : '';
            const disabled = warning && qty <= 0 ? 'disabled' : '';
            return `
        <div class="variant-row ${selectedClass} ${noStockClass}" data-row-variant="${id}">
            <div>
                <div class="variant-title">${esc(buildVariantTitle(v))}</div>
                <div class="variant-meta">
                    <span class="badge-soft ${stock > 0 ? 'badge-stock' : 'badge-no-stock'}">
                        موجودی قابل فروش: ${stock > 0 ? formatNum(stock) : 'ناموجود'}
                    </span>
                    <span class="badge-soft">پیش‌فاکتور شده: ${formatNum(reserved)}</span>
                    <span class="badge-soft">کل موجودی: ${formatNum(totalStock)}</span>
                    <span class="badge-soft">قیمت: ${formatMoney(price)}</span>
                    ${warning ? `<span class="badge-soft badge-no-stock">${esc(warning)}</span>` : ''}
                    ${v?.is_current_preinvoice_item ? `<span class="badge-soft badge-brand">در پیش‌فاکتور موجود است${stock <= 0 ? ' / موجودی فعلی ناکافی است' : ''}</span>` : ''}
                    ${qty > 0 ? `<span class="badge-soft badge-brand">انتخاب: ${formatNum(qty)}</span>` : ''}
                </div>
            </div>
            <div class="qty-control">
                <button type="button" class="qty-btn picker-minus" data-id="${id}" ${disabled}>−</button>
                <input type="tel" class="qty-input picker-qty" data-id="${id}" data-price="${price}" min="0" max="${max}" value="${qty}" inputmode="numeric" pattern="[0-9]*" autocomplete="off" ${disabled}>
                <button type="button" class="qty-btn picker-plus" data-id="${id}" data-step="1" ${disabled}>+</button>
            </div>
        </div>`;
        }).join('');
    }

    function setModalQty(id, value, shouldRender = false) {
        id = Number(id);
        const item = activeModalItems.find(v => variantId(v) === id);
        if (!item) return;
        const max = modalMaxQty(item);
        const stock = variantStock(item);
        const price = variantPrice(item, activeProduct);
        if (variantSaleWarning(item, price, stock, Number(modalQuantities.get(id) || 0)) && Number(modalQuantities.get(id) || 0) <= 0) {
            modalQuantities.set(id, 0);
            updateModalSummary();
            if (shouldRender) renderPickerRows();
            return;
        }
        const cleanedValue = toEnglishDigits(value).replace(/[^0-9]/g, '');
        let qty = parseInt(cleanedValue || '0', 10);
        if (!Number.isFinite(qty)) qty = 0;
        if (qty < 0) qty = 0;
        if (qty > max) qty = max;
        modalQuantities.set(id, qty);
        updateModalSummary();

        // روی تایپ مستقیم ردیف‌ها را دوباره رندر نمی‌کنیم؛ چون در موبایل باعث از دست رفتن فوکوس input می‌شد.
        if (shouldRender) {
            renderPickerRows();
            return;
        }

        const row = document.querySelector(`[data-row-variant="${id}"]`);
        if (row) row.classList.toggle('row-selected', qty > 0);
    }

    function changeModalQty(id, delta) {
        const current = Number(modalQuantities.get(Number(id)) || 0);
        setModalQty(id, current + Number(delta || 0), true);
    }

    function updateModalSummary() {
        let selectedRows = 0,
            totalQty = 0,
            totalAmount = 0;
        activeModalItems.forEach(v => {
            const id = variantId(v);
            const qty = Number(modalQuantities.get(id) || 0);
            const price = variantPrice(v, activeProduct);
            if (qty > 0) {
                selectedRows++;
                totalQty += qty;
                totalAmount += qty * price;
            }
        });
        modalGroupDiscountType = document.getElementById('modalGroupDiscountType')?.value || 'amount';
        modalGroupDiscountValue = normalizeDiscountInput(modalGroupDiscountType, document.getElementById('modalGroupDiscountValue')?.value || 0);
        const discount = calcDiscount(totalAmount, modalGroupDiscountType, modalGroupDiscountValue);
        document.getElementById('modalSelectedRows').textContent = formatNum(selectedRows);
        document.getElementById('modalTotalQty').textContent = formatNum(totalQty);
        document.getElementById('modalRawAmount').textContent = formatMoney(totalAmount);
        document.getElementById('modalTotalAmount').textContent = formatMoney(Math.max(0, totalAmount - discount));
        const preview = document.getElementById('modalGroupDiscountPreview');
        if (preview) preview.textContent = formatMoney(discount);
    }

    function clearPickerQuantities() {
        if (!confirm('همه تعدادهای انتخاب‌شده پاک شود؟')) return;
        modalQuantities = new Map();
        renderPickerRows();
        updateModalSummary();
    }

    async function saveGroupSelection() {
        if (!activeProductId || !activeProduct) return;
        const items = [];
        let blockedWarning = '';
        activeModalItems.forEach(v => {
            const id = variantId(v);
            const qty = Number(modalQuantities.get(id) || 0);
            const price = variantPrice(v, activeProduct);
            const stock = variantStock(v);
            const warning = variantSaleWarning(v, price, stock, qty);
            if (qty > 0 && warning) {
                blockedWarning = warning;
                return;
            }
            if (qty > 0) items.push({
                variant_id: id,
                quantity: qty,
                price,
                model: variantModel(v),
                design: variantDesign(v),
                variant: variantName(v),
                label: buildVariantTitle(v)
            });
        });
        if (blockedWarning) {
            alert(blockedWarning);
            return;
        }
        if (!items.length) {
            alert('حداقل یک کالا را انتخاب کنید.');
            return;
        }
        const discountType = document.getElementById('modalGroupDiscountType')?.value || 'amount';
        const discountValue = normalizeDiscountInput(discountType, document.getElementById('modalGroupDiscountValue')?.value || 0);
        const previousSelections = JSON.parse(JSON.stringify(groupedSelections || {}));
        groupedSelections[activeProductId] = {
            product: {
                id: activeProductId,
                title: productTitle(activeProduct),
                code: productCode(activeProduct)
            },
            items,
            discount_type: discountType,
            discount_value: discountValue
        };

        const saveBtn = document.getElementById('saveGroupSelectionBtn');
        const oldSaveText = saveBtn?.textContent || '';
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.textContent = 'در حال فریز...';
        }

        try {
            await syncDraftReservation(groupedSelections);
        } catch (e) {
            groupedSelections = previousSelections;
            alert(e.message || 'موجودی برای این انتخاب کافی نیست.');
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.textContent = oldSaveText;
            }
            return;
        } finally {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.textContent = oldSaveText;
            }
        }

        renderGroupSummary();
        updateTotal();
        scheduleLocalDraftSave();
        bootstrap.Modal.getInstance(document.getElementById('groupPickerModal'))?.hide();
        document.getElementById('motherCodeInput').value = '';
        document.getElementById('motherProductBox').style.display = 'none';
        setMotherSearchHintVisible(true);
        if (window.jQuery) $('#motherProductAjaxSelect').val(null).trigger('change');
        selectedMotherProduct = null;
        lastMotherAutoCode = '';
        setTimeout(() => document.getElementById('motherCodeInput').focus(), 100);
    }

    async function deleteGroup(productId) {
        const group = groupedSelections[productId];
        if (!group) return;
        if (!confirm(`محصول «${group.product.title}» حذف شود؟`)) return;
        const previousSelections = JSON.parse(JSON.stringify(groupedSelections || {}));
        delete groupedSelections[productId];
        try {
            await syncDraftReservation(groupedSelections);
        } catch (e) {
            groupedSelections = previousSelections;
            alert(e.message || 'خطا در آزادسازی موجودی محصول.');
            return;
        }
        renderGroupSummary();
        updateTotal();
        scheduleLocalDraftSave();
    }

    function toggleGroupDetails(productId) {
        const card = document.querySelector(`[data-group-card="${productId}"]`);
        if (!card) return;
        const isOpen = card.classList.toggle('is-open');
        const btn = card.querySelector('.group-main');
        if (btn) btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    function renderGroupSummary() {
        const wrap = document.getElementById('groupSummaryList');
        const inputWrap = document.getElementById('groupProductsInputs');
        wrap.innerHTML = '';
        inputWrap.innerHTML = '';
        const groups = Object.values(groupedSelections);
        const totalItems = groups.reduce((s, g) => s + g.items.reduce((ss, it) => ss + Number(it.quantity || 0), 0), 0);
        document.getElementById('orderItemsCountHint').textContent = formatNum(groups.length) + ' کالا | ' + formatNum(totalItems) + ' عدد';
        if (!groups.length) {
            wrap.innerHTML = `<div class="empty-state">هنوز کالایی اضافه نشده است.</div>`;
            updateSubmitState();
            return;
        }
        let idx = 0;
        groups.forEach(group => {
            const productId = Number(group.product.id);
            const qty = group.items.reduce((s, it) => s + Number(it.quantity || 0), 0);
            const rowsCount = group.items.length;
            const subtotal = groupRawSubtotal(group);
            const discount = groupDiscountTotal(group);
            const finalAmount = groupFinalAmount(group);
            const details = group.items.map(it => `
            <div class="detail-pill">
                <div class="fw-bold">${esc(it.label || 'تنوع پیش‌فرض')}</div>
                <div class="text-muted mt-1">تعداد: ${formatNum(it.quantity)} | مبلغ: ${formatMoney(Number(it.quantity) * Number(it.price))}</div>
            </div>`).join('');
            wrap.insertAdjacentHTML('beforeend', `
        <div class="group-card" data-group-card="${productId}">
            <button type="button" class="group-main" onclick="toggleGroupDetails(${productId})" aria-expanded="false">
                <div class="group-title" title="${esc(group.product.title)}">${esc(group.product.title)}</div>
                <div class="group-amount">${formatMoney(finalAmount)}</div>
                <div class="group-arrow">▼</div>
            </button>
            <div class="group-details">
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <span class="badge-soft">کد: ${esc(group.product.code || '—')}</span>
                    <span class="badge-soft">ردیف: ${formatNum(rowsCount)}</span>
                    <span class="badge-soft">تعداد: ${formatNum(qty)}</span>
                    ${discount > 0 ? `<span class="badge-soft badge-brand">تخفیف: ${formatMoney(discount)}</span>` : ''}
                </div>
                <div class="group-actions">
                    <button type="button" class="btn btn-sm btn-outline-primary rounded-3" onclick="openGroupPicker(${productId})">ویرایش</button>
                    <button type="button" class="btn btn-sm btn-outline-danger rounded-3" onclick="deleteGroup(${productId})">حذف</button>
                </div>
                <div class="mb-2 hint">خام: ${formatMoney(subtotal)}${discount > 0 ? ' | تخفیف: ' + formatMoney(discount) : ''}</div>
                <div class="details-grid">${details}</div>
            </div>
        </div>`);
            group.items.forEach(item => {
                inputWrap.insertAdjacentHTML('beforeend', `
                <input type="hidden" name="products[${idx}][item_id]" value="${Number(item.item_id || 0)}">
                <input type="hidden" name="products[${idx}][id]" value="${productId}">
                <input type="hidden" name="products[${idx}][variety_id]" value="${Number(item.variant_id)}">
                <input type="hidden" name="products[${idx}][quantity]" value="${Number(item.quantity)}">
                <input type="hidden" name="products[${idx}][price]" value="${Number(item.price)}">`);
                idx++;
            });
        });
        updateSubmitState();
    }

    function buildDiscountBreakdown(subtotal, groupDiscounts, orderDiscount, totalDiscount) {
        const groups = Object.values(groupedSelections).map(group => ({
            product_id: Number(group.product.id),
            product_title: group.product.title,
            discount_type: group.discount_type || 'amount',
            discount_value: Number(group.discount_value || 0),
            discount_amount: groupDiscountTotal(group),
            raw_subtotal: groupRawSubtotal(group),
            final_amount: groupFinalAmount(group)
        }));
        return {
            subtotal,
            group_discount_amount: groupDiscounts,
            order_discount_type: document.getElementById('orderDiscountType')?.value || 'amount',
            order_discount_value: Number(document.getElementById('orderDiscountValue')?.value || 0),
            order_discount_amount: orderDiscount,
            total_discount_amount: totalDiscount,
            groups
        };
    }

    function updateTotal() {
        const shipping = toInt(document.getElementById('shipping_price')?.value || 0);
        let subtotal = 0,
            groupDiscounts = 0;
        Object.values(groupedSelections).forEach(group => {
            subtotal += groupRawSubtotal(group);
            groupDiscounts += groupDiscountTotal(group);
        });
        const afterGroupDiscount = Math.max(0, subtotal - groupDiscounts);
        const orderType = document.getElementById('orderDiscountType')?.value || 'amount';
        const orderValue = normalizeDiscountInput(orderType, document.getElementById('orderDiscountValue')?.value || 0);
        const orderDiscount = calcDiscount(afterGroupDiscount, orderType, orderValue);
        const totalDiscount = Math.min(subtotal, groupDiscounts + orderDiscount);
        const total = Math.max(0, subtotal + shipping - totalDiscount);
        document.getElementById('discount').value = String(totalDiscount);
        document.getElementById('totalDiscountView').value = formatMoney(totalDiscount);
        document.getElementById('total_price').value = formatMoney(total);
        const preview = document.getElementById('orderDiscountPreview');
        if (preview) preview.textContent = 'تخفیف کلی: ' + formatMoney(orderDiscount);
        document.getElementById('discount_breakdown').value = JSON.stringify(buildDiscountBreakdown(subtotal, groupDiscounts, orderDiscount, totalDiscount));
        updateSubmitState();
        scheduleLocalDraftSave();
    }


    function initialProductDiscount(productId) {
        const products = INITIAL_DISCOUNT_BREAKDOWN?.products || {};
        const raw = products[String(productId)] || products[Number(productId)] || null;
        if (!raw) return { type: 'amount', value: 0 };
        return {
            type: raw.discount_type || raw.type || 'amount',
            value: Number(raw.discount_value ?? raw.value ?? 0)
        };
    }

    function hydrateInitialInvoiceDiscount() {
        const invoice = INITIAL_DISCOUNT_BREAKDOWN?.invoice || null;
        if (invoice) {
            document.getElementById('orderDiscountType').value = invoice.discount_type || invoice.type || 'amount';
            document.getElementById('orderDiscountValue').value = Number(invoice.discount_value ?? invoice.value ?? 0);
        } else if (HAS_LEGACY_DISCOUNT) {
            document.getElementById('orderDiscountType').value = 'amount';
            document.getElementById('orderDiscountValue').value = Number(OLD_DISCOUNT_AMOUNT || 0);
        }
    }

    async function hydrateInitialGroups() {
        if (!INIT_ROWS || !INIT_ROWS.length) {
            hydrateInitialInvoiceDiscount();
            renderGroupSummary();
            updateTotal();
            return;
        }
        const grouped = {};
        INIT_ROWS.forEach(row => {
            const productId = Number(row.id || row.product_id || 0);
            if (!productId) return;
            if (!grouped[productId]) grouped[productId] = [];
            grouped[productId].push(row);
        });
        for (const [productId, rows] of Object.entries(grouped)) {
            let product = null;
            try {
                product = await getProductDetails(productId);
            } catch (e) {}
            const varieties = getProductVarieties(product);
            groupedSelections[Number(productId)] = {
                product: {
                    id: Number(productId),
                    title: productTitle(product) || rows[0]?.product_name || ('محصول #' + productId),
                    code: productCode(product) || rows[0]?.product_code || ''
                },
                discount_type: initialProductDiscount(productId).type,
                discount_value: initialProductDiscount(productId).value,
                items: rows.map(row => {
                    const vid = Number(row.variety_id || row.variant_id || 0);
                    const v = varieties.find(item => variantId(item) === vid);
                    return {
                        variant_id: vid,
                        item_id: Number(row.item_id || row.id || 0),
                        quantity: Number(row.quantity || 0),
                        price: Number(row.price || (v ? variantPrice(v, product) : 0)),
                        model: v ? variantModel(v) : '—',
                        design: v ? variantDesign(v) : '—',
                        variant: v ? variantName(v) : (row.variant_name || '—'),
                        label: v ? buildVariantTitle(v) : (row.variant_name || 'تنوع پیش‌فرض')
                    };
                }).filter(item => item.variant_id && item.quantity > 0)
            };
        }
        hydrateInitialInvoiceDiscount();
        renderGroupSummary();
        updateTotal();
    }

    function updateSubmitState() {
        const btn = document.getElementById('submitOrderBtn');
        const hint = document.getElementById('submitHint');
        const customerName = normalize(document.getElementById('customer_name')?.value);
        const customerMobile = normalize(document.getElementById('customer_mobile')?.value);
        const hasProducts = document.querySelectorAll('#groupProductsInputs input[name$="[quantity]"]').length > 0;
        const shippingId = normalize(document.getElementById('shipping_id')?.value);
        const ok = !!customerName && !!customerMobile && hasProducts && !!shippingId;
        btn.disabled = !ok;
        if (ok) {
            hint.textContent = 'آماده ثبت.';
            hint.style.color = '#178c63';
        } else {
            hint.textContent = 'مشتری، روش ارسال و حداقل یک کالا لازم است.';
            hint.style.color = '';
        }
    }

    async function validateSelectedStockBeforeSubmit() {
        if (IS_EDIT) return true;
        const errors = [];
        for (const group of Object.values(groupedSelections)) {
            const product = await getProductDetails(group.product.id, true);
            const varieties = getProductVarieties(product);
            for (const item of group.items) {
                const v = varieties.find(row => Number(variantId(row)) === Number(item.variant_id));
                if (!v) {
                    errors.push(`${group.product.title}: تنوع ${item.variant_id} پیدا نشد.`);
                    continue;
                }
                const price = variantPrice(v, product);
                if (Number(price || 0) <= 0) {
                    errors.push(`${group.product.title} / ${buildVariantTitle(v)}: قیمت فروش ثبت نشده است.`);
                }
            }
        }
        if (errors.length) {
            alert('امکان ثبت پیش‌فاکتور وجود ندارد:\n\n' + errors.slice(0, 8).join('\n'));
            return false;
        }
        return true;
    }

    function normalizeBeforeSubmit() {
        const totalEl = document.getElementById('total_price');
        if (totalEl) totalEl.value = String(toInt(totalEl.value));
        const shipEl = document.getElementById('shipping_price');
        if (shipEl) shipEl.value = String(toInt(shipEl.value));
        const discEl = document.getElementById('discount');
        if (discEl) discEl.value = String(toInt(discEl.value));
        document.querySelectorAll('#groupProductsInputs input').forEach(input => {
            input.value = String(toInt(input.value));
        });
    }

    async function submitGuard(e) {
        if (isSubmittingProgrammatically) return true;
        e.preventDefault();
        const customerName = normalize(document.getElementById('customer_name').value);
        const customerMobile = normalize(document.getElementById('customer_mobile').value);
        const productInputs = document.querySelectorAll('#groupProductsInputs input[name$="[quantity]"]');
        if (!customerName || !customerMobile) {
            alert('لطفا مشتری را انتخاب کنید.');
            return false;
        }
        if (!document.getElementById('shipping_id').value) {
            alert('روش ارسال را انتخاب کنید.');
            return false;
        }
        if (!productInputs.length) {
            alert('حداقل یک کالا باید اضافه شود.');
            return false;
        }
        const btn = document.getElementById('submitOrderBtn');
        const oldText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'فریز نهایی موجودی...';
        try {
            await syncDraftReservation(groupedSelections);
        } catch (err) {
            alert(err.message || 'فریز موجودی کامل نشد.');
            btn.disabled = false;
            btn.textContent = oldText;
            return false;
        }
        btn.textContent = 'کنترل موجودی...';
        const stockOk = await validateSelectedStockBeforeSubmit();
        if (!stockOk) {
            btn.disabled = false;
            btn.textContent = oldText;
            return false;
        }
        normalizeBeforeSubmit();
        btn.textContent = 'در حال ثبت...';
        isSubmittingProgrammatically = true;
        if (!IS_EDIT) {
            localStorage.removeItem(LOCAL_DRAFT_KEY);
            localStorage.removeItem(RESERVATION_TOKEN_KEY);
        }
        hideLocalDraftBanner();
        document.getElementById('orderForm').submit();
        return true;
    }

    document.addEventListener('DOMContentLoaded', async function() {
        if (!IS_EDIT) {
            ensureReservationToken();
            bindLocalDraftEvents();
        }

        initSelect2Basic(document.getElementById('province_id'), 'انتخاب استان...');
        initSelect2Basic(document.getElementById('city_id'), 'انتخاب شهر...');

        await loadArea();
        fillProvincesSelect();

        if (OLD_PROVINCE_ID) {
            document.getElementById('province_id').value = String(OLD_PROVINCE_ID);
            if (window.jQuery) $('#province_id').trigger('change.select2');
            fillCitiesByProvinceId(OLD_PROVINCE_ID);
        }
        if (OLD_CITY_ID) {
            document.getElementById('city_id').value = String(OLD_CITY_ID);
            if (window.jQuery) $('#city_id').trigger('change.select2');
        }

        fillShippingSelect();
        if (OLD_SHIPPING_ID) document.getElementById('shipping_id').value = String(OLD_SHIPPING_ID);

        initCustomerSearch();
        initMotherAjaxSearch();
        await loadOldCustomer();
        renderRecentProducts();

        document.getElementById('clearCustomerBtn')?.addEventListener('click', clearCustomer);
        document.getElementById('province_id')?.addEventListener('change', function() {
            fillCitiesByProvinceId(this.value);
            scheduleLocalDraftSave();
        });
        document.getElementById('shipping_id')?.addEventListener('change', updateShippingMode);
        document.getElementById('orderDiscountType')?.addEventListener('change', updateTotal);
        document.getElementById('orderDiscountValue')?.addEventListener('input', updateTotal);
        document.getElementById('modalGroupDiscountType')?.addEventListener('change', updateModalSummary);
        document.getElementById('modalGroupDiscountValue')?.addEventListener('input', updateModalSummary);

        document.getElementById('motherCodeInput')?.addEventListener('input', function() {
            this.value = toEnglishDigits(this.value).replace(/\D/g, '').slice(0, 4);
            clearTimeout(motherAutoTimer);
            const code = this.value;
            if (code.length === 4 && code !== lastMotherAutoCode) {
                lastMotherAutoCode = code;
                motherAutoTimer = setTimeout(() => findMotherProductByCode(true), 350);
            }
        });
        document.getElementById('motherCodeInput')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                lastMotherAutoCode = '';
                findMotherProductByCode(true);
            }
        });
        document.getElementById('findMotherBtn')?.addEventListener('click', function() {
            lastMotherAutoCode = '';
            findMotherProductByCode(true);
        });
        document.getElementById('openGroupPickerBtn')?.addEventListener('click', () => openGroupPicker());
        document.getElementById('pickerSearchInput')?.addEventListener('input', renderPickerRows);

        document.getElementById('clearPickerQtyBtn')?.addEventListener('click', clearPickerQuantities);
        document.getElementById('saveGroupSelectionBtn')?.addEventListener('click', saveGroupSelection);

        document.getElementById('groupPickerRows')?.addEventListener('click', function(e) {
            const plus = e.target.closest('.picker-plus');
            const minus = e.target.closest('.picker-minus');
            const input = e.target.closest('.picker-qty');
            if (plus) {
                e.stopPropagation();
                changeModalQty(plus.dataset.id, Number(plus.dataset.step || 1));
                return;
            }
            if (minus) {
                e.stopPropagation();
                changeModalQty(minus.dataset.id, -1);
                return;
            }
            if (input) {
                e.stopPropagation();
                return;
            }
        });
        document.getElementById('groupPickerRows')?.addEventListener('input', function(e) {
            if (e.target.classList.contains('picker-qty')) {
                e.target.value = toEnglishDigits(e.target.value).replace(/[^0-9]/g, '');
                setModalQty(e.target.dataset.id, e.target.value, false);
            }
        });
        document.getElementById('groupPickerRows')?.addEventListener('focusout', function(e) {
            if (e.target.classList.contains('picker-qty')) renderPickerRows();
        });
        document.getElementById('orderForm')?.addEventListener('submit', submitGuard, {
            capture: true
        });

        await hydrateInitialGroups();
        updateShippingMode();
        updateSubmitState();

        isBootingPage = false;

        if (!IS_EDIT && !OLD_CUSTOMER_ID && !INIT_ROWS.length && localDraftExists()) {
            showLocalDraftBanner();
        } else {
            scheduleLocalDraftSave();
        }

        setTimeout(() => document.getElementById('motherCodeInput')?.focus(), 200);
    });
</script>
@endsection
