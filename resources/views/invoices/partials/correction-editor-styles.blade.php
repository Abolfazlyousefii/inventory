{{-- Copy of the preinvoice create page styles (resources/views/preinvoice/create.blade.php) so the seller
     correction page shares its look without touching the create page. Keep in sync when the create design changes. --}}
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
        padding: 10px 16px;
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

        .variant-modal__footer-extra {
            flex-direction: column;
            gap: 6px;
        }

        .variant-modal__footer-extra .modal-discount-box {
            min-width: 0;
            width: 100%;
        }

        .variant-modal__footer-extra .modal-summary-bar {
            width: 100%;
        }

        .modal-discount-box {
            margin-top: 0;
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
        padding: 6px 12px;
        background: #fffdf9;
        border-top: 1px solid rgba(12, 83, 103, .08);
        display: flex;
        align-items: flex-start;
        gap: 10px;
    }

    .variant-modal__footer-extra .modal-discount-box {
        flex: 0 0 auto;
        margin-top: 0;
        min-width: 190px;
    }

    .variant-modal__footer-extra .modal-summary-bar {
        flex: 1 1 auto;
        margin-top: 0 !important;
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

    @media (max-width: 991.98px) {
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
