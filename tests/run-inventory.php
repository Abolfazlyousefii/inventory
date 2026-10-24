<?php

// A repeatable gate for current inventory workflows. The full suite remains
// available via tests/run.php; this does not suppress or skip its legacy tests.
$files = [];
$pattern = '/DocumentCodeGenerator|AccountStatementChronology|AccountStatementPaymentDate|Inventory|Stock|Purchase|SalesReturn|PreinvoiceScopedReservation|ReservationLifecycleContract|PreinvoiceReservationPayload|PreinvoiceMultiShortfall|WarehouseInbound|WarehouseCollection|ReservationLifecycleHardening|ReservationProjection|WarehouseReservationFullLifecycle|TestEnvironmentSafety|PreinvoiceSubmissionSchema|ProductSalesLedger|ProductSummaryProjection|Discount|PreinvoiceAutosave|PreinvoiceLargePayload|UniformProductPricing|FinancePreinvoice(?:Update|EditPersistence|SaveAndFinalize)|PreinvoiceSubmitted/';
foreach (glob(__DIR__.'/Feature/*Test.php') as $file) {
    if (preg_match($pattern, basename($file))) $files[] = $file;
}
if ($files === []) throw new RuntimeException('Inventory test selection is empty');
$_SERVER['argv'] = array_merge([__FILE__, '--configuration', dirname(__DIR__).'/phpunit.xml'], $files, array_slice($_SERVER['argv'], 1));
require __DIR__.'/run.php';
