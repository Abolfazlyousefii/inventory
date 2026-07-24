@extends('layouts.app')

@section('content')
@php
$order = $order ?? null;
$customersPageUrl = $customersPageUrl ?? url('/customers');

$initRows = old('products');
$oldProductsPayload = old('products_payload');
if (!$initRows && is_string($oldProductsPayload) && trim($oldProductsPayload) !== '') {
    try {
        $decodedOldProductsPayload = json_decode($oldProductsPayload, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($decodedOldProductsPayload)) {
            $initRows = $decodedOldProductsPayload;
        }
    } catch (\Throwable $e) {
        // Keep DB/local autosave fallback intact when the old JSON payload is corrupt.
    }
}

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
'line_discount_amount' => (int) ($it->line_discount_amount ?? 0),
];
})->values();
}

if (!$initRows) { $initRows = []; }

$isEdit = (bool) ($isEdit ?? false);
$oldCustomerTitle = trim((string) old('customer_name', $order->customer_name ?? ''));
$oldCustomerMobile = trim((string) old('customer_mobile', $order->customer_mobile ?? ''));
$oldPaymentTermsNote = old('payment_terms_note', $order->payment_terms_note ?? '');
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
        background: #f5f6f8;
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
        background: var(--brand-dark);
    }

    .soft-card-lg {
        background: var(--card);
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
        background: var(--card);
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
        background: #f8fafc;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 10px 13px;
        min-height: 60px;
    }

    .customer-box.is-selected {
        background: #eef8f7;
        border-color: rgba(51, 199, 192, .35);
    }

    .quick-area {
        background: #f8fafc;
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
        background: var(--brand-dark);
        border: none;
        color: #fff;
        padding: 0 18px;
        white-space: nowrap;
    }

    .find-btn:hover {
        background: var(--brand-darker);
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
        background: var(--brand-darker);
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
            display: block;
            overflow-y: auto;
            max-height: calc(100dvh - 118px);
            min-height: 0;
            padding: 9px 9px 76px;
            -webkit-overflow-scrolling: touch;
        }

        .variant-list {
            min-height: 0;
            max-height: none;
            overflow: visible;
            padding: 5px;
        }

        .modal-discount-box {
            margin-top: 12px;
            padding: 8px 9px;
            position: static;
        }

        .modal-discount-box .discount-control {
            gap: 6px;
        }

        .modal-discount-box .discount-line {
            margin-top: 4px;
            font-size: .74rem;
        }

        .modal-summary-bar {
            margin-top: 10px !important;
            padding: 8px 9px;
            position: static;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 7px 10px;
        }

        .modal-footer {
            flex: 0 0 auto;
            position: sticky;
            bottom: 0;
            z-index: 20;
            background: linear-gradient(180deg, #f9f6ee, #f3eee5) !important;
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

        .modal-summary-bar .summary-stat {
            width: auto;
        }

        .modal-summary-bar .summary-stat .s-label {
            font-size: .68rem;
        }

        .modal-summary-bar .summary-stat .s-val {
            font-size: .84rem;
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

    /* Wholesale variant picker: one-scroll compact modal */
    .variant-modal-dialog {
        max-width: 900px;
    }

    .variant-modal-content {
        max-height: calc(100vh - 48px);
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .variant-modal__header,
    .variant-modal__search,
    .variant-modal__footer,
    .variant-modal__footer-extra {
        flex: 0 0 auto;
    }

    .variant-modal__search {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 10px;
        align-items: center;
        padding: 10px 14px;
        background: #fffdf9;
        border-bottom: 1px solid rgba(12, 83, 103, .08);
    }

    .variant-modal__body {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 10px 12px;
        background: linear-gradient(180deg, #fffefc, #faf6ef);
    }

    .variant-modal__footer-extra {
        padding: 8px 12px;
        background: #fffdf9;
        border-top: 1px solid rgba(12, 83, 103, .08);
    }

    .variant-modal__footer {
        gap: 8px;
    }

    .picker-search-wrap {
        position: relative;
        min-width: 0;
    }

    .picker-search-wrap .picker-search {
        padding-left: 34px;
    }

    .picker-search-clear {
        position: absolute;
        left: 6px;
        top: 50%;
        transform: translateY(-50%);
        width: 24px;
        height: 24px;
        border: 0;
        border-radius: 999px;
        background: #eef2f7;
        color: #64748b;
        font-weight: 900;
        line-height: 1;
    }

    .stock-toggle {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin: 0;
        white-space: nowrap;
        font-size: .78rem;
        font-weight: 800;
        color: var(--text-soft);
    }

    .variant-list {
        max-height: none;
        overflow: visible;
        border: 0;
        border-radius: 0;
        background: transparent;
        padding: 0;
    }

    .variant-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 150px;
        gap: 12px;
        align-items: center;
        padding: 9px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #fff;
        margin-bottom: 7px;
    }

    .variant-row__title {
        font-size: .84rem;
        font-weight: 900;
        color: #083344;
        line-height: 1.8;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .variant-row__meta {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 4px;
        font-size: .70rem;
        color: #64748b;
    }

    .variant-pill {
        padding: 2px 7px;
        border-radius: 999px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        white-space: nowrap;
        font-weight: 800;
    }

    .variant-pill--stock { background: #ecfdf5; color: #047857; border-color: #bbf7d0; }
    .variant-pill--selected { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
    .variant-pill--muted { opacity: .66; }

    .variant-row__qty {
        display: grid;
        grid-template-columns: 36px 1fr 36px;
        gap: 6px;
        align-items: center;
        direction: ltr;
    }

    .variant-row__qty button,
    .variant-row__qty input {
        width: 100%;
        height: 36px;
        border-radius: 10px;
    }

    .qty-btn:disabled,
    .qty-input:disabled {
        opacity: .45;
        cursor: not-allowed;
    }

    @media (max-width: 575.98px) {
        .variant-modal-dialog {
            margin: 0;
            max-width: none;
            width: 100%;
            height: 100dvh;
        }

        .variant-modal-content {
            height: 100dvh;
            max-height: 100dvh;
            border-radius: 0;
        }

        .variant-modal__search {
            grid-template-columns: 1fr;
            gap: 7px;
            padding: 8px 10px;
        }

        .variant-modal__body {
            padding: 8px 9px;
            max-height: none;
        }

        .variant-row {
            display: block;
            padding: 10px;
        }

        .variant-row__title {
            white-space: normal;
            font-size: .82rem;
            line-height: 1.8;
        }

        .variant-row__meta {
            gap: 5px;
            margin-top: 6px;
        }

        .variant-row__qty {
            margin-top: 8px;
            grid-template-columns: 42px 1fr 42px;
        }
    }

</style>

<div class="container page-shell py-3">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h1 class="page-title">{{ $isEdit ? 'ویرایش پیش‌فاکتور' : 'ثبت پیش‌فاکتور' }}</h1>
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
    @if($errors->any())
    <div class="alert alert-danger border-0 shadow-sm rounded-4 py-2" id="topStockErrorSummary">
        <div class="fw-bold mb-1">⚠️ خطا:</div>
        @if(session('preinvoice_item_errors'))
            <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <span>{{ count(session('preinvoice_item_errors', [])) }} قلم نیاز به اصلاح موجودی دارند.</span>
                <label class="form-check small mb-0"><input class="form-check-input" type="checkbox" id="showOnlyStockErrors"> نمایش فقط اقلام دارای خطا</label>
            </div>
        @else
            <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        @endif
    </div>
    @endif

    <form action="{{ $isEdit ? route('preinvoice.draft.update', $order->uuid) : route('preinvoice.draft.save') }}" method="POST" id="orderForm" autocomplete="off">
        @csrf
        @if($isEdit) @method('PUT') @endif

        <input type="hidden" name="customer_id" id="customer_id" value="{{ old('customer_id', $order->customer_id ?? '') }}">
        <input type="hidden" name="customer_name" id="customer_name" value="{{ old('customer_name', $order->customer_name ?? '') }}">
        <input type="hidden" name="customer_mobile" id="customer_mobile" value="{{ old('customer_mobile', $order->customer_mobile ?? '') }}">
        <input type="hidden" name="payment_status" value="pending">
        <input type="hidden" name="reservation_token" id="reservation_token" value="{{ old('reservation_token') }}">
        <input type="hidden" name="autosave_uuid" id="autosave_uuid" value="">
        <input type="hidden" name="discount_breakdown" id="discount_breakdown" value="">
        <input type="hidden" name="products_payload" id="products_payload" value="">
        <input type="hidden" name="products_payload_count" id="products_payload_count" value="0">
        <input type="hidden" name="products_payload_version" id="products_payload_version" value="1">
        <input type="hidden" name="products_payload_complete" id="products_payload_complete" value="0">
        <input type="hidden" name="products_payload_total_quantity" id="products_payload_total_quantity" value="0">
        <input type="hidden" name="products_payload_gross_total" id="products_payload_gross_total" value="0">


        <div class="soft-card compact-card mb-3">
            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-2">
                <div>
                    <h2 class="section-title mb-1">نوع ثبت</h2>
                    <div class="hint" id="reservationModeHint">رزرو موقت کالاها ۱ ساعت اعتبار دارد. بعد از ثبت نهایی، زمان رزرو طبق سطح مشتری شروع می‌شود.</div>
                </div>
            </div>
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="border rounded-4 p-3 d-flex gap-2 h-100" style="cursor:pointer">
                        <input class="form-check-input mt-1" type="radio" name="is_in_person" value="0" @checked(! (bool) old('is_in_person', $order->is_in_person ?? false))>
                        <span>
                            <span class="fw-bold d-block">آنلاین / غیرحضوری</span>
                            <span class="hint">رزرو موقت هنگام انتخاب کالا تا ۱ ساعت فعال است.</span>
                        </span>
                    </label>
                </div>
                <div class="col-md-6">
                    <label class="border rounded-4 p-3 d-flex gap-2 h-100" style="cursor:pointer">
                        <input class="form-check-input mt-1" type="radio" name="is_in_person" value="1" @checked((bool) old('is_in_person', $order->is_in_person ?? false))>
                        <span>
                            <span class="fw-bold d-block">حضوری</span>
                            <span class="hint">رزرو موقت تا زمان ثبت نهایی فعال می‌ماند.</span>
                        </span>
                    </label>
                </div>
            </div>
        </div>

        <div class="soft-card compact-card mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-lg-5">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <h2 class="section-title">مشتری</h2>
                            <div class="hint">جستجو با نام یا موبایل</div>
                        </div>
                        <a href="{{ $customersPageUrl }}" class="btn btn-sm btn-outline-success rounded-3">افزودن</a>
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
                                <div class="hint mt-1" id="customer_reservation_hint">سطح رزرو پس از انتخاب مشتری نمایش داده می‌شود.</div>
                            </div>
                            <button type="button" id="clearCustomerBtn" class="btn btn-sm btn-light border rounded-3">تغییر</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="soft-card compact-card mb-3">
            <h2 class="section-title mb-1">شرایط پرداخت پیشنهادی</h2>
            <div class="hint mb-2">مثلاً: ۲۰٪ نقدی، ۸۰٪ چک سه‌ماهه در سه فقره</div>
            <textarea id="payment_terms_note" name="payment_terms_note" class="form-control form-control-sm" rows="2" placeholder="مثلاً ۲۰٪ نقدی، ۸۰٪ چک سه‌ماهه در سه فقره...">{{ $oldPaymentTermsNote }}</textarea>
        </div>

        <div class="soft-card-lg product-focus mb-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h2 class="section-title">کالاها</h2>
                    <div class="hint mt-1">کد ۴ رقمی محصول مادر را وارد کنید</div>
                </div>

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
            <input type="hidden" name="discount_amount" id="discount" value="{{ old('invoice_discount_value', $order->invoice_discount_value ?? data_get($order?->discount_breakdown, 'order_discount_value', 0)) }}">
            <div class="final-grid">
                <div>
                    <label class="label-sm">تخفیف کلی</label>
                    <div class="discount-control">
                        <select id="orderDiscountType" class="form-select form-select-sm">
                            <option value="amount" @selected(old('invoice_discount_type', $order->invoice_discount_type ?? data_get($order?->discount_breakdown, 'order_discount_type', 'amount')) === 'amount')>ریال</option>
                            <option value="percent" @selected(old('invoice_discount_type', $order->invoice_discount_type ?? data_get($order?->discount_breakdown, 'order_discount_type', 'amount')) === 'percent')>درصد</option>
                        </select>
                        <input type="number" id="orderDiscountValue" class="form-control form-control-sm" min="0" step="0.01" inputmode="decimal" value="{{ old('invoice_discount_value', $order->invoice_discount_value ?? data_get($order?->discount_breakdown, 'order_discount_value', 0)) }}" placeholder="مقدار">
                    </div>
                    <div class="discount-line" id="orderDiscountPreview">تخفیف کلی: 0 ریال</div>
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
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-primary px-4 py-2 rounded-3 fw-bold" id="saveDraftBtn" name="intent" value="draft" disabled>ذخیره پیش‌نویس</button>
                        <button class="btn btn-primary px-4 py-2 rounded-3 fw-bold" id="submitOrderBtn" name="intent" value="submit" disabled>ثبت نهایی و ارسال به مالی</button>
                    </div>
                    <div class="hint mt-2">بدون رزرو موجودی و بدون ارسال به مالی</div>
                    <div class="hint">بررسی موجودی، رزرو رسمی و ارسال به مالی</div>
                    <div class="submit-disabled-hint" id="submitHint">برای ثبت، مشتری و حداقل یک کالا لازم است.</div>
                </div>
            </div>
        </div>
    </form>
</div>

<div class="modal fade" id="groupPickerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl variant-modal-dialog">
        <div class="modal-content variant-modal-content">
            <div class="modal-header picker-head variant-modal__header">
                <div>
                    <h5 class="modal-title fw-bold" id="pickerModalTitle">انتخاب تنوع</h5>
                    <div class="hint mt-1" id="pickerModalSubTitle">—</div>
                </div>
                <button type="button" class="btn-close m-0" data-bs-dismiss="modal"></button>
            </div>

            <div class="variant-modal__search">
                <div class="picker-search-wrap">
                    <input type="text" id="pickerSearchInput" class="picker-search" placeholder="جستجو در تنوع‌ها...">
                    <button type="button" id="clearPickerSearchBtn" class="picker-search-clear" aria-label="پاک کردن جستجو">×</button>
                </div>
                <label class="stock-toggle" id="onlyInStockWrap">
                    <input type="checkbox" id="onlyInStockToggle">
                    <span>فقط موجودها</span>
                </label>
            </div>

            <div class="modal-body variant-modal__body">
                <div id="pickerLoading" class="empty-state d-none">در حال دریافت کالاها...</div>

                <div class="variant-list" id="pickerTableWrap">
                    <div id="groupPickerRows"></div>
                </div>
            </div>

            <div class="variant-modal__footer-extra">
                <div class="modal-discount-box">
                    <label class="label-sm">تخفیف محصول</label>
                    <div class="discount-control">
                        <select id="modalGroupDiscountType" class="form-select form-select-sm">
                            <option value="amount">ریال</option>
                            <option value="percent">درصد</option>
                        </select>
                        <input type="number" id="modalGroupDiscountValue" class="form-control form-control-sm" min="0" step="0.01" inputmode="decimal" value="0" placeholder="مقدار تخفیف">
                    </div>
                    <div class="discount-line">تخفیف: <strong id="modalGroupDiscountPreview">0 ریال</strong></div>
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

            <div class="modal-footer variant-modal__footer" style="background:linear-gradient(180deg,#f9f6ee,#f3eee5);border-top:1px solid rgba(12,83,103,.08);">
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
            autosave: @json(route('preinvoice.autosave')),
            autosaveLatest: @json(route('preinvoice.autosave.latest')),
            autosaveDiscardBase: @json(url('/preinvoice/autosave')),
            reservationsHeartbeat: @json(route('preinvoice.reservations.heartbeat')),
            reservationsReleaseToken: @json(route('preinvoice.reservations.release-token')),
            area: @json(url('/preinvoice/api/area')),
            customers: @json(url('/preinvoice/api/customers')),
            customer: @json(url('/preinvoice/api/customers'))
        },
        initRows: @json($initRows),
        oldCustomerId: @json(old('customer_id', $order->customer_id ?? '')),
        oldCustomerName: @json(old('customer_name', $order->customer_name ?? '')),
        oldCustomerMobile: @json(old('customer_mobile', $order->customer_mobile ?? '')),
        oldPaymentTermsNote: @json($oldPaymentTermsNote),
        oldDiscountAmount: @json(old('discount_amount', ($order->invoice_discount_amount ?? $order->discount_amount ?? 0))),
        oldInvoiceDiscountType: @json(old('invoice_discount_type', $order->invoice_discount_type ?? data_get($order?->discount_breakdown, 'order_discount_type', 'amount'))),
        oldInvoiceDiscountValue: @json(old('invoice_discount_value', $order->invoice_discount_value ?? data_get($order?->discount_breakdown, 'order_discount_value', 0))),
        oldDiscountBreakdown: @json(old('discount_breakdown', $order->discount_breakdown ?? null)),
        isEdit: @json($isEdit),
        orderUuid: @json($order->uuid ?? null)
    };

    const API = window.PREINVOICE_BOOT.api;
    const INIT_ROWS = window.PREINVOICE_BOOT.initRows || [];
    const OLD_CUSTOMER_ID = window.PREINVOICE_BOOT.oldCustomerId;
    const OLD_CUSTOMER_NAME = window.PREINVOICE_BOOT.oldCustomerName;
    const OLD_CUSTOMER_MOBILE = window.PREINVOICE_BOOT.oldCustomerMobile;
    const OLD_DISCOUNT_AMOUNT = window.PREINVOICE_BOOT.oldDiscountAmount;
    const OLD_INVOICE_DISCOUNT_TYPE = window.PREINVOICE_BOOT.oldInvoiceDiscountType || 'amount';
    const OLD_INVOICE_DISCOUNT_VALUE = Number(window.PREINVOICE_BOOT.oldInvoiceDiscountValue || 0);
    const OLD_DISCOUNT_BREAKDOWN = window.PREINVOICE_BOOT.oldDiscountBreakdown || {};
    const IS_EDIT = !!window.PREINVOICE_BOOT.isEdit;
    const EDIT_ORDER_UUID = window.PREINVOICE_BOOT.orderUuid || null;
    const SUBMIT_SUCCEEDED = @json(session()->pull('preinvoice_submit_succeeded', false));

    const productCache = new Map();

    let selectedMotherProduct = null;
    let activeProductId = null;
    let activeProduct = null;
    let activeModalItems = [];
    let modalQuantities = new Map();
    let modalGroupDiscountType = 'amount';
    let modalGroupDiscountValue = 0;
    let modalOnlyInStock = false;

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

    const SERVER_ITEM_ERRORS = @json(session('preinvoice_item_errors', []));

    function applyServerItemErrors() {
        if (!Array.isArray(SERVER_ITEM_ERRORS) || !SERVER_ITEM_ERRORS.length) return;
        const first = SERVER_ITEM_ERRORS[0];
        SERVER_ITEM_ERRORS.forEach(error => {
            const variantId = Number(error.variant_id || 0);
            if (!variantId) return;
            document.querySelectorAll(`[data-variant-pill="${variantId}"]`).forEach(pill => {
                pill.classList.add('border', 'border-danger', 'bg-danger-subtle');
                const msg = pill.querySelector('[data-stock-row-error]');
                if (msg) {
                    msg.textContent = error.message || 'موجودی آزاد این تنوع کافی نیست.';
                    msg.classList.remove('d-none');
                }
            });
        });
        const toggle = document.getElementById('showOnlyStockErrors');
        if (toggle) {
            toggle.addEventListener('change', () => {
                const errorVariants = new Set(SERVER_ITEM_ERRORS.map(e => String(Number(e.variant_id || 0))));
                document.querySelectorAll('[data-variant-pill]').forEach(pill => {
                    pill.classList.toggle('d-none', toggle.checked && !errorVariants.has(String(Number(pill.dataset.variantPill || 0))));
                });
                document.querySelectorAll('[data-group-card]').forEach(card => {
                    const hasVisible = !!card.querySelector('[data-variant-pill]:not(.d-none)');
                    card.classList.toggle('d-none', toggle.checked && !hasVisible);
                });
            });
        }
        const firstPill = document.querySelector(`[data-variant-pill="${Number(first.variant_id || 0)}"]`);
        if (firstPill) {
            firstPill.closest('[data-group-card]')?.classList.add('is-open');
            firstPill.scrollIntoView({behavior: 'smooth', block: 'center'});
            firstPill.setAttribute('tabindex', '-1');
            firstPill.focus({preventScroll: true});
        }
    }

    const BROWSER_SESSION_KEY = 'aria_preinvoice_browser_session_v1';
    let isSyncingReservation = false;
    let currentAutosaveUuid = null;
    let autosaveTimer = null;
    let autosaveDirty = false;
    let heartbeatTimer = null;


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
        return token;
    }

    function browserSessionId() {
        let id = normalize(sessionStorage.getItem(BROWSER_SESSION_KEY));
        if (!id) {
            id = (crypto?.randomUUID ? crypto.randomUUID() : cryptoRandomUuidFallback());
            sessionStorage.setItem(BROWSER_SESSION_KEY, id);
        }
        return id;
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

    function currentIsInPerson() {
        return document.querySelector('input[name="is_in_person"]:checked')?.value === '1';
    }

    function setReservationMode(isInPerson) {
        const value = isInPerson ? '1' : '0';
        const input = document.querySelector(`input[name="is_in_person"][value="${value}"]`);
        if (input) input.checked = true;
        updateReservationModeHint();
    }

    function updateReservationModeHint() {
        const hint = document.getElementById('reservationModeHint');
        if (!hint) return;
        hint.textContent = currentIsInPerson()
            ? 'برای فروش حضوری، رزرو موقت تا ثبت نهایی فعال می‌ماند. بعد از ثبت نهایی، زمان رزرو طبق سطح مشتری شروع می‌شود.'
            : 'رزرو موقت کالاها ۱ ساعت اعتبار دارد. بعد از ثبت نهایی، زمان رزرو طبق سطح مشتری شروع می‌شود.';
    }

    async function syncDraftReservation(sourceGroups = groupedSelections) {
        if (IS_EDIT) return { ok: true };
        const token = ensureReservationToken();
        isSyncingReservation = true;
        try {
            const response = await postReservation(API.reservationsSync, {
                reservation_token: token,
                is_in_person: currentIsInPerson(),
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
        productCache.clear();
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

    function variantAvailability(v) {
        const freeStock = Math.max(0, Number(v?.free_stock ?? v?.stock ?? v?.quantity ?? 0) || 0);
        const currentTokenReserved = Math.max(0, Number(v?.current_token_reserved ?? 0) || 0);
        const maxSelectable = Math.max(0, Number(v?.max_selectable_for_current_form ?? v?.sellable_stock ?? (freeStock + currentTokenReserved)) || 0);
        const totalReserved = Math.max(0, Number(v?.total_reserved_including_current ?? v?.reserved ?? 0) || 0);
        const reservedByOthers = Math.max(0, Number(v?.reserved_by_others ?? Math.max(0, totalReserved - currentTokenReserved)) || 0);
        const totalStock = Math.max(0, Number(v?.total_stock ?? (freeStock + totalReserved)) || 0);

        return {
            freeStock,
            currentTokenReserved,
            maxSelectable,
            reservedByOthers,
            totalReserved,
            totalStock,
        };
    }

    function variantStock(v) {
        return variantAvailability(v).maxSelectable;
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
            normalize(document.getElementById('payment_terms_note')?.value) ||
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
            is_in_person: currentIsInPerson(),
            customer: {
                id: document.getElementById('customer_id')?.value || '',
                name: document.getElementById('customer_name')?.value || '',
                mobile: document.getElementById('customer_mobile')?.value || '',
                title: document.getElementById('selectedCustomerTitle')?.textContent || '',
                balance_hint: document.getElementById('customer_balance_hint')?.textContent || '',
                reservation_hint: document.getElementById('customer_reservation_hint')?.textContent || ''
            },
            payment_terms_note: document.getElementById('payment_terms_note')?.value || '',
            discount: {
                type: document.getElementById('orderDiscountType')?.value || 'amount',
                value: document.getElementById('orderDiscountValue')?.value || 0
            },
            groupedSelections: groupedSelections || {}
        };
    }

    function collectProductsForAutosave() {
        const rows = [];
        Object.values(groupedSelections || {}).forEach(group => {
            const productId = Number(group.product?.id || 0);
            (group.items || []).forEach(item => {
                rows.push({
                    id: productId,
                    variety_id: Number(item.variant_id || 0),
                    quantity: Number(item.quantity || 0),
                    price: Number(item.price || 0),
                    line_discount_amount: Number(item.line_discount_amount || 0)
                });
            });
        });
        return rows.filter(row => row.id > 0 && row.variety_id > 0 && row.quantity > 0);
    }

    async function saveDbAutosaveNow() {
        if (IS_EDIT || isBootingPage || isHydratingLocalDraft || isSubmittingProgrammatically || !hasAnyFormData()) return;
        autosaveDirty = false;
        updateLocalDraftStatus('در حال ذخیره...', false);
        const res = await fetch(API.autosave, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({
                draft_uuid: currentAutosaveUuid,
                reservation_token: ensureReservationToken(),
                customer_id: document.getElementById('customer_id')?.value || null,
                customer_name: document.getElementById('customer_name')?.value || '',
                customer_mobile: document.getElementById('customer_mobile')?.value || '',
                payment_terms_note: document.getElementById('payment_terms_note')?.value || '',
                is_in_person: currentIsInPerson() ? 1 : 0,
                discount_amount: toInt(document.getElementById('discount')?.value || 0),
                invoice_discount_type: document.getElementById('orderDiscountType')?.value || 'amount',
                invoice_discount_value: Number(document.getElementById('orderDiscountValue')?.value || 0),
                discount_breakdown: document.getElementById('discount_breakdown')?.value || '',
                products: collectProductsForAutosave()
            })
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok || json?.ok === false) throw new Error(json?.message || 'خطا در ذخیره خودکار');
        currentAutosaveUuid = json.uuid || currentAutosaveUuid;
        const autosaveInput = document.getElementById('autosave_uuid');
        if (autosaveInput) autosaveInput.value = currentAutosaveUuid || '';
        const date = json.saved_at ? new Date(json.saved_at) : new Date();
        updateLocalDraftStatus('ذخیره شد در ' + date.toLocaleTimeString('fa-IR', {hour: '2-digit', minute: '2-digit'}), true);
    }

    function scheduleDbAutosave(delay = 1500) {
        if (IS_EDIT || isSubmittingProgrammatically) return;
        autosaveDirty = true;
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(() => {
            saveDbAutosaveNow().catch(() => updateLocalDraftStatus('خطا در ذخیره خودکار', false));
        }, delay);
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
            scheduleDbAutosave();
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
        const paymentTermsEl = document.getElementById('payment_terms_note');
        if (paymentTermsEl) paymentTermsEl.value = '';
        document.getElementById('selectedCustomerTitle').textContent = 'هنوز مشتری انتخاب نشده است';
        document.getElementById('customer_balance_hint').textContent = '';
        document.getElementById('customer_reservation_hint').textContent = 'سطح رزرو پس از انتخاب مشتری نمایش داده می‌شود.';
        document.getElementById('customerSummaryBox').classList.remove('is-selected');
        setReservationMode(false);
        if (window.jQuery) $('#customer_search_select').val(null).trigger('change');


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
        document.getElementById('customer_reservation_hint').textContent = draft.customer?.reservation_hint || 'سطح رزرو پس از انتخاب مشتری نمایش داده می‌شود.';

        if (draft.customer?.id && window.jQuery) {
            const optionText = displayTitle || draft.customer.id;
            const selectEl = document.getElementById('customer_search_select');
            selectEl.add(new Option(optionText, draft.customer.id, true, true));
            $('#customer_search_select').trigger('change');
        }
        const paymentTermsEl = document.getElementById('payment_terms_note');
        if (paymentTermsEl) paymentTermsEl.value = draft.payment_terms_note || '';
        document.getElementById('orderDiscountType').value = draft.discount?.type || 'amount';
        document.getElementById('orderDiscountValue').value = draft.discount?.value || 0;
        setReservationMode(Boolean(draft.is_in_person));
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

    async function applyDbAutosaveDraft(draft) {
        if (!draft) return;
        isHydratingLocalDraft = true;
        currentAutosaveUuid = draft.uuid || null;
        const autosaveInput = document.getElementById('autosave_uuid');
        if (autosaveInput) autosaveInput.value = currentAutosaveUuid || '';
        clearVisibleFormOnly();
        document.getElementById('customer_id').value = draft.customer?.id || '';
        document.getElementById('customer_name').value = draft.customer?.name || '';
        document.getElementById('customer_mobile').value = draft.customer?.mobile || '';
        document.getElementById('payment_terms_note').value = draft.payment_terms_note || '';
        document.getElementById('orderDiscountType').value = draft.discount?.type || 'amount';
        document.getElementById('orderDiscountValue').value = draft.discount?.value || 0;
        setReservationMode(Boolean(draft.is_in_person));
        groupedSelections = {};
        for (const row of (draft.items || [])) {
            const productId = Number(row.product_id || 0);
            const draftVariantId = Number(row.variant_id || 0);
            if (!productId || !draftVariantId) continue;
            let product = null;
            try { product = await getProductDetails(productId, true); } catch (e) {}
            const varieties = getProductVarieties(product);
            const v = varieties.find(item => draftVariantId === variantId(item));
            if (!groupedSelections[productId]) {
                groupedSelections[productId] = {
                    product: { id: productId, title: productTitle(product) || row.product?.title || ('محصول #' + productId), code: productCode(product) || row.product?.sku || '' },
                    discount_type: 'amount',
                    discount_value: 0,
                    items: []
                };
            }
            const warning = row.stock_warning ? ' — موجودی فعلی کافی نیست' : '';
            groupedSelections[productId].items.push({
                variant_id: draftVariantId,
                quantity: Number(row.quantity || 0),
                price: Number(row.price || (v ? variantPrice(v, product) : 0)),
                model: v ? variantModel(v) : '—',
                design: v ? variantDesign(v) : '—',
                variant: v ? variantName(v) : '—',
                label: (v ? buildVariantTitle(v) : 'تنوع پیش‌فرض') + warning
            });
        }
        renderGroupSummary();
        updateTotal();
        updateSubmitState();
        updateLocalDraftStatus('پیش‌نویس بدون رزرو موجودی بازیابی شد', true);
        isHydratingLocalDraft = false;
        scheduleDbAutosave();
    }

    async function loadLatestDbAutosaveBanner() {
        if (IS_EDIT) return;
        try {
            const res = await fetch(API.autosaveLatest, {headers: {'Accept': 'application/json'}});
            const json = await res.json();
            if (!json?.draft) return;
            const banner = document.getElementById('localDraftBanner');
            document.getElementById('localDraftBannerText').textContent = 'یک پیش‌نویس ذخیره‌شده پیدا شد. این پیش‌نویس بدون رزرو موجودی بازیابی می‌شود و موجودی کالاها هنگام ادامه کار دوباره بررسی خواهد شد.';
            banner.classList.add('is-visible');
            document.getElementById('loadLocalDraftBtn').onclick = () => applyDbAutosaveDraft(json.draft);
            document.getElementById('discardLocalDraftBtn').onclick = async () => {
                if (!confirm('پیش‌نویس ذخیره‌شده حذف شود؟')) return;
                await fetch(API.autosaveDiscardBase + '/' + encodeURIComponent(json.draft.uuid) + '/discard', {
                    method: 'POST',
                    headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''}
                });
                banner.classList.remove('is-visible');
            };
        } catch (e) {}
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
            updateTotal();
            updateSubmitState();
            isHydratingLocalDraft = false;
            await removeLocalDraft(true, false);
        });

        document.querySelectorAll('input[name="is_in_person"]').forEach(input => {
            input.addEventListener('change', async function() {
                updateReservationModeHint();
                scheduleLocalDraftSave();
                if (!Object.keys(groupedSelections || {}).length) return;
                try {
                    await syncDraftReservation(groupedSelections);
                } catch (e) {
                    alert(e.message || 'رزرو موقت این فرم منقضی شده است. موجودی دوباره بررسی شد؛ لطفاً انتخاب کالا را تایید کنید.');
                }
            });
        });

        ['payment_terms_note', 'orderDiscountType', 'orderDiscountValue'].forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('change', scheduleLocalDraftSave);
            el.addEventListener('input', scheduleLocalDraftSave);
        });

        window.addEventListener('beforeunload', function() {
            saveLocalDraftNow();
        });
        window.addEventListener('pagehide', releaseTokenWithBeacon);
        window.addEventListener('beforeunload', releaseTokenWithBeacon);
        startReservationHeartbeat();
        setInterval(() => {
            if (autosaveDirty) saveDbAutosaveNow().catch(() => updateLocalDraftStatus('خطا در ذخیره خودکار', false));
        }, 30000);
    }

    function releaseTokenWithBeacon() {
        if (isSubmittingProgrammatically) return;
        const token = normalize(document.getElementById('reservation_token')?.value) || normalize(localStorage.getItem(RESERVATION_TOKEN_KEY));
        if (!token || !navigator.sendBeacon) return;
        const payload = new FormData();
        payload.append('token', token);
        payload.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
        navigator.sendBeacon(API.reservationsReleaseToken, payload);
    }

    function startReservationHeartbeat() {
        const beat = async () => {
            const token = normalize(document.getElementById('reservation_token')?.value) || normalize(localStorage.getItem(RESERVATION_TOKEN_KEY));
            if (!token) return;
            try {
                await postReservation(API.reservationsHeartbeat, {token, browser_session_id: browserSessionId()});
            } catch (e) {
                console.warn('reservation heartbeat failed', e);
            }
        };
        beat();
        heartbeatTimer = setInterval(beat, 30000);
    }

    updateReservationModeHint();

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
        const res = await fetch(API.products + '?q=' + encodeURIComponent(query), {
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


    function reservationTierLabel(c) {
        return normalize(c?.reservation_tier_label) || 'معمولی پیش‌فرض';
    }

    function reservationDurationShort(c) {
        const full = normalize(c?.reservation_duration_label) || 'رزرو ۳ ساعته';
        if (full.includes('بدون محدودیت')) return 'بدون محدودیت زمانی';
        if (full.includes('۱') || full.includes('1')) return '۱ ساعت';
        return '۳ ساعت';
    }

    function applyCustomerToForm(c) {
        if (!c) return;
        const name = customerFullName(c);
        const mobile = normalize(c.mobile);
        document.getElementById('customer_id').value = c.id || '';
        document.getElementById('customer_name').value = name;
        document.getElementById('customer_mobile').value = mobile;
        document.getElementById('selectedCustomerTitle').textContent = name + (mobile ? ' - ' + mobile : '');
        document.getElementById('customer_balance_hint').textContent = 'مانده حساب: ' + formatMoney(c.balance || 0);
        document.getElementById('customer_reservation_hint').textContent = 'سطح رزرو: ' + reservationTierLabel(c) + ' | مدت رزرو پس از ثبت نهایی: ' + reservationDurationShort(c);
        document.getElementById('customerSummaryBox').classList.add('is-selected');
        updateSubmitState();
        scheduleLocalDraftSave();
    }

    function clearCustomer() {
        document.getElementById('customer_id').value = '';
        document.getElementById('customer_name').value = '';
        document.getElementById('customer_mobile').value = '';
        document.getElementById('selectedCustomerTitle').textContent = 'هنوز مشتری انتخاب نشده است';
        document.getElementById('customer_balance_hint').textContent = '';
        document.getElementById('customer_reservation_hint').textContent = 'سطح رزرو پس از انتخاب مشتری نمایش داده می‌شود.';
        document.getElementById('customerSummaryBox').classList.remove('is-selected');
        setReservationMode(false);
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
        document.getElementById('onlyInStockToggle').checked = false;
        modalOnlyInStock = false;
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
            const availability = variantAvailability(v);
            if (modalOnlyInStock && availability.maxSelectable <= 0 && Number(modalQuantities.get(variantId(v)) || 0) <= 0) return false;
            if (!q) return true;
            const haystack = [variantModel(v), variantDesign(v), variantName(v), v?.sku, v?.code, v?.variant_code, v?.barcode].join(' ').toLowerCase();
            return haystack.includes(q);
        });
    }

    function modalMaxQty(v) {
        const id = variantId(v);
        const currentQty = Number(modalQuantities.get(id) || 0);
        return Math.max(variantStock(v), currentQty);
    }

    function renderPickerRows() {
        const wrap = document.getElementById('groupPickerRows');
        const rows = filteredModalItems();
        const q = normalize(document.getElementById('pickerSearchInput')?.value || '');
        if (!rows.length) {
            wrap.innerHTML = `<div class="empty-state">${q ? 'تنوعی با این جستجو پیدا نشد.' : 'موردی برای نمایش وجود ندارد.'}</div>`;
            return;
        }
        wrap.innerHTML = rows.map(v => {
            const id = variantId(v);
            const availability = variantAvailability(v);
            const stock = availability.maxSelectable;
            const freeStock = availability.freeStock;
            const currentTokenReserved = availability.currentTokenReserved;
            const reservedByOthers = availability.reservedByOthers;
            const max = modalMaxQty(v);
            const qty = Number(modalQuantities.get(id) || 0);
            const price = variantPrice(v, activeProduct);
            const selectedClass = qty > 0 ? 'row-selected' : '';
            const noStockClass = stock <= 0 && qty <= 0 ? 'row-empty-stock' : '';
            const disabled = stock <= 0 && qty <= 0 ? 'disabled' : '';
            const minusDisabled = qty <= 0 || disabled ? 'disabled' : '';
            const plusDisabled = qty >= max || disabled ? 'disabled' : '';
            const title = buildVariantTitle(v);
            return `
        <div class="variant-row ${selectedClass} ${noStockClass}" data-row-variant="${id}">
            <div class="variant-row__info">
                <div class="variant-row__title" title="${esc(title)}">${esc(title)}</div>
                <div class="variant-row__meta">
                    <span class="variant-pill variant-pill--stock">آزاد: ${freeStock > 0 ? formatNum(freeStock) : 'ناموجود'}</span>
                    ${reservedByOthers > 0 ? `<span class="variant-pill">رزرو: ${formatNum(reservedByOthers)}</span>` : ''}
                    <span class="variant-pill ${stock === freeStock ? 'variant-pill--muted' : ''}" title="سقف قابل انتخاب">سقف: ${formatNum(stock)}</span>
                    <span class="variant-pill">قیمت: ${formatMoney(price)}</span>
                    ${v?.is_current_preinvoice_item ? `<span class="variant-pill variant-pill--selected">در پیش‌فاکتور موجود است${stock <= 0 ? ' / موجودی فعلی ناکافی است' : ''}</span>` : ''}
                    ${qty > 0 ? `<span class="variant-pill variant-pill--selected" data-selected-pill="${id}">انتخاب شما: ${formatNum(qty)}</span>` : ''}
                    ${currentTokenReserved > 0 && qty <= 0 ? `<span class="variant-pill variant-pill--selected">رزرو شما: ${formatNum(currentTokenReserved)}</span>` : ''}
                </div>
            </div>
            <div class="variant-row__qty qty-control">
                <button type="button" class="qty-btn picker-minus" data-id="${id}" ${minusDisabled}>−</button>
                <input type="tel" class="qty-input picker-qty" data-id="${id}" data-price="${price}" min="0" max="${max}" value="${qty}" inputmode="numeric" pattern="[0-9]*" autocomplete="off" ${disabled}>
                <button type="button" class="qty-btn picker-plus" data-id="${id}" data-step="1" ${plusDisabled}>+</button>
            </div>
        </div>`;
        }).join('');
    }

    function setModalQty(id, value, shouldRender = false) {
        id = Number(id);
        const item = activeModalItems.find(v => variantId(v) === id);
        if (!item) return;
        const max = modalMaxQty(item);
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
        if (row) {
            row.classList.toggle('row-selected', qty > 0);
            const qtyInput = row.querySelector('.picker-qty');
            if (qtyInput) qtyInput.value = qty;
            row.querySelector('.picker-minus').disabled = qty <= 0;
            row.querySelector('.picker-plus').disabled = qty >= max;
            const meta = row.querySelector('.variant-row__meta');
            const oldPill = row.querySelector(`[data-selected-pill="${id}"]`);
            if (oldPill) oldPill.remove();
            if (qty > 0 && meta) meta.insertAdjacentHTML('beforeend', `<span class="variant-pill variant-pill--selected" data-selected-pill="${id}">انتخاب شما: ${formatNum(qty)}</span>`);
        }
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
        const saveBtn = document.getElementById('saveGroupSelectionBtn');
        if (saveBtn) {
            saveBtn.disabled = selectedRows === 0;
            saveBtn.textContent = selectedRows > 0 ? `افزودن ${formatNum(selectedRows)} قلم به سبد` : 'افزودن به سبد';
        }
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
        activeModalItems.forEach(v => {
            const id = variantId(v);
            const qty = Number(modalQuantities.get(id) || 0);
            if (qty > 0) items.push({
                variant_id: id,
                quantity: qty,
                price: variantPrice(v, activeProduct),
                model: variantModel(v),
                design: variantDesign(v),
                variant: variantName(v),
                label: buildVariantTitle(v)
            });
        });
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
            <div class="detail-pill" data-variant-pill="${Number(it.variant_id)}">
                <div class="fw-bold">${esc(it.label || 'تنوع پیش‌فرض')}</div>
                <div class="text-muted mt-1">تعداد: ${formatNum(it.quantity)} | مبلغ: ${formatMoney(Number(it.quantity) * Number(it.price))}</div>
                <div class="small text-danger mt-1 d-none" data-stock-row-error></div>
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
        const total = Math.max(0, subtotal - totalDiscount);
        document.getElementById('discount').value = String(totalDiscount);
        document.getElementById('totalDiscountView').value = formatMoney(totalDiscount);
        document.getElementById('total_price').value = formatMoney(total);
        const preview = document.getElementById('orderDiscountPreview');
        if (preview) preview.textContent = 'تخفیف کلی: ' + formatMoney(orderDiscount);
        document.getElementById('discount_breakdown').value = JSON.stringify(buildDiscountBreakdown(subtotal, groupDiscounts, orderDiscount, totalDiscount));
        updateSubmitState();
        scheduleLocalDraftSave();
    }

    async function hydrateInitialGroups() {
        if (!INIT_ROWS || !INIT_ROWS.length) {
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
                discount_type: (OLD_DISCOUNT_BREAKDOWN.groups || []).find(g => Number(g.product_id) === Number(productId))?.discount_type || 'amount',
                discount_value: Number((OLD_DISCOUNT_BREAKDOWN.groups || []).find(g => Number(g.product_id) === Number(productId))?.discount_value || 0),
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
                        label: v ? buildVariantTitle(v) : (row.variant_name || 'تنوع پیش‌فرض'),
                        line_discount_amount: Number(row.line_discount_amount || 0)
                    };
                }).filter(item => item.variant_id && item.quantity > 0)
            };
        }
        renderGroupSummary();
        updateTotal();
    }

    function updateSubmitState() {
        const btn = document.getElementById('submitOrderBtn');
        const draftBtn = document.getElementById('saveDraftBtn');
        const hint = document.getElementById('submitHint');
        const customerName = normalize(document.getElementById('customer_name')?.value);
        const customerMobile = normalize(document.getElementById('customer_mobile')?.value);
        const hasProducts = document.querySelectorAll('#groupProductsInputs input[name$="[quantity]"]').length > 0;
        const ok = !!customerName && !!customerMobile && hasProducts;
        if (btn) btn.disabled = !ok;
        if (draftBtn) draftBtn.disabled = !ok;
        if (ok) {
            hint.textContent = 'آماده ذخیره یا ثبت نهایی.';
            hint.style.color = '#178c63';
        } else {
            hint.textContent = 'انتخاب مشتری و حداقل یک کالا لازم است.';
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
                const stock = variantStock(v);
                const requested = Number(item.quantity || 0);
                if (requested > stock) errors.push(`${group.product.title} / ${buildVariantTitle(v)}: موجودی ${stock} عدد، درخواست ${requested} عدد.`);
            }
        }
        if (errors.length) {
            alert('موجودی تغییر کرده:\n\n' + errors.slice(0, 8).join('\n'));
            return false;
        }
        return true;
    }


    function collectProductsForSubmit() {
        const rows = [];
        let rowNumber = 0;
        for (const group of Object.values(groupedSelections || {})) {
            const productId = Number(group?.product?.id || 0);
            const productTitle = group?.product?.title || ('محصول #' + productId);
            for (const item of (group?.items || [])) {
                rowNumber++;
                const row = {
                    item_id: Number(item.item_id || 0) || null,
                    id: productId,
                    product_id: productId,
                    variety_id: Number(item.variant_id || 0),
                    variant_id: Number(item.variant_id || 0),
                    quantity: Number(item.quantity || 0),
                    price: Number(item.price || 0),
                    line_discount_amount: Number(item.line_discount_amount || 0)
                };
                if (!(row.id > 0 && row.variety_id > 0 && row.quantity > 0 && row.price >= 0)) {
                    throw new Error(`اطلاعات یکی از اقلام پیش‌فاکتور ناقص است.
هیچ تغییری ثبت نشد.
ردیف ${formatNum(rowNumber)}: ${productTitle}`);
                }
                rows.push(row);
            }
        }
        return rows;
    }

    function prepareProductsPayloadForSubmit() {
        const payloadEl = document.getElementById('products_payload');
        const countEl = document.getElementById('products_payload_count');
        const versionEl = document.getElementById('products_payload_version');
        const completeEl = document.getElementById('products_payload_complete');
        const qtyEl = document.getElementById('products_payload_total_quantity');
        const grossEl = document.getElementById('products_payload_gross_total');
        if (completeEl) completeEl.value = '0';
        const rows = collectProductsForSubmit();
        if (!rows.length) {
            throw new Error('حداقل یک کالا باید اضافه شود.');
        }
        const payload = JSON.stringify(rows);
        const totalQuantity = rows.reduce((sum, row) => sum + Number(row.quantity || 0), 0);
        const grossTotal = rows.reduce((sum, row) => sum + (Number(row.quantity || 0) * Number(row.price || 0)), 0);
        payloadEl.value = payload;
        countEl.value = String(rows.length);
        versionEl.value = '1';
        qtyEl.value = String(totalQuantity);
        grossEl.value = String(grossTotal);
        completeEl.value = '1';
        // disable legacy product inputs after the JSON payload has been built successfully
        document.querySelectorAll('#groupProductsInputs input[name^="products["]').forEach(input => {
            input.disabled = true;
        });
        return rows;
    }

    function normalizeBeforeSubmit() {
        const totalEl = document.getElementById('total_price');
        if (totalEl) totalEl.value = String(toInt(totalEl.value));
        const discEl = document.getElementById('discount');
        if (discEl) discEl.value = String(toInt(discEl.value));
        document.querySelectorAll('#groupProductsInputs input').forEach(input => {
            input.value = String(toInt(input.value));
        });
    }

    async function submitGuard(e) {
        if (isSubmittingProgrammatically) return true;
        e.preventDefault();
        const submitter = e.submitter || document.activeElement;
        const intent = submitter?.value === 'draft' ? 'draft' : 'submit';
        const customerName = normalize(document.getElementById('customer_name').value);
        const customerMobile = normalize(document.getElementById('customer_mobile').value);
        let rowsForSubmit = [];
        try {
            rowsForSubmit = collectProductsForSubmit();
        } catch (err) {
            alert(err.message || 'اطلاعات یکی از اقلام پیش‌فاکتور ناقص است.\nهیچ تغییری ثبت نشد.');
            return false;
        }
        if (!customerName || !customerMobile) {
            alert('لطفا مشتری را انتخاب کنید.');
            return false;
        }
        if (!rowsForSubmit.length) {
            alert('حداقل یک کالا باید اضافه شود.');
            return false;
        }
        const btn = intent === 'draft' ? document.getElementById('saveDraftBtn') : document.getElementById('submitOrderBtn');
        const oldText = btn.textContent;
        btn.disabled = true;
        if (intent === 'draft') {
            normalizeBeforeSubmit();
            try {
                prepareProductsPayloadForSubmit();
            } catch (err) {
                alert(err.message || 'اطلاعات اقلام پیش‌فاکتور ناقص است.\nهیچ تغییری ثبت نشد.');
                btn.disabled = false;
                btn.textContent = oldText;
                return false;
            }
            btn.textContent = 'در حال ذخیره پیش‌نویس...';
            isSubmittingProgrammatically = true;
            appendProgrammaticIntent(intent);
            document.getElementById('orderForm').submit();
            return true;
        }

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
        try {
            prepareProductsPayloadForSubmit();
        } catch (err) {
            alert(err.message || 'اطلاعات اقلام پیش‌فاکتور ناقص است.\nهیچ تغییری ثبت نشد.');
            btn.disabled = false;
            btn.textContent = oldText;
            return false;
        }
        btn.textContent = 'در حال ثبت...';
        isSubmittingProgrammatically = true;
        appendProgrammaticIntent(intent);
        document.getElementById('orderForm').submit();
        return true;
    }

    function appendProgrammaticIntent(intent) {
        const form = document.getElementById('orderForm');
        form.querySelector('input[data-programmatic-intent]')?.remove();
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'intent';
        input.value = intent;
        input.dataset.programmaticIntent = 'true';
        form.appendChild(input);
    }

    document.addEventListener('DOMContentLoaded', async function() {
        if (SUBMIT_SUCCEEDED && !IS_EDIT) {
            localStorage.removeItem(LOCAL_DRAFT_KEY);
            localStorage.removeItem(RESERVATION_TOKEN_KEY);
            hideLocalDraftBanner();
        }
        if (!IS_EDIT) {
            ensureReservationToken();
            bindLocalDraftEvents();
            await loadLatestDbAutosaveBanner();
        }

        initCustomerSearch();
        initMotherAjaxSearch();
        await loadOldCustomer();
        renderRecentProducts();

        document.getElementById('clearCustomerBtn')?.addEventListener('click', clearCustomer);
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
        document.getElementById('clearPickerSearchBtn')?.addEventListener('click', function() {
            const input = document.getElementById('pickerSearchInput');
            input.value = '';
            renderPickerRows();
            input.focus();
        });
        document.getElementById('onlyInStockToggle')?.addEventListener('change', function() {
            modalOnlyInStock = this.checked;
            renderPickerRows();
        });

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
        document.getElementById('groupPickerRows')?.addEventListener('keydown', function(e) {
            if (!e.target.classList.contains('picker-qty')) return;
            if (!['Enter', 'ArrowDown', 'ArrowUp'].includes(e.key)) return;
            e.preventDefault();
            const inputs = Array.from(document.querySelectorAll('#groupPickerRows .picker-qty:not(:disabled)'));
            const index = inputs.indexOf(e.target);
            const next = e.key === 'ArrowUp' ? inputs[index - 1] : inputs[index + 1];
            if (next) {
                next.focus();
                next.select();
            }
        });
        document.getElementById('groupPickerRows')?.addEventListener('focusout', function(e) {
            if (e.target.classList.contains('picker-qty')) renderPickerRows();
        });
        document.getElementById('orderForm')?.addEventListener('submit', submitGuard, {
            capture: true
        });

        await hydrateInitialGroups();
        applyServerItemErrors();
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
