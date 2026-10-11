# Payment receipts report

Entry: Financial reports → Cash and cheque receipts (`finance.reports.receipts`). The old registered-cheques URL redirects here. All report, detail and export routes belong to `page.finance.accounts`; invoice links require the existing invoice-view permissions as well.

Read-only source: one `invoice_payments` row per receipt. Ledger entries and imported balance corrections are not additional receipts. Cash and online payments share the liquid-payment chart group; details retain the original method. Cheque receipts use the latest linked cheque's received date, falling back to the payment date; creation date is not used. Date boundaries are inclusive Jalali days, with the default current month through today.

Duplicate cheque metadata is reduced to its latest ID and flagged; payment amounts remain authoritative and are never multiplied. Payments recorded as cash/online but carrying cheque metadata are flagged without changing the recorded method. Due-date reporting includes linked cheque documents, even where their recorded payment method conflicts.

Due buckets are disjoint: before today, today, tomorrow through seven days ahead inclusive, later, and missing due date. They use all matching customer/bank/method records independently of the receipt date range. They do not represent outstanding cheques: the existing registered/unregistered statuses describe Sayad registration, not collection. This limitation is stated on screen.

Details are paginated by 25. Exports preserve the active receipt/day/method or due-bucket selection; XLSX uses explicit string cells to preserve identifiers and prevent formula injection. Print/PDF uses the browser print view with Vazirmatn. Neither action changes financial or inventory data.

Validation: 24 focused tests / 218 assertions (report, top products, unified payment entry); browser interaction tests at 320, 390, 768 and 1366px; production read-only source reconciliation of all 70 receipts; authenticated GET smoke checks including details, print, XLSX, legacy redirect and neighboring financial pages. Existing two cash/cheque metadata mismatches are displayed, not repaired.

Deployment backup: `storage/app/private/deploy-backups/payment-report-20261010` on the server. Only five scoped changes to existing files and seven new report files were installed, with hash verification; no migrations.
