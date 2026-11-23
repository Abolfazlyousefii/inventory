@php
    $ledgers = $rows;
    $effectiveLedgerIds = $meta['effectiveIds'];
    $ledgerBalances = $meta['rowBalances'];
    $netBalance = $customer->balance;
    $rial = fn ($value) => \App\Support\Currency::formatRial(abs((int) $value));
    $balanceText = fn ($value) => $value > 0 ? 'بدهکار' : ($value < 0 ? 'بستانکار' : 'تسویه');
    $canManageDocuments = $canManagePayments = $canAdjustBalance = false;
    $statementDocumentUrl = function ($ledger, $invoice, $salesReturn) use ($customer) {
        if ($invoice) return route('commercial.customers.document.full', [$customer, 'invoice', $invoice->id, 'view']);
        if ($salesReturn) return route('commercial.customers.document.full', [$customer, 'return', $salesReturn->id, 'view']);
        return null;
    };
@endphp
<div class="as-document ce-finance-ledger">
    @include('account-statements.partials.ledger-table', $meta['references'])
</div>
