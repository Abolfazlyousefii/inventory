<?php

namespace App\Services;

use App\Models\{PurchaseReturn, Supplier, SupplierLedger};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only directory: financial identities remain separate until explicitly reconciled. */
final class PersonAccountDirectory
{
    public function paginate(Builder $customers, Request $request)
    {
        $fullName = DB::getDriverName() === 'sqlite'
            ? "TRIM(COALESCE(first_name, '') || ' ' || COALESCE(last_name, ''))"
            : "TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))";
        $customerRows = DB::query()->fromSub($customers->toBase(), 'customer_accounts')
            ->selectRaw("id, COALESCE(NULLIF(name, ''), NULLIF({$fullName}, ''), mobile) AS display_name, mobile, 'customer' AS account_role")
            ->selectRaw('COALESCE(opening_balance, 0) + COALESCE(debit_sum, 0) - COALESCE(credit_sum, 0) AS balance');

        $ledger = SupplierLedger::query()->effectiveForBalance()
            ->whereColumn('supplier_ledgers.supplier_id', 'suppliers.id')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE -amount END), 0)");
        $legacy = DB::table('purchase_returns as pr')->whereNull('pr.deleted_at')
            ->whereColumn('pr.supplier_id', 'suppliers.id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('supplier_ledgers as posted')
                ->whereColumn('posted.supplier_id', 'pr.supplier_id')->whereColumn('posted.reference_id', 'pr.id')
                ->where('posted.reference_type', PurchaseReturn::class)->where('posted.type', 'debit'))
            ->selectRaw('COALESCE(SUM(pr.total_amount), 0)');
        $supplierSource = Supplier::query()->select('suppliers.*')
            ->where(fn ($query) => $query->whereNull('is_inventory_adjustment_source')->orWhere('is_inventory_adjustment_source', false))
            ->selectSub($ledger, 'ledger_balance')->selectSub($legacy, 'legacy_returns');
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $latin = strtr($q, array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
            $name = strtr($latin, ['ي' => 'ی', 'ك' => 'ک']);
            $phone = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";
            $supplierSource->where(function ($query) use ($q, $name, $latin, $phone) {
                $query->where('name', 'like', '%'.$q.'%')->orWhereRaw("REPLACE(REPLACE(name, 'ي', 'ی'), 'ك', 'ک') LIKE ?", ['%'.$name.'%'])
                    ->orWhere('phone', 'like', '%'.$q.'%')->orWhereRaw($phone.' LIKE ?', ['%'.$latin.'%'])
                    ->orWhere('city', 'like', '%'.$q.'%')->orWhere('id', $latin);
            });
        }
        $supplierRows = DB::query()->fromSub($supplierSource->toBase(), 'supplier_accounts')
            ->selectRaw("id, name AS display_name, phone AS mobile, 'supplier' AS account_role, ledger_balance + legacy_returns AS balance");
        $role = $request->query('role');
        $union = $role === 'supplier' ? $supplierRows : ($role === 'customer' ? $customerRows : $customerRows->unionAll($supplierRows));
        $balances = DB::query()->fromSub($union, 'accounts')->select('accounts.*')
            ->selectRaw('CASE WHEN balance > 0 THEN balance ELSE 0 END AS debt')
            ->selectRaw('CASE WHEN balance < 0 THEN -balance ELSE 0 END AS credit');
        $query = DB::query()->fromSub($balances, 'directory');
        $sort = ['name' => 'display_name', 'mobile' => 'mobile', 'debt' => 'debt', 'credit' => 'credit', 'status' => 'balance'][$request->query('sort')] ?? null;
        if ($sort) $query->orderBy($sort, $request->query('direction') === 'asc' ? 'asc' : 'desc');
        return $query->orderByDesc('id')->orderBy('account_role')->paginate(20)->withQueryString();
    }
}
