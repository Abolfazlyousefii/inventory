<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PersonnelAccountDocument;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnRevision;
use App\Models\Supplier;
use App\Models\SupplierAccountTransaction;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PersonAccountStatementService
{
    public function statement(string $personKey, int $perPage = 25): array
    {
        $person = $this->resolve($personKey);
        $customerId = $person['customer']?->id;
        $supplierId = $person['supplier']?->id;
        $entries = $this->entriesQuery($customerId, $supplierId);

        $totals = (clone $entries)
            ->where('is_effective', 1)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) AS debit_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) AS credit_total")
            ->first();

        $openingBalance = (int) ($person['customer']?->opening_balance ?? 0);
        $totalDebit = (int) ($totals->debit_total ?? 0);
        $totalCredit = (int) ($totals->credit_total ?? 0);
        $netBalance = $openingBalance + $totalDebit - $totalCredit;

        $ledgers = (clone $entries)
            ->orderByDesc('statement_date')
            ->orderByRaw("COALESCE(created_at, '1970-01-01 00:00:00') DESC")
            ->orderByDesc('source_order')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        foreach ($ledgers as $ledger) {
            $ledger->ledger_key = $ledger->ledger_source.':'.$ledger->id;
        }

        return [
            'person' => $person,
            'openingBalance' => $openingBalance,
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
            'netBalance' => $netBalance,
            'ledgers' => $ledgers,
            'ledgerBalances' => $this->pageBalances($entries, $ledgers, $netBalance),
        ];
    }

    /**
     * A person can have either role or both. Existing records are joined by the
     * same normalized phone convention used by the person list.
     *
     * @return array{customer: ?Customer, supplier: ?Supplier, name: string, mobile: string, is_customer: bool, is_supplier: bool}
     */
    public function resolve(string $personKey): array
    {
        // Directory links open the supplier's own book, never a phone-based netting.
        if (preg_match('/^supplier-only-([0-9]+)$/D', $personKey, $m)) {
            $supplier = Supplier::findOrFail((int) $m[1]);
            return ['customer' => null, 'supplier' => $supplier, 'name' => $supplier->name,
                'mobile' => $supplier->phone, 'is_customer' => false, 'is_supplier' => true,
                'link_warning' => null];
        }
        $customer = null;
        $supplier = null;
        $explicit = false;
        if (preg_match('/^customer-([0-9]+)$/D', $personKey, $m)) {
            $customer = Customer::find((int) $m[1]);
            $explicit = true;
        } elseif (preg_match('/^supplier-([0-9]+)$/D', $personKey, $m)) {
            $supplier = Supplier::find((int) $m[1]);
            $explicit = true;
        }
        $phone = PhoneNormalizer::normalize((string) ($customer?->mobile ?? $supplier?->phone ?? ($explicit ? '' : $personKey)));
        $customers = $phone === '' ? collect() : Customer::query()->orderBy('id')->cursor()
            ->filter(fn ($c) => PhoneNormalizer::normalize((string) $c->mobile) === $phone)->take(2)->collect();
        $suppliers = $phone === '' ? collect() : Supplier::query()->orderBy('id')->cursor()
            ->filter(fn ($c) => PhoneNormalizer::normalize((string) $c->phone) === $phone)->take(2)->collect();
        $ambiguous = $customers->count() > 1 || $suppliers->count() > 1;
        if (! $ambiguous && (! $explicit || $customer || $supplier)) {
            $customer ??= $customers->first();
            $supplier ??= $suppliers->first();
        }
        if (! $customer && ! $supplier) {
            throw new NotFoundHttpException($ambiguous ? 'شماره تماس مشترک است؛ گردش هر شخص را با شناسه مستقل باز کنید.' : 'شخص موردنظر پیدا نشد.');
        }
        return [
            'customer' => $customer, 'supplier' => $supplier,
            'name' => $customer?->display_name ?: ($supplier?->name ?? 'شخص'),
            'mobile' => $customer?->mobile ?: ($supplier?->phone ?? ''),
            'is_customer' => $customer !== null, 'is_supplier' => $supplier !== null,
            'link_warning' => $ambiguous ? 'شماره تماس در چند پرونده تکرار شده است؛ دفترهای مالی برای جلوگیری از اختلاط، جدا نمایش داده می‌شوند.' : null,
        ];
    }

    private function entriesQuery(?int $customerId, ?int $supplierId): Builder
    {
        $queries = [];

        if ($customerId !== null) {
            $customerEntries = DB::table('customer_ledgers as cl')
                ->where('cl.customer_id', $customerId)
                ->selectRaw("'customer' AS ledger_source, cl.id, cl.type, cl.amount, cl.reference_type, cl.reference_id, cl.note, cl.created_at, cl.updated_at, 0 AS source_order")
                ->selectRaw("DATE(COALESCE(
                    (SELECT ip.paid_at FROM invoice_payments ip WHERE ip.id = cl.reference_id AND cl.reference_type = ?),
                    (SELECT pad.document_date FROM personnel_account_documents pad WHERE pad.id = cl.reference_id AND cl.reference_type = ?),
                    (SELECT fd.document_date FROM financial_account_documents fd WHERE fd.id = cl.reference_id AND cl.reference_type = ?),
                    (SELECT p.purchased_at FROM purchases p WHERE p.id = cl.reference_id AND cl.reference_type = ?),
                    cl.created_at,
                    '1970-01-01'
                )) AS statement_date", [InvoicePayment::class, PersonnelAccountDocument::class, \App\Models\FinancialAccountDocument::class, Purchase::class]);

            $cancelledStatuses = Invoice::cancelledStatuses();
            if ($cancelledStatuses !== []) {
                $placeholders = implode(',', array_fill(0, count($cancelledStatuses), '?'));
                $customerEntries->selectRaw(
                    "CASE WHEN cl.reference_type = ? AND EXISTS (
                        SELECT 1 FROM invoices i
                        WHERE i.id = cl.reference_id AND i.status IN ({$placeholders})
                    ) THEN 0 ELSE 1 END AS is_effective",
                    array_merge([Invoice::class], $cancelledStatuses)
                );
            } else {
                $customerEntries->selectRaw('1 AS is_effective');
            }

            $queries[] = $customerEntries;
        }

        if ($supplierId !== null) {
            $supplierEntries = DB::table('supplier_ledgers as sl')
                ->where('sl.supplier_id', $supplierId)
                ->selectRaw("'supplier' AS ledger_source, sl.id, sl.type, sl.amount, sl.reference_type, sl.reference_id, sl.note, sl.created_at, sl.updated_at, 1 AS source_order")
                ->selectRaw("DATE(COALESCE(
                    (SELECT sat.transaction_date FROM supplier_account_transactions sat WHERE sat.id = sl.reference_id AND sl.reference_type = ?),
                    (SELECT fd.document_date FROM financial_account_documents fd WHERE fd.id = sl.reference_id AND sl.reference_type = ?),
                    (SELECT p.purchased_at FROM purchases p WHERE p.id = sl.reference_id AND sl.reference_type = ?),
                    (SELECT pr.returned_at FROM purchase_returns pr WHERE pr.id = sl.reference_id AND sl.reference_type = ?),
                    sl.created_at,
                    '1970-01-01'
                )) AS statement_date", [SupplierAccountTransaction::class, \App\Models\FinancialAccountDocument::class, Purchase::class, PurchaseReturn::class])
                ->selectRaw("CASE WHEN sl.reference_type = ? AND NOT EXISTS (
                    SELECT 1 FROM purchase_returns pr
                    WHERE pr.id = sl.reference_id AND pr.deleted_at IS NULL
                ) AND NOT EXISTS (
                    SELECT 1 FROM purchase_return_revisions reversal
                    JOIN supplier_ledgers posted ON posted.reference_id = reversal.id AND posted.reference_type = ?
                    WHERE reversal.purchase_return_id = sl.reference_id AND reversal.action = 'deleted'
                ) THEN 0 ELSE 1 END AS is_effective", [PurchaseReturn::class, PurchaseReturnRevision::class]);

            if ($customerId !== null) {
                // SupplierLedgerService mirrors some customer-origin purchases into
                // CustomerLedger. Keep the customer row as the canonical copy.
                $supplierEntries->where(function ($query) use ($customerId) {
                    $query->where('sl.reference_type', '!=', Purchase::class)
                        ->orWhereNull('sl.reference_type')
                        ->orWhereNotExists(function ($duplicate) use ($customerId) {
                            $duplicate->selectRaw('1')
                                ->from('customer_ledgers as copied')
                                ->where('copied.customer_id', $customerId)
                                ->where('copied.reference_type', Purchase::class)
                                ->whereColumn('copied.reference_id', 'sl.reference_id')
                                ->whereColumn('copied.type', 'sl.type');
                        });
                });
            }

            $queries[] = $supplierEntries;

            // Older returns predate supplier-ledger synchronization. Include
            // their stored document totals as a read-only fallback, but never
            // duplicate a return that already has its supplier debit row.
            $legacyReturns = DB::table('purchase_returns as pr')
                ->where('pr.supplier_id', $supplierId)
                ->whereNotExists(function ($existing) use ($supplierId) {
                    $existing->selectRaw('1')
                        ->from('supplier_ledgers as slr')
                        ->where('slr.supplier_id', $supplierId)
                        ->where('slr.reference_type', PurchaseReturn::class)
                        ->whereColumn('slr.reference_id', 'pr.id')
                        ->where('slr.type', 'debit');
                })
                ->selectRaw("'supplier' AS ledger_source, -pr.id AS id, 'debit' AS type, pr.total_amount AS amount, ? AS reference_type, pr.id AS reference_id, pr.note AS note, pr.created_at AS created_at, pr.updated_at AS updated_at, 2 AS source_order", [PurchaseReturn::class])
                ->selectRaw("DATE(COALESCE(pr.returned_at, pr.created_at, '1970-01-01')) AS statement_date")
                ->selectRaw('CASE WHEN pr.deleted_at IS NULL THEN 1 ELSE 0 END AS is_effective');

            $queries[] = $legacyReturns;
        }

        if ($queries === []) {
            throw new NotFoundHttpException('برای این شخص دفتر حسابی پیدا نشد.');
        }

        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        return DB::query()->fromSub($union, 'person_entries');
    }

    private function pageBalances(Builder $entries, $ledgers, int $netBalance): array
    {
        $rows = $ledgers->getCollection();
        if ($rows->isEmpty()) {
            return [];
        }

        $first = $rows->first();
        $firstCreatedAt = $first->created_at ?: '1970-01-01 00:00:00';

        $newerMovement = (clone $entries)
            ->where('is_effective', 1)
            ->where(function ($query) use ($first, $firstCreatedAt) {
                $query->where('statement_date', '>', $first->statement_date)
                    ->orWhere(function ($sameDate) use ($first, $firstCreatedAt) {
                        $sameDate->where('statement_date', $first->statement_date)
                            ->where(function ($registration) use ($first, $firstCreatedAt) {
                                $registration->whereRaw("COALESCE(created_at, '1970-01-01 00:00:00') > ?", [$firstCreatedAt])
                                    ->orWhere(function ($sameRegistration) use ($first, $firstCreatedAt) {
                                        $sameRegistration->whereRaw("COALESCE(created_at, '1970-01-01 00:00:00') = ?", [$firstCreatedAt])
                                            ->where(function ($source) use ($first) {
                                                $source->where('source_order', '>', (int) $first->source_order)
                                                    ->orWhere(function ($sameSource) use ($first) {
                                                        $sameSource->where('source_order', (int) $first->source_order)
                                                            ->where('id', '>', (int) $first->id);
                                                    });
                                            });
                                    });
                            });
                    });
            })
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debit' THEN amount WHEN type = 'credit' THEN -amount ELSE 0 END), 0) AS movement")
            ->value('movement');

        $balance = $netBalance - (int) $newerMovement;
        $balances = [];

        foreach ($rows as $row) {
            $key = $row->ledger_source.':'.$row->id;
            $balances[$key] = $balance;

            if ((int) $row->is_effective === 1) {
                $balance -= match ($row->type) {
                    'debit' => (int) $row->amount,
                    'credit' => -(int) $row->amount,
                    default => 0,
                };
            }
        }

        return $balances;
    }
}
